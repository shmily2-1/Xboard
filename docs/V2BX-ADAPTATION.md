# 原 Xboard 页面内的 V2bX 适配

本扩展基于 Xboard `d826a3b32f73bde24acce2a3ab033c6538ce121d`，配套 V2bX 使用 `v0.1.0-core-upgrade.26`。原节点、用户、套餐、支付与后台资源继续保留。没有新增 Composer 或 npm 依赖。

### Hysteria2 连接修复（2026-11-17）

原节点表单新增证书域名，保存为 `protocol_settings.tls.server_name`，供节点 API 与客户端订阅共用。伪装站点仍单独保存为 `protocol_settings.masquerade`；V2bX 在线配置会直接读取，不再重复输入。

原有和 V2bX 扩展后端都会将节点自身的 `port` 范围下发为 `ports`，并下发 `hop_interval`，保留 `server_port` 为固定 UDP 监听端口。单端口下发 `ports:null`，不创建范围转发。节点组中有父节点时仍使用当前节点自己的公网范围。修复前订阅已经在随机选端口，但节点 API 缺少范围，导致只有固定端口可用。

更新面板后按实际运行方式清理缓存并重载 PHP/Octane，再升级节点到 `.26`。核对 `/api/v1/server/UniProxy/config` 的 `ports` 与订阅范围一致，并使用真实客户端验证认证和 HTTPS 转发。无需数据库迁移或重新申请已有证书。已临时添加的手工转发规则可在正式规则验证通过后撤下。

离线回归：`php scripts/test-hysteria-config.php`，使用已安装的锁定 Composer 依赖，不连接数据库或生产面板。

## 已接入范围

| 功能 | 入口和边界 |
| --- | --- |
| HTTP/Mixed | 新节点选择 `v2bx-extended`，使用 UUID 用户名和独立连接密码；支持普通订阅及专用 Xray JSON |
| MASQUE | 地址池、MTU、Host、Path、H2/H3；使用专用 Xray JSON；证书在节点本机配置 |
| XDRIVE | VLESS 的传输配置；启用 Encryption，清空 flow，关闭 TLS/REALITY，客户端使用 Xray Mux |
| Xray 密钥生成 | 原节点表单生成 VLESS Encryption、REALITY X25519、MLDSA65、Short ID；面板服务器需配置 `V2BX_XRAY_BIN` |
| 机器服务 | Tunnel、TUN、WireGuard、AmneziaWG、MTProto 管理入口已停用；历史数据和旧节点同步接口保留，不再提供新建或编辑 |

远端 XDRIVE 配置可以保存并下发给节点，但本版面板不自动将共享远端存储凭据发给订阅用户。`local` 模式须保证客户端和服务端能访问相容的共享存储。远端 S3、Google Drive 和模板后端需单独配置权限隔离并验收。

MASQUE 暂不支持非零设备数限制；AmneziaWG 暂不支持非零用户限速。保存与同步会明确拒绝不兼容配置。WireGuard 的限速验收范围为 TCP/UDP。MTProto 不提供按 Telegram 内部用户计费。

## 面板部署与页面

使用现有 Xboard 的部署流程安装 **composer.lock 锁定的依赖**，准备数据库、Redis、应用密钥及队列环境后执行：

```sh
php artisan migrate --force
php artisan optimize:clear
```

升级现有面板前备份数据库和配置；不要替换原 `APP_KEY`，否则原有加密凭据无法解密。全新部署按 Xboard 原有流程生成专属应用密钥。此代码包不含测试数据库、测试账号、凭据、vendor 或测试环境配置。

所有入口沿用原项目：

- 原后台“节点管理”的添加/编辑弹窗增加 V2bX 后端、Mixed/MASQUE、VLESS/XDRIVE 和相应配置，继续使用原节点列表、权限组、显隐、筛选、修改和删除操作。
- 原后台不再显示机器服务管理入口；历史机器服务数据不自动删除。
- 原服务器详情的安装后端可以选择原 xboard-node 或 V2bX；V2bX 选项提供固定版本组件安装命令和私有同步配置下载。安装组件不表示已经启动节点或完成普通代理节点配置。
- 用户仪表盘保持原 Xboard 订阅界面，不新增机器服务、Peer 或专用配置卡片。

