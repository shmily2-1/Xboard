# Xboard / DoroCloud 综合项目

这是一个基于 Laravel 12 的 Xboard 面板适配项目，面向 DoroCloud 的生产部署，包含：Xboard 管理后台、用户中心主题、8090 独立前端、8080 API 网关、8380 独立 API/Cap 入口、V2bX 节点后端接口、订阅协议适配以及 Windows 服务守护脚本。

> 本仓库是 `shmily2-1/Xboard` 的生产适配源码。上游 Xboard 的通用安装说明仍然适用；DoroCloud 的端口、主题和 Windows 部署以本 README、`windows/` 和 `docs/` 中的说明为准。

## 项目组成

| 组件 | 用途 | 生产入口 |
| --- | --- | --- |
| Xboard 主站 | 用户前台、管理后台、订阅和全部 Laravel API | 原站 80 端口 |
| 8090 用户中心 | `user.dorocloud.net` 的独立 `doro-notes` 前端主题 | `user.dorocloud.net:8090` |
| 8080 API | 给 8090 前端和其他客户端使用的独立后端 API | `dorocloud.net:8080`，内部转发到 PHP 8.5 |
| 8380 API/Cap | 受限的客户端 API、Cap 页面和 Cap 静态资源 | 默认仅回环监听 `127.0.0.1:8380` |
| V2bX API | 节点配置、用户、流量上报、在线状态和服务同步 | `/api/v1/server/*`、`/api/v2/server/*` |
| Cap 验证码 | 本机自建、人机挑战、兑换和服务端一次性校验 | `/api/v1/guest/captcha/*` |
| Windows 守护 | PHP、队列、API-8380、Cap、备份和健康检查 | `windows/`、批处理和 PowerShell 脚本 |

生产环境当前使用 PHP 8.5 CLI/FastCGI（PHP 8.5.12）；PHP-FPM/Windows FastCGI 池、Nginx 和守护进程属于部署环境，不把 `.env`、私钥和运行状态提交到仓库。

## 主要功能

- Laravel 12 + Redis + MySQL 的面板后端。
- React/Shadcn 风格管理端，包含 V2bX 节点管理和 Cap 配置入口。
- Xboard 用户前端主题 `theme/Xboard`。
- 8090 独立用户中心主题 `sites/user.dorocloud.net`，主题名为 `doro-notes`。
- VLESS、VMess、Trojan、Shadowsocks、Hysteria/Hysteria2 等订阅及节点配置。
- Hysteria2 `tls.server_name`、Shadowrocket `sni`/`peer`、REALITY 参数、端口跳跃 `ports`/`hop_interval` 适配。
- Cap 本机自建验证码：浏览器端 WASM 挑战、后端代理、一次性 token 校验和 8090 同源映射。
- V2bX 节点握手、配置拉取、用户同步、流量/在线状态上报、服务和凭据管理。
- Windows API-8380、Cap、队列和健康状态守护脚本。

## 目录说明

```text
app/                         Laravel 后端、控制器、服务、协议和中间件
config/                      应用配置
database/                    数据库迁移和初始数据
resources/js/admin-v2bx.js   管理端定制源码
public/assets/admin/         生成后的管理端资源
public/assets/cap/           Xboard 主站 Cap 公共资源
routes/                      Web 路由
app/Http/Routes/V1/          `/api/v1` 路由
app/Http/Routes/V2/          `/api/v2` 路由
theme/Xboard/                Xboard 主用户主题
sites/user.dorocloud.net/   8090 独立用户中心主题
windows/                     Windows 部署、Nginx 示例、API-8380 和 Cap 脚本
tests/                       Hysteria2、端口跳跃和回归测试
```

生产运行时的 `storage/`、`bootstrap/cache/`、`vendor/`、Cap `private.json`、日志、进程状态和二进制文件不作为公开源码提交。

# API 接口总览

API 根路径由 Laravel 的 `RouteServiceProvider` 注册：

```text
/api/v1/*
/api/v2/*
```

