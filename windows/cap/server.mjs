import http from 'node:http';
import { readFile } from 'node:fs/promises';
import { createHash, createHmac, randomBytes, timingSafeEqual } from 'node:crypto';
import { pathToFileURL } from 'node:url';
import { createClient } from 'redis';
import { generateChallenge, validateChallenge } from 'capjs-core';

export function safeEqual(a, b) {
  if (typeof a !== 'string' || typeof b !== 'string') return false;
  const x = Buffer.from(a), y = Buffer.from(b);
  return x.length === y.length && timingSafeEqual(x, y);
}
export const CONSUME = 'local v=redis.call("GET",KEYS[1]); if v then redis.call("DEL",KEYS[1]); end; return v';
const RATE = 'local n=redis.call("INCR",KEYS[1]); if n==1 then redis.call("EXPIRE",KEYS[1],ARGV[1]); end; return n';
const sha = s => createHash('sha256').update(s).digest('hex');
const TOKEN_TTL_MS = 5 * 60 * 1000;
const CHALLENGE_TTL_MS = 3 * 60 * 1000;

export async function createAdapter(config, injectedRedis) {
  if (!/^[a-zA-Z0-9_-]{8,64}$/.test(config.siteKey || '') ||
      ['proxySecret','verifySecret','signingSecret'].some(k => typeof config[k] !== 'string' || config[k].length < 32)) {
    throw new Error('Invalid private Cap configuration');
  }
  if (!/^[a-zA-Z0-9:_-]{8,100}:$/.test(config.redisPrefix || '')) throw new Error('Invalid Redis namespace');
  const redis = injectedRedis || createClient({ url: config.redisUrl,
    socket: { connectTimeout: 3000, reconnectStrategy: retries => Math.min(500 + retries * 200, 5000) },
    disableOfflineQueue: true });
  // Never print Redis URLs, credentials, challenge bodies, or redeem tokens.
  redis.on('error', () => {});
  if (!injectedRedis) await redis.connect();
  const key = k => config.redisPrefix + k;
  let inflight = 0;
  const response = (res, status, value) => {
    res.writeHead(status, {'content-type':'application/json; charset=utf-8', 'cache-control':'no-store',
      'x-content-type-options':'nosniff'});
    res.end(JSON.stringify(value));
  };
  const readBody = async req => {
    let chunks=[], size=0;
    for await (const chunk of req) {
      size+=chunk.length;
      if (size>65536) { const e=new Error('body'); e.status=413; throw e; }
      chunks.push(chunk);
    }
    try { const b=JSON.parse(Buffer.concat(chunks).toString() || '{}');
      if (!b || Array.isArray(b) || typeof b!=='object') throw new Error(); return b;
    } catch { const e=new Error('body'); e.status=400; throw e; }
  };
  const server = http.createServer(async (req, res) => {
    let counted=false;
    req.setTimeout(10000);
    try {
      const url = new URL(req.url, 'http://127.0.0.1');
      if (url.pathname === '/health' && req.method === 'GET') {
        await redis.ping(); return response(res,200,{status:'ok',service:'xboard-cap',version:'1.0.0',redis:'connected'});
      }
      if (!safeEqual(req.headers['x-cap-proxy-secret'], config.proxySecret)) return response(res,403,{success:false});
      if (req.method !== 'POST') return response(res,405,{success:false});
      const match = url.pathname.match(/^\/([a-zA-Z0-9_-]+)\/(challenge|redeem|siteverify)$/);
      if (!match || !safeEqual(match[1],config.siteKey)) return response(res,404,{success:false});
      if (inflight>=8) return response(res,429,{success:false,error:'busy'});
      inflight++; counted=true;
      const action=match[2], body=await readBody(req);
      if (action==='siteverify') {
        if (!safeEqual(body.secret,config.verifySecret)) return response(res,403,{success:false});
        if (typeof body.response!=='string' || body.response.length>256 ||
            !new RegExp('^'+config.siteKey+':[a-f0-9]{32}:[a-f0-9]{64}$').test(body.response)) {
          return response(res,400,{success:false});
        }
        // GET+DEL must be ONE atomic operation; plain GET then DEL permits double spending.
        const stored=await redis.eval(CONSUME,{keys:[key('token:'+sha(body.response))],arguments:[]});
        const valid=stored!==null && Number(stored)>Date.now();
        return response(res,valid?200:400,{success:valid});
      }
      const ip=String(req.headers['x-cap-client-ip'] || 'unknown').slice(0,128);
      const fingerprint=createHmac('sha256',config.signingSecret).update(ip).digest('hex');
      const n=Number(await redis.eval(RATE,{keys:[key('rate:'+action+':'+fingerprint)],arguments:['60']}));
      if(n>(action==='challenge'?20:40)) return response(res,429,{success:false,error:'rate_limited'});
      if(action==='challenge') {
        const result=await generateChallenge(config.signingSecret,{
          scope: config.siteKey, expiresMs: CHALLENGE_TTL_MS,
          challengeCount: config.challengeCount || 20, challengeDifficulty: config.difficulty || 4,
          instrumentation: config.instrumentation !== false,
        });
        return response(res,200,result);
      }
      const result=await validateChallenge(config.signingSecret,body,{
        scope:config.siteKey, tokenTtlMs:TOKEN_TTL_MS,
        consumeNonce:async(sig,ttl)=> (await redis.set(key('nonce:'+sig),'1',
          {NX:true,PX:Math.max(1,Math.ceil(ttl))}))==='OK',
        signToken:async()=> config.siteKey+':'+randomBytes(16).toString('hex')+':'+randomBytes(32).toString('hex'),
      });
      if(!result.success) return response(res,400,{success:false,error:'invalid_challenge'});
      const token=result.token;
      const expires=Date.now()+TOKEN_TTL_MS;
      await redis.set(key('token:'+sha(token)),String(expires),{PX:TOKEN_TTL_MS});
      // Match the official widget wire response; do not return internal risk/nonce metadata.
      return response(res,200,{success:true,token,expires});
    } catch (error) {
      if(!res.headersSent) response(res,error.status || 503,{success:false,error:error.status?'invalid_request':'unavailable'});
      else res.end();
    } finally { if(counted) inflight--; }
  });
  server.requestTimeout=12000;
  server.headersTimeout=15000;
  server.keepAliveTimeout=5000;
  return {server, redis, close:async()=>{
    await new Promise(resolve=>server.close(resolve));
    if(!injectedRedis) await redis.quit();
  }};
}

if(process.argv[1] && import.meta.url===pathToFileURL(process.argv[1]).href) {
  const configPath=process.env.CAP_CONFIG || new URL('./private.json',import.meta.url);
  const config=JSON.parse(await readFile(configPath,'utf8'));
  if(config.host && config.host!=='127.0.0.1') throw new Error('Cap must remain loopback-only');
  const adapter=await createAdapter(config);
  const port=Number(config.port || 3010);
  if(!Number.isInteger(port) || port<1024 || port>65535) throw new Error('Invalid port');
  adapter.server.listen(port,'127.0.0.1',()=>console.log(JSON.stringify({event:'ready',host:'127.0.0.1',port,pid:process.pid})));
  let closing=false;
  const close=async()=>{if(closing)return; closing=true; const force=setTimeout(()=>process.exit(1),8000);force.unref(); await adapter.close();process.exit(0);};
  process.on('SIGINT',close);process.on('SIGTERM',close);
}