没有独立管理站点、额外登录页、悬浮跳转按钮或 iframe。旧 `/<secure_path>/v2bx` 与 `/connections` 地址只跳转回原节点管理和原仪表盘。数据库中的既有服务、凭据、租约和计量数据保留，原 API 兼容路径继续供已部署节点使用。

上游管理端公开仓库仅提供构建产物。本仓库固定其原始版本 `ef5f43da335092cbff8fdf0ad7ff9b4d92d7d0d7`，通过 `scripts/build-admin-native.py` 将可编辑的 `resources/js/admin-v2bx.js` 编入原管理模块，复用原组件、路由、鉴权与样式。用户端保持原始 Xboard 构建，不注入额外页面。生成器校验原始 SHA256 和精确接入位置；上游版本变化时明确失败，不能静默猜测替换。

生成后的管理端随本仓库保存，不再由原管理端子模块覆盖。运行 `python3 scripts/check-native-build.py` 可离线复现并检查产物，无需新增 npm/Composer 依赖。标准 `php artisan xboard:update` 会按原流程刷新主题资源；自定义用户主题需要在自己的原订阅组件接入相同用户 API。

## 节点端版本与配置

须使用包含本次补丁的 V2bX。构建标签：

```sh
go build -tags 'sing xray hysteria2 with_quic with_grpc with_utls with_wireguard with_acme with_gvisor' -o V2bX .
```

HTTP/Mixed 选择 `Core: sing`，MASQUE 和 VLESS/XDRIVE 选择 `Core: xray`。继续使用原 UniProxy API 的 `ApiHost/ApiKey/NodeID/NodeType`。每个代理节点设置可写且私有的 `TrafficStateDir`，用于保存批次和待上报流量。

HTTP/Mixed 开启 TLS 或使用 MASQUE 时，须在节点本机配置有效的 `CertConfig`，包括证书、私钥、域名及适用的 ALPN。面板的证书表单不会自动替代节点本机配置。MASQUE 的客户端 ALPN 必须与节点实际 ALPN 一致。

访问端口 `port` 和服务监听端口 `server_port` 分别保存。专用客户端导出使用访问端口，避免 NAT 或转发部署下连接错误。

HTTP/Mixed 的协议设置示意：

```json
{"tls":0,"tls_settings":{"server_name":"NODE_HOST"}}
```

MASQUE 需要 `address`、`mtu`、`path`、`masque_host`、`alpn` 和 `tls_settings`。XDRIVE 在 VLESS 的 `network_settings` 保存存储参数，`encryption` 保存加密参数。X25519 私钥使用无填充 Base64URL；客户端公钥由服务端参数派生。ML-KEM 配置必须导入锁定 Xray 生成的客户端公钥参数，本版不提供 PHP 端的 ML-KEM 密钥生成器。

普通订阅会过滤 MASQUE/XDRIVE，避免输出不完整或被静默降为 TCP 的配置。用户从原“我的订阅”卡片下载匹配锁定 Xray 的完整出站配置，再按客户端要求合并本地入站、DNS 和路由。

## 旧机器服务同步接口

Python 服务代理 `scripts/v2bx-service-agent.py` 使用 Python 标准库调用 V2bX CLI，轮询原机器资源下的 `/api/v2/server/machine/services/pull`、`ack` 和 `meter`。旧接口只在 404 时回退；认证失败不回退。它与 V2bX 服务进程分别运行，不会再次消费核心的原子流量计数器。

V2bX `.20` 的一键安装包已包含相同组件，可直接运行 `v2bx panel-agent --config /etc/V2bX/xboard-agent.json`。代理配置中的 `v2bx` 指向真实二进制 `/usr/local/V2bX/V2bX`，不能指向管理菜单别名。

节点主配置中明确设置 `LocalServiceDir`。代理仅管理 `xb-<服务ID>` 命名空间；原本手工管理的服务不会按面板列表自动删除。

先在面板创建机器并取得其 Token，再在节点的私有目录保存代理配置。以下为字段结构示意，不能将占位值作为实际配置启用：