认证接口使用 JSON；用户和管理接口使用 `Authorization` 请求头返回的认证信息。节点接口使用节点凭据/服务中间件。支付回调、订阅下载和 WebSocket 不应当按普通 JSON API 处理。

## 入口和端口

### 原站 80

原站提供完整 Xboard 功能：

```text
GET  /
GET  /{后台安全路径}
GET  /{订阅路径}/{token}
GET  /api/v1/*
GET  /api/v2/*
```

后台安全路径和订阅路径由配置决定，不在文档中写死。短订阅地址仍由原站保留。

### 8080 独立后端 API

```text
http://127.0.0.1:8080/api/v1
http://dorocloud.net:8080/api/v1
```

8080 根路径只返回 JSON 服务说明；`/api/` 路径转入 Xboard 后端，不加载主题、后台 HTML 或静态用户页面。生产 Nginx 配置位于宝塔配置目录，仓库示例为：

```text
windows/nginx-api-8080.conf.example
windows/API-8080.md
```

8080 与原站使用同一套 Xboard 源码、数据库和认证体系。

### 8090 独立用户中心前端

```text
http://user.dorocloud.net:8090/
```

8090 是静态前端站点，源码位于 `sites/user.dorocloud.net/`。它通过同源路径调用：

```text
/api/*       -> 代理到 127.0.0.1:8080/api/*
/wp-json/*   -> WordPress 风格路径映射到 /api/v1/*
```

主题资源位于：

```text
sites/user.dorocloud.net/wp-content/themes/doro-notes/
```

8090 的 `/wp-json/` 只是兼容层，不是第二套后端；真实业务仍由 Xboard 的 Laravel API 完成。

### 8380 受限 API/Cap 入口

默认监听：

```text
127.0.0.1:8380
```

提供：

```text
GET  /
GET  /health
GET  /captcha.html
GET  /assets/cap/widget.js
GET  /assets/cap/cap_wasm_bg.wasm
GET  /assets/cap/hashwx.wasm
GET  /assets/cap/pako_inflate.min.js
/api/v1/{guest|passport|user|client}/*
/api/v1/{guest|passport|user|client}/*
/api/v2/{passport|user|client}/*
```

8380 不开放管理接口、节点接口、短订阅路由或项目完整静态目录。配置见 `windows/api-8380/nginx/conf/nginx.conf`。

# `/api/v1` 前端和客户端接口

以下路径均相对于 `/api/v1`。

