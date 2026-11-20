/* Xboard Cap bridge v1.1.0. Kept outside the third-party theme bundle for upgrade safety. */
(function () {
  'use strict';
  if (window.__xboardCapBridge) return;
  window.__xboardCapBridge = true;
  var script = document.currentScript;
  var assets = new URL('./', script.src).href;
  var apiBaseRaw = window.__XBOARD_API_BASE__ || (script && script.getAttribute('data-api-base')) || '';
  var apiBase = null;
  try { if (apiBaseRaw) { apiBase = new URL(apiBaseRaw, location.href); apiBase.pathname = apiBase.pathname.replace(/\/$/, ''); } } catch (_) { apiBase = null; }
  var apiOrigin = apiBase ? apiBase.origin : null;
  function apiPath(path) { return apiBase ? new URL(path, apiBase.origin + '/').href : path; }
  function targetUrl(value) { try { return new URL(typeof value === 'string' ? value : value.url, location.href); } catch (_) { return null; } }
  function isApiTarget(u) { return !!u && (u.origin === location.origin || (apiOrigin && u.origin === apiOrigin)); }
  var wordpress = new URL(assets).pathname.indexOf('/wp-content/') === 0;
  var configUrl = wordpress && !apiBase ? '/wp-json/wp/v2/settings' : (apiBase ? apiPath('/api/v1/guest/comm/config') : '/api/v1/guest/comm/config');
  var endpoint = wordpress && !apiBase ? '/wp-json/wp/v2/captcha/' : (apiBase ? apiPath('/api/v1/guest/captcha/') : '/api/v1/guest/captcha/');
  var nativeFetch = window.fetch.bind(window);
  var NativeXHR = window.XMLHttpRequest;
  var cached = null, pending = null, loadedAt = 0, modalQueue = Promise.resolve();
  function urlOf(value) { return targetUrl(value); }
  function isConfig(value) { var u=urlOf(value); return isApiTarget(u) && /\/(?:api\/v[12]\/guest\/comm\/config|wp-json\/wp\/v2\/settings)\/?$/.test(u.pathname); }
  function protectedRequest(value, method) { var u=urlOf(value); return isApiTarget(u) && String(method || 'GET').toUpperCase()==='POST' && /\/(?:passport\/(?:auth\/register|comm\/sendEmailVerify)|wp\/v2\/users\/(?:register|verify-email))\/?$/.test(u.pathname); }
  function remember(json) {
    var value=json && json.data && typeof json.data==='object' ? json.data : json;
    if (value && typeof value==='object' && 'captcha_type' in value) { cached=value; loadedAt=Date.now(); }
    return value;
  }
  function active(config) { return !!config && Number(config.is_captcha)===1 && config.captcha_type==='cap'; }
  function clientConfig(json) {
    var config=remember(json);
    if (!active(config)) return json;
    // The original theme knows only Google/Turnstile. The bridge owns Cap's UI;
    // server-side CAPTCHA remains enabled and cannot be bypassed by these UI flags.
    var copy=Object.assign({},config,{is_captcha:0,is_recaptcha:0});
    return json && json.data ? Object.assign({},json,{data:copy}) : copy;
  }
  function getConfig() {
    if (cached && Date.now()-loadedAt<30000) return Promise.resolve(cached);
    if (!pending) pending=nativeFetch(configUrl,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}})
      .then(function(r){if(!r.ok)throw new Error('config');return r.json();}).then(remember)
      .catch(function(){return null;}).finally(function(){pending=null;});
    return pending;
  }
  var widgetReady;
  function loadWidget() {
    if (!widgetReady) widgetReady=new Promise(function(resolve,reject){
      window.CAP_CUSTOM_WASM_URL=new URL('cap_wasm_bg.wasm',assets).href;
      window.CAP_CUSTOM_HASHWX_URL=new URL('hashwx.wasm',assets).href;
      window.CAP_PAKO_URL=new URL('pako_inflate.min.js',assets).href;
      var js=document.createElement('script'); js.src=new URL('widget.js',assets).href; js.async=true;
      js.onload=function(){customElements.whenDefined('cap-widget').then(resolve);};
      js.onerror=function(){widgetReady=null;reject(new Error('验证码组件加载失败，请刷新重试'));};
      document.head.appendChild(js);
    });
    return widgetReady;
  }
  function showWidget(config) {
    return loadWidget().then(function(){return new Promise(function(resolve,reject){
      if(!/^[a-zA-Z0-9_-]{8,64}$/.test(config.cap_site_key || '')) return reject(new Error('Cap 尚未完成本机配置'));
      var previous=document.activeElement;
      var cover=document.createElement('div');cover.className='xboard-cap-cover';
      var panel=document.createElement('section');panel.className='xboard-cap-panel'; panel.setAttribute('role','dialog');panel.setAttribute('aria-modal','true');panel.setAttribute('aria-label','安全验证');panel.tabIndex=-1;
      var title=document.createElement('h2');title.textContent='安全验证';
      var text=document.createElement('p');text.textContent='完成验证后将继续当前操作。';
      var message=document.createElement('p');message.className='xboard-cap-message';message.setAttribute('role','status');
      var widget=document.createElement('cap-widget');
      widget.setAttribute('data-cap-api-endpoint',new URL(endpoint,location.origin).href);
      widget.setAttribute('data-cap-worker-count','2');
      widget.setAttribute('data-cap-i18n-initial-state','点击进行人机验证');
      widget.setAttribute('data-cap-i18n-verifying-label','正在验证…');
      widget.setAttribute('data-cap-i18n-solved-label','验证通过');
      widget.setAttribute('data-cap-i18n-error-label','验证失败，请重试');
      widget.setAttribute('data-cap-i18n-required-label','请先完成人机验证');
      var cancel=document.createElement('button');cancel.type='button';cancel.textContent='取消';
      var settled=false, timer;
      function finish(error,token){if(settled)return;settled=true;clearTimeout(timer);document.removeEventListener('keydown',keyDown,true);cover.remove();if(previous && previous.isConnected)previous.focus();error?reject(error):resolve(token);}
      function keyDown(e){if(e.key==='Escape'){e.preventDefault();finish(new Error('已取消安全验证'));}else if(e.key==='Tab'){var focus=panel.querySelectorAll('button,[tabindex="0"]');if(focus.length && ((e.shiftKey && document.activeElement===focus[0]) || (!e.shiftKey && document.activeElement===focus[focus.length-1]))){e.preventDefault();(e.shiftKey?focus[focus.length-1]:focus[0]).focus();}}}
      cancel.addEventListener('click',function(){finish(new Error('已取消安全验证'));});
      widget.addEventListener('solve',function(e){var token=e.detail && e.detail.token;if(typeof token==='string' && token.length<=256)finish(null,token);});
      widget.addEventListener('error',function(){message.textContent='验证服务暂不可用或挑战失败，请重试；也可以取消后稍后再试。';});
      panel.append(title,text,widget,message,cancel);cover.appendChild(panel);document.body.appendChild(cover);panel.focus();document.addEventListener('keydown',keyDown,true);
      timer=setTimeout(function(){finish(new Error('安全验证超时，请重新操作'));},120000);
    });});
  }
  function obtain(config) { var job=modalQueue.catch(function(){}).then(function(){return showWidget(config);});modalQueue=job.catch(function(){});return job; }
  function addToken(body,token) {
    if(typeof FormData!=='undefined' && body instanceof FormData){var f=new FormData();body.forEach(function(v,k){f.append(k,v);});f.set('cap_token',token);return f;}
    if(body instanceof URLSearchParams){var p=new URLSearchParams(body);p.set('cap_token',token);return p;}
    if(typeof body==='string'){try{var value=JSON.parse(body);if(value && typeof value==='object' && !Array.isArray(value))return JSON.stringify(Object.assign({},value,{cap_token:token}));}catch(_){}var p=new URLSearchParams(body);p.set('cap_token',token);return p.toString();}
    throw new Error('当前请求格式不支持 Cap，请刷新页面后重试');
  }
  window.fetch=function(input,init){
    var opts=Object.assign({},init || {}), method=opts.method || (input && input.method) || 'GET';
    if(isConfig(input)) return nativeFetch(input,opts).then(async function(response){try{var json=await response.clone().json();var updated=clientConfig(json);if(updated!==json){var headers=new Headers(response.headers);headers.delete('content-length');return new Response(JSON.stringify(updated),{status:response.status,statusText:response.statusText,headers:headers});}}catch(_){}return response;});
    if(!protectedRequest(input,method)) return nativeFetch(input,opts);
    return getConfig().then(async function(config){
      if(!active(config))return nativeFetch(input,opts);
      if(opts.signal && opts.signal.aborted)throw new DOMException('Aborted','AbortError');
      var token=await obtain(config);
      if(opts.signal && opts.signal.aborted)throw new DOMException('Aborted','AbortError');
      var request=input;
      if(typeof Request!=='undefined' && input instanceof Request){var original=input;var body=opts.body===undefined?await original.clone().text():opts.body;request=new Request(original,Object.assign({},opts,{body:addToken(body,token)}));return nativeFetch(request);}
      opts.body=addToken(opts.body,token);return nativeFetch(request,opts);
    });
  };
  class CapXHR extends NativeXHR {
    open(method,url){this._capMethod=method;this._capUrl=url;this._capAborted=false;this._capGeneration=(this._capGeneration || 0)+1;var result=super.open.apply(this,arguments);var xhr=this;
      if(isConfig(url))this.addEventListener('readystatechange',function(){if(xhr.readyState!==4 || xhr.status<200 || xhr.status>=300)return;try{var isJson=xhr.responseType==='json';var original=isJson?xhr.response:JSON.parse(xhr.responseText);var updated=clientConfig(original);if(updated!==original){var jsonText=JSON.stringify(updated);Object.defineProperty(xhr,'responseText',{configurable:true,get:function(){return jsonText;}});Object.defineProperty(xhr,'response',{configurable:true,get:function(){return isJson?updated:jsonText;}});}}catch(_){} });
      return result;
    }
    abort(){this._capAborted=true;this._capGeneration=(this._capGeneration || 0)+1;return super.abort();}
    send(body){var xhr=this, generation=this._capGeneration;
      if(!protectedRequest(this._capUrl,this._capMethod))return super.send(body);
      getConfig().then(function(config){if(xhr._capAborted || xhr._capGeneration!==generation)return;
        if(!active(config))return NativeXHR.prototype.send.call(xhr,body);
        return obtain(config).then(function(token){if(!xhr._capAborted && xhr._capGeneration===generation)NativeXHR.prototype.send.call(xhr,addToken(body,token));});
      }).catch(function(){if(!xhr._capAborted && xhr._capGeneration===generation){xhr.dispatchEvent(new ProgressEvent('error'));}});
    }
  }
  window.XMLHttpRequest=CapXHR;
})();