```json
{
  "panel":"https://PANEL_HOST",
  "machine_id":1,
  "token":"MACHINE_TOKEN",
  "state_dir":"/var/lib/V2bX/xboard-agent",
  "v2bx":"/usr/local/bin/V2bX",
  "v2bx_config":"/etc/V2bX/config.json",
  "interval":10,
  "backend_profiles":{},
  "metrics_ports":{}
}
```

保护配置和状态目录的访问权限。远程面板必须使用 HTTPS；HTTP 仅接受本机联调地址。启动命令：

```sh
V2bX server -c /etc/V2bX/config.json
python3 /path/to/Xboard/scripts/v2bx-service-agent.py --config /etc/V2bX/xboard-agent.json
```

`--once` 执行单轮同步。常驻运行可交给现有进程管理器；本扩展不会自行安装系统服务或修改系统路由/NAT。

旧机器服务的同步接口和历史配置文件仍保留，供已部署节点完成收尾；面板不再允许新增或编辑这些服务。

- `Executable`：已经取得并审核的本地二进制绝对路径。
- `SHA256`：该文件的实际 SHA256；代理同步时校验文件。
- `Version`：分别为 `amneziawg-go-v3.1.20260828` 或 `mtg-2.1.7`。

MTProto 还需在 `metrics_ports` 为每个 `xb-服务ID` 预留不同的本机指标端口。Secret 由面板加密保存，节点写入私有文件；链接只在管理员请求时导出。

WireGuard/AmneziaWG 服务的服务器私钥由节点 `V2bX services keygen` 生成。该命令使用独占创建，拒绝覆盖现有文件，只向标准输出返回公钥。节点将服务器公钥上报给面板。客户端私钥由用户持有，导出时检查与登记公钥一致，不保存到面板。

用户态 WireGuard 的 `Listen` 必须使用 `0.0.0.0:端口` 或 `[::]:端口`。地址池在同一机器内不能重叠。服务器地址、保留地址和已占用地址不发给 Peer。

## 状态、撤销与计量

服务配置有期望修订和节点实际应用修订。保存后显示“待应用”，收到真实节点确认后再显示运行/停用；超过 60 秒未上报，页面显示状态过期。不能把保存成功视为后端已启动。

Peer 换钥保留地址。撤销后，地址一直保留到节点确认移除；历史 Peer 记录保留，用于延迟流量归属。服务删除同样先停用、收取剩余流量，再确认移除。机器上有未移除服务时禁止直接删除机器；有未停用服务时禁止禁用机器。

新代理节点和服务流量都使用持久批次及唯一约束去重。相同批次不同内容会报冲突。用户额度与对应统计在同一事务中更新；服务总量不会被写入虚构用户账单。旧接口/旧后端保留原行为，未协商批次的请求不承诺重复上报去重。

当前用量倍率按面板首次成功接收批次时确定。跨权限组迁移前应先结清节点待上报流量；本版没有历史权限组授权账本。节点断电、系统崩溃发生在本地计数持久化之前时，仍可能丢失最后一个采样窗口，不能宣称端到端零损失。

WireGuard/AmneziaWG 原生 Peer 字节包含协议开销，与普通代理应用字节口径不同。Tunnel/TUN/MTProto 按服务统计；MTProto 的 Telegram DC 和实际消息公网互通须另行验证。

## 备份、升级和回退

需共同备份：数据库、原 `APP_KEY`、V2bX `TrafficStateDir`、`LocalServiceDir` 状态、代理 `state_dir` 和后端 Profile。

停止扩展前先在面板停用/移除服务并确认，再停代理或回退节点。不能先删除代理目录、地址状态或待报队列。新增迁移的 `down()` 明确拒绝自动删除凭据、租约和计量表；保留这些数据后再制定人工回退操作。

本次测试覆盖 PHP 8.3 的 HTTP/SQLite 实际请求、浏览器操作、真实双端数据转发以及 Linux 隔离后端。测试环境将设置缓存映射到 Laravel 文件缓存，不包含 Redis、Horizon 常驻队列和 Octane 生产验证。正式环境仍按 Xboard 原部署要求提供它们。