## Guest 公开接口

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/guest/comm/config` | 公开站点配置、登录注册开关、验证码类型和 Cap 公钥配置 |
| GET | `/guest/plan/fetch` | 公开套餐列表 |
| POST | `/guest/captcha/challenge` | Cap 挑战；仅在后台启用 Cap 时可用 |
| POST | `/guest/captcha/redeem` | 提交 Cap 挑战答案并换取一次性 token |
| GET/POST | `/guest/payment/notify/{method}/{uuid}` | 支付异步通知 |
| POST | `/guest/telegram/webhook` | Telegram webhook |

`siteverify` 不作为公开浏览器接口提供，由 Laravel 通过本机 Cap 服务完成服务端校验。

## Passport 登录注册接口

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| POST | `/passport/auth/register` | 注册 |
| POST | `/passport/auth/login` | 邮箱密码登录 |
| GET | `/passport/auth/token2Login` | token 快速登录 |
| POST | `/passport/auth/forget` | 找回密码 |
| POST | `/passport/auth/getQuickLoginUrl` | 获取快速登录链接 |
| POST | `/passport/auth/loginWithMailLink` | 邮件链接登录 |
| POST | `/passport/comm/sendEmailVerify` | 发送邮箱验证码 |
| POST | `/passport/comm/pv` | 登录/注册页面统计 |

当后台启用验证码时，注册和邮箱验证流程会根据 `captcha_type` 校验 `cap_token` 或其他已配置验证码字段。

## User 用户接口

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/user/resetSecurity` | 重置安全信息 |
| GET | `/user/info` | 当前用户资料 |
| POST | `/user/changePassword` | 修改密码 |
| POST | `/user/update` | 更新用户资料 |
| GET | `/user/getSubscribe` | 获取订阅信息 |
| GET | `/user/getStat` | 用户流量统计 |
| GET | `/user/checkLogin` | 检查登录状态 |
| POST | `/user/transfer` | 余额/邀请相关转移操作 |
| POST | `/user/getQuickLoginUrl` | 获取用户快速登录链接 |
| GET | `/user/getActiveSession` | 活跃会话 |
| POST | `/user/removeActiveSession` | 删除活跃会话 |
| POST | `/user/order/save` | 创建订单 |
| POST | `/user/order/checkout` | 发起结算/支付 |
| GET | `/user/order/check` | 检查订单 |
| GET | `/user/order/detail` | 订单详情 |
| GET | `/user/order/fetch` | 订单列表 |
| GET | `/user/order/getPaymentMethod` | 支付方式 |
| POST | `/user/order/cancel` | 取消订单 |
| GET | `/user/plan/fetch` | 用户可购买套餐 |
| GET | `/user/invite/save` | 生成邀请信息 |
| GET | `/user/invite/fetch` | 邀请列表 |
| GET | `/user/invite/details` | 邀请详情 |
| GET | `/user/notice/fetch` | 用户公告 |
| POST | `/user/ticket/save` | 新建工单 |
| GET | `/user/ticket/fetch` | 工单列表/详情 |
| POST | `/user/ticket/reply` | 工单回复 |
| POST | `/user/ticket/close` | 关闭工单 |
| POST | `/user/ticket/withdraw` | 撤回工单 |
| GET | `/user/server/fetch` | 用户可用节点 |
| GET | `/user/server/connections` | V2bX 连接信息 |
| POST | `/user/server/export` | 导出节点/凭据 |
| POST | `/user/server/rotateCredentials` | 轮换 V2bX 凭据 |
| POST | `/user/coupon/check` | 检查优惠券 |
| POST | `/user/gift-card/check` | 检查礼品卡 |
| POST | `/user/gift-card/redeem` | 兑换礼品卡 |
| GET | `/user/gift-card/history` | 礼品卡使用记录 |
| GET | `/user/gift-card/detail` | 礼品卡详情 |
| GET | `/user/gift-card/types` | 礼品卡类型 |
| GET | `/user/telegram/getBotInfo` | Telegram 机器人信息 |
| GET | `/user/comm/config` | 用户端配置 |
| POST | `/user/comm/getStripePublicKey` | Stripe 公钥 |
| GET | `/user/knowledge/fetch` | 知识库内容 |
| GET | `/user/knowledge/getCategory` | 知识库分类 |
| GET | `/user/stat/getTrafficLog` | 流量明细 |

