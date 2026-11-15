/* Compiled into the pinned original admin module by build-admin-native.py.
 * H, Lf, u8e, htt/ptt and the Select components are the original React/shadcn
 * bindings. No additional React root, router, login or stylesheet is created.
 */
const XBCreate = H.createElement;
const XBClass = "space-y-2 font-mono text-xs";
function XBButton(label, onClick, props = {}) {
  return XBCreate(Lf, {type: "button", variant: "outline", size: "sm", onClick, ...props}, label);
}
function XBField({label, value, onChange, type = "text", options, disabled, help}) {
  const id = H.useId();
  const control = options ? XBCreate(yzt, {value: String(value ?? ""), onValueChange: onChange, disabled},
    XBCreate(Czt, {id, className: "font-mono text-xs"}, XBCreate(wzt, {placeholder: "请选择"})),
    XBCreate(Nzt, null, options.map(([v, text]) => XBCreate(Lzt, {key: v, value: String(v)}, text))))
    : XBCreate(u8e, {id, type, value: value ?? "", disabled, className: "font-mono text-xs",
      onChange: e => onChange(type === "number" ? (e.target.value === "" ? "" : Number(e.target.value)) : e.target.value)});
  return XBCreate("div", {className: XBClass}, XBCreate("label", {htmlFor: id, className: "text-foreground/80"}, label), control,
    help && XBCreate("p", {className: "text-muted-foreground"}, help));
}
function XBCheck({label, checked, onChange}) {
  const id = H.useId();
  return XBCreate("div", {className: "flex items-center gap-2 font-mono text-xs"},
    XBCreate(oZt, {id, checked: !!checked, onCheckedChange: onChange}), XBCreate("label", {htmlFor: id}, label));
}
function XBJson({label, value, onChange, onError}) {
  const [text, setText] = H.useState(JSON.stringify(value ?? {}, null, 2));
  const [error, setError] = H.useState("");
  const focused = H.useRef(false);
  H.useEffect(() => { if (!focused.current) setText(JSON.stringify(value ?? {}, null, 2)); }, [JSON.stringify(value)]);
  return XBCreate("div", {className: XBClass}, XBCreate("label", null, label),
    XBCreate("textarea", {value: text, rows: 5, "aria-label": label, spellCheck: false,
      className: "flex w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-xs",
      onFocus: () => {focused.current = true;}, onBlur: () => {focused.current = false;},
      onChange: e => {const next = e.target.value; setText(next); try {
        const data = JSON.parse(next); if (!data || typeof data !== "object" || Array.isArray(data)) throw Error();
        setError(""); onError?.(false); onChange(data);
      } catch {setError("请输入合法的 JSON 对象"); onError?.(true);}}}),
    error && XBCreate("p", {role: "alert", className: "text-destructive"}, error));
}
function XBRandomText(length = 16) { const chars = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789"; const bytes = crypto.getRandomValues(new Uint8Array(length)); return Array.from(bytes, b => chars[b % chars.length]).join(""); }
function XBRandomB64URL(bytes = 32) { const raw = crypto.getRandomValues(new Uint8Array(bytes)); let text = ""; for (const b of raw) text += String.fromCharCode(b); return btoa(text).replace(/\+/g,"-").replace(/\//g,"_").replace(/=+$/g,""); }
function XBEncryptionEditor({value, onChange}) {
  const v = value || {}; const [busy, setBusy] = H.useState(false); const update = (key, next) => onChange({...v, [key]: next, enabled: true});
  const generate = () => { const privateKey = XBRandomB64URL(32); onChange({...v, enabled: true, method: v.method || "mlkem768x25519plus", mode: v.mode || "random", ticket: v.ticket || "600s", private_key: privateKey, decryption: [v.method || "mlkem768x25519plus", v.mode || "random", v.ticket || "600s", v.server_padding || "", privateKey].filter(Boolean).join(".")}); };
  const generateNative = () => XBRun(async () => { setBusy(true); try {
    const response = await RL(OL() + "/server/manage/generateNodeKeys", {kind: "vless_encryption", core: "xray"});
    const data = response?.data || response;
    onChange({...v, enabled: true, method: "mlkem768x25519plus", mode: "native", ticket: "600s", private_key: data.decryption?.split(".").slice(3).join("."), decryption: data.decryption, encryption: data.encryption});
    gE.success("已按锁定 Xray 生成 VLESS 成对参数");
  } finally { setBusy(false); }});
  return XBCreate("div", {className: "flex flex-wrap items-center gap-2 border-t pt-3"},
    XBCreate("span", {className: "text-xs text-muted-foreground"}, "VLESS Encryption 参数生成"),
    XBButton("按 Xray 生成成对参数", generateNative, {disabled: busy}),
    XBButton("本地生成服务端参数", generate, {disabled: busy}),
    v.enabled && XBCreate("div", {className: "grid w-full gap-3 border-t pt-3 md:grid-cols-3"},
      XBCreate(XBField, {label: "加密类型", value: v.method || "mlkem768x25519plus", options: [["mlkem768x25519plus", "ML-KEM-768 + X25519"]], onChange: n => update("method", n)}),
      XBCreate(XBField, {label: "模式", value: v.mode || "native", options: [["native", "native"], ["xorpub", "xorpub"], ["random", "random"]], onChange: n => update("mode", n)}),
      XBCreate(XBField, {label: "票据有效期", value: v.ticket || "600s", onChange: n => update("ticket", n)}),
      XBCreate(XBField, {label: "服务端填充（可选）", value: v.server_padding || "", onChange: n => update("server_padding", n)})));
}
function XBHysteriaEditor({form, value, onChange}) {
  const v = value || {}, ob = v.obfs || {}, quic = v.quic || {}, profile = form.watch("backend_profile") || "legacy", xrayBackend = profile === "v2bx-extended";
  const set = (key, next) => onChange({...v, [key]: next});
  const setOb = (key, next) => onChange({...v, obfs: {...ob, [key]: next}});
  const setObMode = next => onChange({...v, obfs: {...ob, open: true, type: next, mode: next,
    ...(next === "gecko" ? {min_packet_size: ob.min_packet_size || 512, max_packet_size: ob.max_packet_size || 1200} : {})}});
  const setQuic = (key, next) => onChange({...v, quic: {...quic, [key]: next}});
  const generate = () => setOb("password", XBRandomText(18));
  return XBCreate("div", {className: "space-y-4 rounded-md border bg-muted/20 p-3"},
    XBCreate("strong", null, "Hysteria2 传输与伪装"),
    XBCreate(XBField, {label: "版本", value: v.version || 2, type: "number", disabled: true, onChange: n => set("version", n)}),
    XBCreate(XBField, {label: "UDP 空闲超时（秒）", value: v.udp_idle_timeout || 60, type: "number", onChange: n => set("udp_idle_timeout", n)}),
    XBCreate(XBCheck, {label: "启用 UDP 伪装", checked: !!ob.open, onChange: n => onChange({...v, obfs: {...ob, open: n, type: ob.type || "salamander", mode: ob.mode || "salamander"}})}),
    ob.open && XBCreate("div", {className: "space-y-3"},
      XBCreate(XBField, {label: "伪装类型", value: ob.type || "salamander", options: [["salamander", "Salamander (Hysteria2)"], ["gecko", "Gecko experimental"]], onChange: setObMode}),
      XBCreate(XBField, {label: "模式", value: ob.mode || ob.type || "salamander", options: [["salamander", "Salamander"], ["gecko", "Gecko experimental"]], onChange: setObMode}),
      XBCreate(XBField, {label: "伪装密码", value: ob.password || "", onChange: n => setOb("password", n)}), XBButton("自动生成伪装密码", generate),
      (ob.mode === "gecko" || ob.type === "gecko") && XBCreate("div", {className: "grid grid-cols-2 gap-3"}, XBCreate(XBField, {label: "Packet Min", type: "number", value: ob.min_packet_size || 512, onChange: n => setOb("min_packet_size", n)}), XBCreate(XBField, {label: "Packet Max", type: "number", value: ob.max_packet_size || 1200, onChange: n => setOb("max_packet_size", n)}))),
    XBCheck ? XBCreate("div", {className: "space-y-3 border-t pt-3"}, XBCreate("strong", null, "QUIC 参数"), XBCreate(XBField, {label: "初始包大小", type: "number", value: quic.initial_packet_size || 0, disabled: xrayBackend, help: xrayBackend ? "Xray 后端不接受 sing-box 的 initial_packet_size，请保持为 0。" : null, onChange: n => setQuic("initial_packet_size", n)}), XBCreate(XBCheck, {label: "禁用 Path MTU Discovery", checked: !!quic.disable_path_mtu_discovery, onChange: n => setQuic("disable_path_mtu_discovery", n)})) : null,
    XBCreate(XBHysteriaMasqueradeEditor, {value: v.masquerade, onChange: n => set("masquerade", n)}));
}
function XBHysteriaMasqueradeEditor({value, onChange}) {
  const m = value && typeof value === "object" ? value : {}, type = m.type || "";
  const set = (key, next) => onChange({...m, type, [key]: next});
  const auto = () => onChange({type: "string", statusCode: 200, content: "<!doctype html><html><head><meta charset=\"utf-8\"><title>Welcome</title></head><body><h1>Welcome</h1><p>It works.</p></body></html>", headers: {"content-type": "text/html; charset=utf-8"}});
  return XBCreate("div", {className: "space-y-3 border-t pt-3"},
    XBCreate("div", {className: "flex items-center justify-between"}, XBCreate("strong", null, "传输伪装 / Masquerade"), XBCreate("div", {className: "flex gap-2"}, XBButton("自动生成安全页面", auto), type && XBButton("关闭伪装", () => onChange(null)))),
    XBCreate(XBField, {label: "伪装方式", value: type || "none", options: [["none", "不启用"], ["proxy", "代理站点（伪装域名）"], ["file", "本地文件目录"], ["string", "自定义页面"]], onChange: next => onChange(next === "none" ? null : {...m, type: next})}),
    type === "proxy" && XBCreate("div", {className: "space-y-3"}, XBCreate(XBField, {label: "伪装域名 / 代理 URL", value: m.url || "", help: "例如 https://www.example.com/landing", onChange: n => set("url", n)}), XBCreate(XBCheck, {label: "转发时重写 Host", checked: !!m.rewriteHost, onChange: n => set("rewriteHost", n)})),
    type === "file" && XBCreate(XBField, {label: "本地文件目录", value: m.dir || "", help: "填写节点服务器上的静态网站目录，例如 /var/www/html", onChange: n => set("dir", n)}),
    type === "string" && XBCreate("div", {className: "space-y-3"}, XBCreate(XBField, {label: "HTTP 状态码", type: "number", value: m.statusCode || 200, onChange: n => set("statusCode", n)}), XBCreate(XBField, {label: "返回页面内容", value: m.content || "", help: "不会发送到订阅用户，只用于 HY2 入口伪装响应", onChange: n => set("content", n)}), XBCreate(XBJson, {label: "响应头（可选）", value: m.headers || {}, onChange: n => set("headers", n)})),
    XBCreate("p", {className: "text-xs text-muted-foreground"}, "小白可直接点击“自动生成安全页面”；代理站点模式需要填写可访问的 https/http 域名。"));
}
function XBRealityGenerator({value, onChange}) {
  const reality = value || {}, [busy, setBusy] = H.useState(false);
  const generate = () => XBRun(async () => { setBusy(true); try {
    const [x25519, mldsa, shortId] = await Promise.all([
      RL(OL() + "/server/manage/generateNodeKeys", {kind: "x25519", core: "xray"}),
      RL(OL() + "/server/manage/generateNodeKeys", {kind: "mldsa65", core: "xray"}),
      RL(OL() + "/server/manage/generateNodeKeys", {kind: "short_id", core: "xray"}),
    ]);
    const x = x25519?.data || x25519, m = mldsa?.data || mldsa, s = shortId?.data || shortId;
    onChange({...reality, private_key: x.private_key, public_key: x.public_key, short_id: s.short_id, mldsa65Seed: m.seed, mldsa65Verify: m.verify});
    gE.success("已按 Xray 生成 Reality 参数");
  } finally { setBusy(false); }});
  return XBCreate("div", {className: "space-y-3 rounded-md border bg-muted/20 p-3"},
    XBCreate("div", {className: "flex items-center justify-between"}, XBCreate("strong", null, "REALITY 密钥"), XBButton("按 Xray 自动生成", generate, {disabled: busy})),
    XBCreate("p", {className: "text-xs text-muted-foreground"}, "生成 X25519、公钥、Short ID 和 MLDSA65 Seed/Verify；域名、Dest 和证书仍需填写。"));
}
function XBNodeProtocol({form, serverType, value, onChange}) {
  const profile = form.watch("backend_profile") || "legacy";
  const settings = value || {};
  const isHysteria = ["hysteria", "hysteria2"].includes(serverType);
  const v2bxType = ["http", "mixed", "masque", "hysteria", "hysteria2", "vless"].includes(serverType);
  H.useEffect(() => {if (v2bxType && profile !== "v2bx-extended") form.setValue("backend_profile", "v2bx-extended");}, [serverType, profile, v2bxType]);
  const field = (label, key, extra = {}) => XBCreate(XBField, {key, label, value: settings[key], onChange: v => onChange({...settings, [key]: v}), ...extra});
  const badJson = key => invalid => invalid ? form.setError(key, {type: "validate", message: "JSON 无效"}) : form.clearErrors(key);
  const children = [];
  if (v2bxType) children.push(XBCreate("div", {key: "backend-fixed", className: "rounded-md border bg-muted/20 px-3 py-2 text-xs text-muted-foreground"},
    XBCreate("span", {className: "font-medium text-foreground"}, "节点后端：V2bX"),
    XBCreate("span", {className: "ml-2"}, "当前适配节点统一使用 V2bX 后端。")));
  if (["http", "mixed"].includes(serverType)) children.push(XBCreate("div", {key: "mixed-help", className: "rounded-md border bg-muted/20 p-3 text-xs text-muted-foreground"},
    XBCreate("strong", {className: "block text-foreground"}, "V2bX HTTP/Mixed 参数"),
    XBCreate("p", {className: "mt-1"}, "账号密码按用户单独生成、轮换和计量，不在节点表单中填写。保存节点后，在原用户管理或订阅卡片中管理连接密码。"),
    XBCreate("p", {className: "mt-1"}, "本节点表单只配置监听端口和 TLS；Mixed 客户端导出时会映射为 SOCKS 出站。")));
  if (serverType === "masque") {
    children.push(field("隧道地址池（逗号分隔）", "address", {value: (settings.address || []).join(", "),
      onChange: v => onChange({...settings, address: v.split(/[\n,]+/).map(s => s.trim()).filter(Boolean)})}),
      field("MTU", "mtu", {type: "number"}), field("请求路径", "path"), field("请求主机", "masque_host"),
      field("传输方式", "alpn", {value: (settings.alpn || ["h3"]).join(","),
        options: [["h3", "HTTP/3"], ["h2", "HTTP/2"], ["h2,h3", "HTTP/2 与 HTTP/3"]],
        onChange: v => onChange({...settings, alpn: v.split(",")})}),
      XBCreate(XBField, {key: "sni", label: "证书域名", value: settings.tls_settings?.server_name || "",
        onChange: v => onChange({...settings, tls: 1, tls_settings: {...settings.tls_settings, server_name: v}})}));
  } else if (!isHysteria) {
    children.push(XBCreate(o5t, {key: serverType, serverType: serverType === "mixed" ? "http" : (isHysteria ? "hysteria" : serverType), value, onChange}));
  }
  if (serverType === "vless") {
    children.push(XBCreate(XBEncryptionEditor, {key: "enc", value: settings.encryption, onChange: v => onChange({...settings, encryption: v})}));
    if (settings.tls === 2) children.push(XBCreate(XBRealityGenerator, {key: "reality-generator", value: settings.reality_settings, onChange: v => onChange({...settings, reality_settings: v})}));
    if (settings.network === "xdrive") {
      const drive = settings.network_settings || {};
      const change = (key, v) => onChange({...settings, network_settings: {...drive, [key]: v}});
      children.push(XBCreate(XBField, {key: "storage", label: "存储服务", value: drive.service || "local",
        options: [["local", "本地目录"], ["s3", "S3"], ["google-drive", "Google Drive"], ["template", "自定义模板"]],
        onChange: v => change("service", v)}),
        XBCreate(XBField, {key: "folder", label: "存储目录", value: drive.remoteFolder || "", onChange: v => change("remoteFolder", v)}),
        XBCreate(XBJson, {key: "drive", label: "存储凭据、轮询与分段参数", value: drive,
          onChange: v => onChange({...settings, network_settings: v}), onError: badJson("protocol_settings.network_settings")}));
    }
    children.push(XBCreate("div", {key: "vless-help", className: "rounded-md border bg-muted/20 p-3 text-xs text-muted-foreground"},
      XBCreate("strong", {className: "block text-foreground"}, "VLESS 配置提示"),
      XBCreate("p", {className: "mt-1"}, profile === "v2bx-extended" ? "当前使用 V2bX 后端，Encryption、传输参数和 XDRIVE 会随节点配置下发。" : "当前使用原有后端；要启用 V2bX 的 Encryption 或 XDRIVE，请将节点后端切换为 V2bX。")));
  }
  if (isHysteria) children.push(XBHysteriaEditor({form, value: settings, onChange}));
  return XBCreate("div", {className: "space-y-4", "data-native-node-settings": true}, ...children);
}

function XBInstallProtocols() {
  for (const [type, label, color] of [["mixed", "Mixed", "#2196F3"], ["masque", "MASQUE", "#7E57C2"]]) {
    rqt.push({type, label}); sqt[type] = color;
    y5t.push({value: type, label, color});
  }
  s5t.mixed = {...s5t.http};
  s5t.masque = {schema: fy(hy()).default({address: [], mtu: 1500, path: "/", alpn: ["h3"], tls: 1}), component: X4t};
  s5t.vless.schema = s5t.vless.schema.extend({encryption: fy(hy()).default({enabled: false})});
  P4t.networkOptions.push({value: "xdrive", label: "XDRIVE"});
}

const XBMachineBase = OL() + "/server/machine";
const XBStateLabels = {pending: "待应用", running: "运行中", disabled: "已停用", removed: "已移除", error: "应用失败"};
function XBBytes(value) {return Number(value || 0) < 1048576 ? (Number(value || 0) / 1024).toFixed(1) + " KiB" : (Number(value) / 1048576).toFixed(2) + " MiB";}
async function XBRun(action) {try {await action();} catch (e) {gE.error(Object.values(e.response?.data?.errors || {}).flat()[0] || e.response?.data?.message || e.message || "操作失败");}}
function XBDownload(text, filename) {
  const url = URL.createObjectURL(new Blob([text], {type: "application/octet-stream"}));
  const a = document.createElement("a"); a.href = url; a.download = filename; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
}
const XBServiceTypes = [];
function XBMachineInstall({machineId, open, t}) {
  const [backend, setBackend] = H.useState("xboard-node"), [data, setData] = H.useState(null);
  H.useEffect(() => {setData(null);}, [machineId]);
  const fetchInstall = async () => {const response = await IL(XBMachineBase + "/installCommand", {params: {id: machineId, backend: "v2bx"}}); setData(response.data); return response.data;};
  return XBCreate("div", {className: "space-y-3"},
    XBCreate(XBField, {label: "安装后端", value: backend, options: [["xboard-node", "原 xboard-node"], ["v2bx", "V2bX"]], onChange: setBackend}),
    backend === "xboard-node" ? XBCreate(R3t, {machineId, open, t}) :
      XBCreate(s8e, null, XBCreate(o8e, null, XBCreate(a8e, {className: "text-sm"}, "安装 V2bX")),
        XBCreate(c8e, {className: "space-y-3"},
          XBCreate("p", {className: "text-sm text-muted-foreground"}, "机器服务使用同步组件；普通代理节点继续按节点 ID 配置。安装命令只更新组件，配置文件和运行进程保留。"),
          XBButton("生成安装命令", () => XBRun(fetchInstall)),
          data && XBCreate("div", {className: "space-y-3"},
            XBCreate("pre", {className: "overflow-x-auto rounded-md border p-3 font-mono text-xs", style: {whiteSpace: "pre-wrap", overflowWrap: "anywhere"}}, data.command),
            XBCreate("div", {className: "flex flex-wrap gap-2"}, XBButton("复制安装命令", () => XBRun(async () => {await navigator.clipboard.writeText(data.command); gE.success("已复制");})),
              XBButton("下载同步配置", () => XBDownload(JSON.stringify(data.agent_config, null, 2), "xboard-agent.json"))),
            XBCreate("p", {className: "text-sm text-muted-foreground"}, "同步配置含机器凭据，请保存到节点私有目录。按实际安装位置核对二进制和主配置路径，再运行 v2bx panel-agent --config /etc/V2bX/xboard-agent.json。")))));
}
function XBServiceEditor({machineId, service, onSaved, onClose}) {
  const [form, setForm] = H.useState(() => service ? structuredClone(service) : {machine_id: machineId, name: "", type: "tunnel", enabled: false,
    spec: {Listen: "", Target: "", Network: "tcp"}});
  const [busy, setBusy] = H.useState(false), [jsonError, setJsonError] = H.useState(false);
  const spec = form.spec;
  const change = (key, value) => setForm(f => ({...f, [key]: value}));
  const set = (key, value) => change("spec", {...spec, [key]: value});
  const group = (name, key, value) => set(name, {...spec[name], [key]: value});
  const input = (label, key, options) => XBCreate(XBField, {key, label, value: spec[key], onChange: v => set(key, v), options});
  const list = (label, name, key) => XBCreate(XBField, {key: name + key, label, value: (spec[name]?.[key] || []).join(", "),
    onChange: v => group(name, key, v.split(/[\n,]+/).map(s => s.trim()).filter(Boolean))});
  const fields = [XBCreate(XBField, {key: "name", label: "服务名称", value: form.name, onChange: v => change("name", v)}),
    XBCreate(XBField, {key: "type", label: "服务类型", value: form.type, disabled: !!service, options: XBServiceTypes,
      onChange: type => setForm(f => ({...f, type, spec: type === "tunnel" ? {Listen: "", Target: "", Network: "tcp"}
        : type === "tun" ? {TUN: {Interface: "", Address: [], AutoRoute: false}}
        : ["wireguard", "amneziawg"].includes(type) ? {Listen: "", WireGuard: {Address: [], Endpoint: ""}, ...(type === "amneziawg" ? {BackendProfile: "", Obfuscation: {}} : {})}
        : {Listen: "", PublicIP: "", Secret: "", BackendProfile: ""}}))}),
    XBCreate(XBCheck, {key: "enabled", label: "启用服务", checked: form.enabled, onChange: v => change("enabled", v)})];
  if (form.type !== "tun") fields.push(input("监听地址", "Listen"));
  if (form.type === "tunnel") fields.push(input("转发目标", "Target"), input("转发协议", "Network", [["tcp", "TCP"], ["udp", "UDP"], ["tcp,udp", "TCP 与 UDP"]]));
  if (form.type === "tun") fields.push(XBCreate(XBField, {key: "interface", label: "网卡名称", value: spec.TUN?.Interface, onChange: v => group("TUN", "Interface", v)}),
    list("网卡地址", "TUN", "Address"), XBCreate(XBCheck, {key: "autoroute", label: "自动配置路由", checked: spec.TUN?.AutoRoute, onChange: v => group("TUN", "AutoRoute", v)}),
    list("接管网段", "TUN", "RouteAddress"), list("排除网段", "TUN", "RouteExcludeAddress"),
    XBCreate(XBField, {key: "outbound", label: "出口网卡（可选）", value: spec.TUN?.OutboundInterface, onChange: v => group("TUN", "OutboundInterface", v || undefined)}));
  if (["wireguard", "amneziawg"].includes(form.type)) fields.push(list("服务端地址及分配池", "WireGuard", "Address"),
    XBCreate(XBField, {key: "endpoint", label: "客户端连接地址", value: spec.WireGuard?.Endpoint, onChange: v => group("WireGuard", "Endpoint", v)}),
    list("客户端 DNS", "WireGuard", "DNS"), list("客户端允许网段", "WireGuard", "ClientAllowedIPs"));
  if (["tun", "wireguard", "amneziawg"].includes(form.type)) {
    const name = form.type === "tun" ? "TUN" : "WireGuard";
    fields.push(XBCreate(XBField, {key: "mtu", label: "MTU（可选）", type: "number", value: spec[name]?.MTU,
      onChange: v => group(name, "MTU", v === "" ? undefined : v)}));
  }
  if (["amneziawg", "mtproto"].includes(form.type)) fields.push(input("节点本机后端名称", "BackendProfile"));
  if (form.type === "amneziawg") fields.push(XBCreate(XBJson, {key: "obfs", label: "混淆参数", value: spec.Obfuscation, onChange: v => set("Obfuscation", v), onError: setJsonError}));
  if (form.type === "mtproto") fields.push(input("公网主机", "PublicIP"), XBCreate("div", {className: "space-y-2"}, XBCreate(XBField, {key: "secret", type: "password", label: "连接 Secret", value: spec.Secret, onChange: v => set("Secret", v)}), XBButton("自动生成 Secret", () => XBRun(async () => {const response = await RL(OL() + "/server/manage/generateNodeKeys", {kind: "mtproto_secret", core: "sing-box"}); const data = response?.data || response; set("Secret", data.secret); gE.success("Secret 已生成");}))));
  if (form.type === "mtproto") fields.push(XBCreate(XBField, {key: "concurrency", label: "并发连接数（可选）", type: "number", value: spec.Concurrency,
    onChange: v => set("Concurrency", v === "" ? undefined : v)}));
  const save = () => XBRun(async () => {setBusy(true); try {
    if (!form.name.trim() || jsonError) throw Error("请填写服务名称并修正配置");
    await RL(XBMachineBase + "/services/save", {id: service?.id, machine_id: machineId, name: form.name, type: form.type, enabled: form.enabled, spec});
    await onSaved(); onClose(); gE.success("已保存，等待节点应用");
  } finally {setBusy(false);}});
  return XBCreate(htt, {open: true, onOpenChange: onClose}, XBCreate(ptt, {className: "max-w-xl overflow-y-auto", style: {maxHeight: "85vh"}},
    XBCreate(mtt, null, XBCreate(_tt, null, service ? "编辑机器服务" : "添加机器服务")), XBCreate("div", {className: "space-y-4"}, ...fields),
    XBCreate(ftt, null, XBButton("取消", onClose), XBButton(busy ? "保存中…" : "保存", save, {disabled: busy || jsonError}))));
}
function XBPeerManager({service, onReload, onClose}) {
  const blank = {user_id: "", private_key: "", public_key: "", preshared_key: "", clear_psk: false, keepalive: 25, enabled: true};
  const [peer, setPeer] = H.useState(blank), [busy, setBusy] = H.useState(false);
  const rows = (service.peers || []).filter(p => !p.retired);
  const edit = (key, value) => setPeer(p => ({...p, [key]: value}));
  const generateKeys = () => XBRun(async () => {const response = await RL(OL() + "/server/manage/generateNodeKeys", {kind: "wireguard_keypair", core: "sing-box"}); const data = response?.data || response; setPeer(p => ({...p, private_key: data.private_key, public_key: data.public_key})); gE.success("客户端密钥已生成");});
  const generatePsk = () => XBRun(async () => {const response = await RL(OL() + "/server/manage/generateNodeKeys", {kind: "wireguard_psk", core: "sing-box"}); const data = response?.data || response; edit("preshared_key", data.preshared_key); gE.success("预共享密钥已生成");});
  const submit = () => XBRun(async () => {setBusy(true); try {
    const data = {service_id: service.id, user_id: Number(peer.user_id), public_key: peer.public_key, keepalive: Number(peer.keepalive), enabled: peer.enabled};
    if (peer.clear_psk) data.preshared_key = null;
    else if (peer.preshared_key) data.preshared_key = peer.preshared_key;
    await RL(XBMachineBase + "/peers/save", data); setPeer(blank); await onReload(); gE.success("Peer 已保存，等待节点应用");
  } finally {setBusy(false);}});
  return XBCreate(htt, {open: true, onOpenChange: onClose}, XBCreate(ptt, {className: "max-w-2xl overflow-y-auto", style: {maxHeight: "85vh"}},
    XBCreate(mtt, null, XBCreate(_tt, null, service.name + " · Peer 管理")),
    rows.length ? XBCreate("div", {className: "space-y-3"}, ...rows.map(p => {
      const runtime = service.runtime?.peers?.find(r => Number(r.UID) === Number(p.user_id));
      return XBCreate("div", {key: p.id, className: "rounded-md border p-3 space-y-2"},
        XBCreate("p", {className: "font-mono text-xs"}, "用户 " + p.user_id + " · " + p.addresses.join(", ") + (p.deleting ? " · 正在撤销" : p.enabled ? "" : " · 已停用")),
        XBCreate("p", {className: "break-all font-mono text-xs text-muted-foreground"}, p.public_key),
        XBCreate("p", {className: "font-mono text-xs text-muted-foreground"}, "↑ " + XBBytes(p.u) + " ↓ " + XBBytes(p.d) + (runtime?.LastHandshake ? " · 最近握手 " + new Date(runtime.LastHandshake * 1000).toLocaleString("zh-CN") : " · 暂无握手记录")),
        runtime?.Endpoint && XBCreate("p", {className: "font-mono text-xs text-muted-foreground"}, runtime.Endpoint),
        !p.deleting && XBCreate("div", {className: "flex flex-wrap gap-2"}, XBButton("编辑", () => setPeer({...p, preshared_key: "", clear_psk: false})),
          XBButton(p.enabled ? "停用" : "启用", () => XBRun(async () => {await RL(XBMachineBase + "/peers/save", {service_id: service.id, user_id: p.user_id, public_key: p.public_key, keepalive: p.keepalive, enabled: !p.enabled}); await onReload();})),
          XBButton("撤销", () => XBRun(async () => {await RL(XBMachineBase + "/peers/remove", {id: p.id}); await onReload();}), {variant: "destructive"})));
    })) : XBCreate("p", {className: "py-4 text-sm text-muted-foreground"}, "尚未分配 Peer"),
    XBCreate("div", {className: "space-y-3 border-t pt-4"}, XBButton("新增 Peer", () => setPeer(blank)), XBButton("生成客户端密钥", generateKeys),
      XBCreate(XBField, {label: "用户 ID", type: "number", value: peer.user_id, onChange: v => edit("user_id", v)}),
      peer.private_key && XBCreate("div", {className: "space-y-2"}, XBCreate(XBField, {label: "客户端私钥（仅本次显示）", type: "password", value: peer.private_key, onChange: v => edit("private_key", v)}), XBButton("下载客户端私钥", () => XBDownload(peer.private_key + "\n", "wireguard-private-key.txt"))),
      XBCreate(XBField, {label: "客户端公钥", value: peer.public_key, onChange: v => edit("public_key", v)}),
      XBCreate("div", {className: "space-y-2"}, XBCreate(XBField, {label: "预共享密钥（留空保留）", type: "password", value: peer.preshared_key, onChange: v => edit("preshared_key", v)}), XBButton("自动生成预共享密钥", generatePsk)),
      XBCreate(XBCheck, {label: "清除预共享密钥", checked: peer.clear_psk, onChange: v => edit("clear_psk", v)}),
      XBCreate(XBField, {label: "保活间隔", type: "number", value: peer.keepalive, onChange: v => edit("keepalive", v)}),
      XBCreate(XBCheck, {label: "启用 Peer", checked: peer.enabled, onChange: v => edit("enabled", v)})),
    XBCreate(ftt, null, XBButton("关闭", onClose), XBButton("保存 Peer", submit, {disabled: busy}))));
}
function XBMachineServices({machineId, open}) {
  const [rows, setRows] = H.useState([]), [loading, setLoading] = H.useState(true), [error, setError] = H.useState("");
  const [query, setQuery] = H.useState(""), [type, setType] = H.useState("all"), [editor, setEditor] = H.useState(null), [peerId, setPeerId] = H.useState(null);
  const [selected, setSelected] = H.useState([]), [busy, setBusy] = H.useState(false);
  const reload = H.useCallback(async () => {try {const response = await IL(XBMachineBase + "/services", {params: {machine_id: machineId}}); setRows(response.data || []); setError("");} catch {setError("服务读取失败，请重试");} finally {setLoading(false);}}, [machineId]);
  H.useEffect(() => {if (!open) return; setRows([]); setLoading(true); reload(); const timer = setInterval(reload, 5000); return () => clearInterval(timer);}, [machineId, open, reload]);
  const action = (action, ids) => XBRun(async () => {setBusy(true); try {await RL(XBMachineBase + "/services/action", {action, ids}); setSelected([]); await reload();} finally {setBusy(false);}});
  const filtered = rows.filter(s => s.name.includes(query) && (type === "all" || s.type === type));
  const peerService = rows.find(s => s.id === peerId);
  return XBCreate(s8e, {"data-native-machine-services": true}, XBCreate(o8e, null, XBCreate(a8e, {className: "text-sm"}, "机器服务")),
    XBCreate(c8e, {className: "space-y-4"},
      XBCreate("div", {className: "flex flex-wrap items-end gap-2"}, XBCreate(XBField, {label: "查询服务", value: query, onChange: setQuery}),
        XBCreate(XBField, {label: "服务类型", value: type, onChange: setType, options: [["all", "全部"], ...XBServiceTypes]}),
        XBButton("刷新", () => XBRun(reload)), XBButton("添加服务", () => setEditor({}))),
      selected.length > 0 && XBCreate("div", {className: "flex flex-wrap gap-2"}, ...[["enable", "批量启用"], ["disable", "批量停用"], ["remove", "批量撤销"]].map(([a, l]) => XBButton(l, () => action(a, selected), {key: a, disabled: busy}))),
      error && XBCreate("p", {role: "alert", className: "text-destructive"}, error),
      loading ? XBCreate("p", {className: "text-sm text-muted-foreground"}, "正在读取服务…") : !filtered.length ? XBCreate("p", {className: "py-4 text-sm text-muted-foreground"}, query || type !== "all" ? "没有匹配的服务" : "这台机器尚未添加服务") :
        XBCreate("div", {className: "overflow-x-auto"}, XBCreate("table", {className: "w-full text-xs"},
          XBCreate("thead", null, XBCreate("tr", {className: "border-b"}, ...["选择", "服务", "实际状态", "流量", "操作"].map(v => XBCreate("th", {key: v, className: "px-2 py-2 text-left font-medium"}, v)))),
          XBCreate("tbody", null, ...filtered.map(s => XBCreate("tr", {key: s.id, className: "border-b"},
            XBCreate("td", {className: "px-2 py-3"}, XBCreate(oZt, {"aria-label": "选择 " + s.name, checked: selected.includes(s.id), disabled: s.deleting || busy,
              onCheckedChange: checked => setSelected(v => checked ? [...v, s.id] : v.filter(id => id !== s.id))})),
            XBCreate("td", {className: "px-2 py-3"}, s.name, XBCreate("p", {className: "text-muted-foreground"}, s.type)),
            XBCreate("td", {className: "px-2 py-3"}, s.runtime?.reported_at && Date.now()/1000 - s.runtime.reported_at > 60 && s.state !== "removed" ? "状态已过期" : XBStateLabels[s.state] || s.state,
              s.runtime?.error && XBCreate("p", {className: "text-destructive"}, s.runtime.error)),
            XBCreate("td", {className: "px-2 py-3 font-mono"}, "↑ " + XBBytes(s.u) + " ↓ " + XBBytes(s.d)),
            XBCreate("td", {className: "px-2 py-3"}, !s.deleting && XBCreate("div", {className: "flex flex-wrap gap-2"},
              XBButton("编辑", () => setEditor(s)), XBButton(s.enabled ? "停用" : "启用", () => action(s.enabled ? "disable" : "enable", [s.id]), {disabled: busy}),
              XBButton("重启", () => action("restart", [s.id]), {disabled: busy}), XBButton("撤销", () => action("remove", [s.id]), {variant: "destructive", disabled: busy}),
              ["wireguard", "amneziawg"].includes(s.type) && XBButton("Peer", () => setPeerId(s.id)),
              s.type === "mtproto" && XBButton("下载连接", () => XBRun(async () => {const r = await RL(XBMachineBase + "/services/link", {id: s.id}); XBDownload(r, "mtproto-" + s.id + ".txt");}))))))))),
      editor && XBCreate(XBServiceEditor, {key: editor.id || "new", machineId, service: editor.id ? editor : null, onSaved: reload, onClose: () => setEditor(null)}),
      peerService && XBCreate(XBPeerManager, {service: peerService, onReload: reload, onClose: () => setPeerId(null)})));
}

XBInstallProtocols();