## Client 客户端接口

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/client/subscribe` | 旧版客户端订阅接口，需要客户端认证中间件 |
| GET | `/client/app/getConfig` | 客户端配置/Clash 配置 |
| GET | `/client/app/getVersion` | Windows、macOS、Android 客户端版本和下载地址 |

## Server 节点后端接口

这些接口不是给普通用户前端使用，必须携带节点服务认证。

### UniProxy

| 方法 | 路径 |
| --- | --- |
| GET | `/server/UniProxy/config` |
| GET | `/server/UniProxy/user` |
| POST | `/server/UniProxy/push` |
| POST | `/server/UniProxy/alive` |
| GET | `/server/UniProxy/alivelist` |
| POST | `/server/UniProxy/status` |

### Shadowsocks/Trojan 兼容后端

| 方法 | 路径 |
| --- | --- |
| GET | `/server/ShadowsocksTidalab/user` |
| POST | `/server/ShadowsocksTidalab/submit` |
| GET | `/server/TrojanTidalab/config` |
| GET | `/server/TrojanTidalab/user` |
| POST | `/server/TrojanTidalab/submit` |

## V1 订阅和 Web 路由

以下不是 `/api/v1` 路由，但属于前端/客户端的重要接口：

```text
GET /{subscribe_path}/{token}        短订阅
GET /connections                      兼容跳转到用户前台
GET /{secure_path}                    管理端 HTML
GET /{secure_path}/v2bx               V2bX 管理页面跳转
```

`subscribe_path`、`secure_path` 从后台配置读取，不能在代码中写死。

# `/api/v2` 接口

## V2 Passport 和 Client

V2 Passport 与 V1 使用相同业务路径：

```text
POST /api/v2/passport/auth/register
POST /api/v2/passport/auth/login
GET  /api/v2/passport/auth/token2Login
POST /api/v2/passport/auth/forget
POST /api/v2/passport/auth/getQuickLoginUrl
POST /api/v2/passport/auth/loginWithMailLink
POST /api/v2/passport/comm/sendEmailVerify
POST /api/v2/passport/comm/pv
```

V2 Client：

```text
GET /api/v2/client/app/getConfig
GET /api/v2/client/app/getVersion
```

V2 User 当前提供：

```text
GET  /api/v2/user/resetSecurity
GET  /api/v2/user/info
GET  /api/v2/user/server/connections
POST /api/v2/user/server/export
POST /api/v2/user/server/rotateCredentials
```

## V2 Server

| 方法 | 路径 | 用途 |
| --- | --- | --- |
| GET/POST | `/api/v2/server/handshake` | 节点握手和能力协商 |
| POST | `/api/v2/server/report` | 节点报告 |
| GET | `/api/v2/server/config` | 获取节点配置 |
| GET | `/api/v2/server/user` | 获取节点用户 |
| POST | `/api/v2/server/push` | 推送节点数据 |
| POST | `/api/v2/server/alive` | 上报在线状态 |
| GET | `/api/v2/server/alivelist` | 在线列表 |
| POST | `/api/v2/server/status` | 节点状态 |
| POST | `/api/v2/server/machine/nodes` | 机器节点同步 |
| POST | `/api/v2/server/machine/status` | 机器状态同步 |

## V2bX 管理、用户和机器接口

### 管理端 V2bX

`{secure_path}` 是后台安全路径，实际值由配置产生：

```text
GET  /api/v2/{secure_path}/v2bx/metadata
GET  /api/v2/{secure_path}/v2bx/nodes
GET  /api/v2/{secure_path}/v2bx/node
GET  /api/v2/{secure_path}/v2bx/services
POST /api/v2/{secure_path}/v2bx/services/save
POST /api/v2/{secure_path}/v2bx/services/action
POST /api/v2/{secure_path}/v2bx/services/link
POST /api/v2/{secure_path}/v2bx/peers/save
POST /api/v2/{secure_path}/v2bx/peers/remove
POST /api/v2/{secure_path}/v2bx/credentials/rotate
```

### 用户端 V2bX

```text
GET  /api/v2/user/v2bx/connections
POST /api/v2/user/v2bx/export
POST /api/v2/user/v2bx/credentials/rotate
```

### 机器端 V2bX

```text
POST /api/v2/server/machine/v2bx/pull
POST /api/v2/server/machine/v2bx/ack
POST /api/v2/server/machine/v2bx/meter
POST /api/v2/server/machine/services/pull
POST /api/v2/server/machine/services/ack
POST /api/v2/server/machine/services/meter
```

# `/api/v2` 管理后台接口完整清单

下面所有管理接口都以配置生成的后台前缀为根：

```text
/api/v2/{secure_path}/...
```

要求管理员认证和后台日志中间件。前端管理资源位于 `public/assets/admin/`，源码入口为 `resources/js/admin-v2bx.js`。

| 模块 | 接口 |
| --- | --- |
| config | `GET fetch`; `POST save`, `POST setTelegramWebhook`, `POST testSendMail`; `GET getEmailTemplate`, `GET getThemeTemplate` |
| mail/template | `GET list`, `GET get`; `POST save`, `POST reset`, `POST test` |
| plan | `GET fetch`; `POST save`, `POST drop`, `POST update`, `POST sort` |
| server/group | `GET fetch`; `POST save`, `POST drop` |
| server/route | `GET fetch`; `POST save`, `POST drop` |
| server/manage | `GET getNodes`, `GET generateEchKey`; `POST update`, `POST save`, `POST drop`, `POST copy`, `POST sort`, `POST batchDelete`, `POST batchUpdate`, `POST resetTraffic`, `POST batchResetTraffic`, `POST generateNodeKeys`, `POST credentials/rotate` |
| server/machine | `GET fetch`, `GET getToken`, `GET installCommand`, `GET nodes`, `GET history`, `GET services`; `POST save`, `POST drop`, `POST resetToken`, `POST services/save`, `POST services/action`, `POST services/link`, `POST peers/save`, `POST peers/remove` |
| order | `ANY fetch`; `POST update`, `POST assign`, `POST paid`, `POST cancel`, `POST detail` |
| user | `ANY fetch`; `GET getUserInfoById`; `POST update`, `POST generate`, `POST dumpCSV`, `POST sendMail`, `POST ban`, `POST resetSecret`, `POST setInviteUser`, `POST destroy` |
| stat | `GET getOverride`, `GET getStats`, `GET getServerLastRank`, `GET getServerYesterdayRank`, `GET getOrder`, `GET getRanking`, `GET getStatRecord`, `GET getTrafficRank`; `ANY getStatUser` |
| notice | `GET fetch`; `POST save`, `POST update`, `POST drop`, `POST show`, `POST sort` |
| ticket | `ANY fetch`; `POST reply`, `POST close` |
| coupon | `ANY fetch`; `POST generate`, `POST drop`, `POST show`, `POST update` |
| gift-card | `ANY templates`, `ANY codes`, `ANY usages`, `ANY statistics`, `GET types`; `POST create-template`, `POST update-template`, `POST delete-template`, `POST generate-codes`, `POST toggle-code`, `POST update-code`, `POST delete-code`; `GET export-codes` |
| knowledge | `GET fetch`, `GET getCategory`; `POST save`, `POST show`, `POST drop`, `POST sort` |
| payment | `GET fetch`, `GET getPaymentMethods`; `POST getPaymentForm`, `POST save`, `POST drop`, `POST show`, `POST sort` |
| system | `GET getSystemStatus`, `GET getQueueStats`, `GET getQueueWorkload`, `GET getQueueMasters`, `GET getHorizonFailedJobs` |
| theme | `GET getThemes`; `POST upload`, `POST delete`, `POST saveThemeConfig`, `POST getThemeConfig` |
| plugin | `GET types`, `GET getPlugins`, `GET config`; `POST upload`, `POST delete`, `POST install`, `POST uninstall`, `POST enable`, `POST disable`, `POST config`, `POST upgrade` |
| traffic-reset | `GET logs`, `GET stats`, `GET user/{userId}/history`; `POST reset-user` |

更新检查接口在当前版本中没有启用，不能把注释代码当作可用 API。

# 8090 前端 WordPress 兼容接口

以下路径由 `D:\BtSoft\nginx\conf\site-includes\user-dorocloud-8090\wp-route.conf` 映射到 8080 的 `/api/v1`。登录态仍使用 Xboard 的认证方式。

| 前端路径 | 后端路径 |
| --- | --- |
| `GET /wp-json/wp/v2/settings` | `/guest/comm/config` |
| `GET /wp-json/wp/v2/settings/user` | `/user/comm/config` |
| `GET /wp-json/wp/v2/users/me` | `/user/info` |
| `POST /wp-json/wp/v2/users/me/update` | `/user/update` |
| `POST /wp-json/wp/v2/users/me/password` | `/user/changePassword` |
| `GET /wp-json/wp/v2/users/me/security` | `/user/resetSecurity` |
| `GET /wp-json/wp/v2/users/me/subscription` | `/user/getSubscribe` |
| `GET /wp-json/wp/v2/users/me/stats` | `/user/getStat` |
| `GET /wp-json/wp/v2/users/me/traffic` | `/user/stat/getTrafficLog` |
| `GET /wp-json/wp/v2/notices` | `/user/notice/fetch` |
| `GET /wp-json/wp/v2/servers` | `/user/server/fetch` |
| `GET /wp-json/wp/v2/products`、`/products/detail` | `/user/plan/fetch` |
| `GET /wp-json/wp/v2/orders` | `/user/order/fetch` |
| `POST /wp-json/wp/v2/orders/new` | `/user/order/save` |
| `POST /wp-json/wp/v2/orders/detail` | `/user/order/detail` |
| `POST /wp-json/wp/v2/orders/check` | `/user/order/check` |
| `POST /wp-json/wp/v2/orders/cancel` | `/user/order/cancel` |
| `POST /wp-json/wp/v2/orders/checkout` | `/user/order/checkout` |
| `GET /wp-json/wp/v2/payment-methods` | `/user/order/getPaymentMethod` |
| `POST /wp-json/wp/v2/coupons/check` | `/user/coupon/check` |
| `GET /wp-json/wp/v2/support` | `/user/ticket/fetch` |
| `POST /wp-json/wp/v2/support/new` | `/user/ticket/save` |
| `GET /wp-json/wp/v2/support/detail` | `/user/ticket/fetch` |
| `POST /wp-json/wp/v2/support/reply` | `/user/ticket/reply` |
| `POST /wp-json/wp/v2/support/close` | `/user/ticket/close` |
| `POST /wp-json/wp/v2/support/withdraw` | `/user/ticket/withdraw` |
| `GET /wp-json/wp/v2/referrals` | `/user/invite/fetch` |
| `GET /wp-json/wp/v2/referrals/new` | `/user/invite/save` |
| `GET /wp-json/wp/v2/referrals/details` | `/user/invite/details` |
| `POST /wp-json/wp/v2/balance/transfer` | `/user/transfer` |
| `GET /wp-json/wp/v2/docs/detail`、`/docs/search` | `/user/knowledge/fetch` |
| `GET /wp-json/wp/v2/telegram/bot` | `/user/telegram/getBotInfo` |
| `POST /wp-json/wp/v2/users/login` | `/passport/auth/login` |
| `POST /wp-json/wp/v2/users/register` | `/passport/auth/register` |
| `POST /wp-json/wp/v2/users/lostpassword` | `/passport/auth/forget` |
| `GET /wp-json/wp/v2/users/token` | `/passport/auth/token2Login` |
| `POST /wp-json/wp/v2/users/verify-email` | `/passport/comm/sendEmailVerify` |
| `POST /wp-json/wp/v2/captcha/challenge` | `/guest/captcha/challenge` |
| `POST /wp-json/wp/v2/captcha/redeem` | `/guest/captcha/redeem` |

`GET /wp-json/` 返回兼容 WordPress 的 namespace 索引；未映射的 `/wp-json/*` 返回 `404 rest_no_route`。Telegram 登录路径当前明确返回 `501`，因为后端没有提供 Telegram 登录接口。

# Cap 验证码

Cap 是本机自建验证码，不依赖第三方验证码服务。完整流程：

```text
1. 前端请求 /guest/comm/config 或 /client/app/getConfig
2. 后端返回 captcha_type=cap、cap_site_key、cap_api_endpoint
3. 浏览器请求 POST /api/v1/guest/captcha/challenge
4. 浏览器在 WASM 中完成工作量证明
5. 浏览器请求 POST /api/v1/guest/captcha/redeem
6. 注册/发信接口提交 cap_token
7. Laravel 通过回环地址调用 Cap siteverify
8. token 原子消费，重复使用失败
```

公开接口：

```text
POST /api/v1/guest/captcha/challenge
POST /api/v1/guest/captcha/redeem
```

8090 同源兼容接口：

```text
POST /wp-json/wp/v2/captcha/challenge
POST /wp-json/wp/v2/captcha/redeem
```

8380 静态资源：

```text
GET /assets/cap/widget.js
GET /assets/cap/cap_wasm_bg.wasm
GET /assets/cap/hashwx.wasm
GET /assets/cap/pako_inflate.min.js
```

Cap 服务端配置文件 `windows/cap/private.json` 只存在于生产机，不提交到 GitHub。前端只能看到公开的 `cap_site_key`，不能看到 `proxySecret`、`verifySecret`、Redis 配置或私钥。

管理端 Cap 入口在：

```text
管理后台 -> 系统设置/验证码配置 -> 验证码类型 -> Cap（本机自建）
```

关闭验证码时，Cap 类型配置仍可查看；关闭不会强行开启验证码。

# V2bX / Hysteria2 适配

- Hysteria2 顶层 `server_name` 优先读取 `protocol_settings.tls.server_name`，为空才回退节点 host。
- 订阅输出保留 `tls_settings`、Masquerade、QUIC、混淆、带宽和端口跳跃字段。
- Shadowrocket Hysteria2 同时输出 `sni` 和兼容字段 `peer`，最低版本为 `2.2.35`。
- Hysteria2 端口跳跃下发 `ports` 和 `hop_interval`，监听端口与端口范围分开校验。
- 管理端 Hysteria/Hysteria2 表单的“证书域名（SNI）”保存到 `protocol_settings.tls.server_name`，与伪装站点 URL 独立。

相关文件：

```text
app/Protocols/Shadowrocket.php
app/Services/V2bX/NodeConfig.php
app/Services/ServerService.php
app/Support/Hysteria2PortHopping.php
resources/js/admin-v2bx.js
public/assets/admin/assets/index-v2bx-native.js
```

# WebSocket 和后台服务

节点实时同步 WebSocket 默认监听 `8076`：

```bash
php artisan ws-server start --host=0.0.0.0 --port=8076
```

Nginx 通过 `/ws/` 反向代理到 `127.0.0.1:8076`。生产环境的队列、调度、PHP、API-8380 和 Cap 守护由 Windows 脚本及计划任务管理，详见 `WINDOWS-README.md` 和 `windows/` 下的脚本。不要为了更新源码擅自重启生产服务。

# 部署和安全

- 生产 `.env`、Cap `private.json`、数据库密码、Redis 密码、服务 token、备份、日志和运行状态不上传。
- 8080 可以作为独立 API 网关，但公网账户密码和 token 必须通过 HTTPS 反向代理传输。
- 8380 默认只监听回环地址，不应直接暴露公网。
- 8090 的 `/wp-json/` 只是前端兼容映射；不要把真实 API 密钥写入 `env.js`。
- 修改 Laravel 代码后，若启用 Octane，需要按部署方式重启对应 Octane/PHP 服务；修改静态主题后需要刷新 `public/theme` 或部署静态文件并清理缓存。
- 管理后台路径、订阅路径、App URL 和支付回调地址均来自配置，不要在前端代码中硬编码生产密钥或完整订阅 token。

# 验证命令

```bash
php -l app/Protocols/Shadowrocket.php
php -l app/Services/V2bX/NodeConfig.php
php -l app/Services/ServerService.php
php -l app/Support/Hysteria2PortHopping.php
node --check resources/js/admin-v2bx.js
node --check public/assets/admin/assets/index-v2bx-native.js
python scripts/check-native-build.py
git diff --check
git status --short
```

## 文档索引

- [8080 独立 API 说明](windows/API-8080.md)
- [V2bX 适配说明](docs/V2BX-ADAPTATION.md)
- [Xboard / V2bX 迁移指南](docs/XBOARD-V2BX-MIGRATION.md)
- [8090 用户中心主题说明](sites/user.dorocloud.net/README.md)
- [Windows 部署说明](WINDOWS-README.md)

## 许可证和免责声明

本项目沿用 Xboard 的许可证和上游声明。项目仅用于学习、测试和合法的网络服务部署；使用者应自行遵守所在地法律法规、服务商条款和数据保护要求。
