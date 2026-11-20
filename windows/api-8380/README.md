# 原版主题独立 API：8380（Windows / 宝塔）

部署日期：2026-11-06。此入口是纯 API，不展示主题，不替换现有站点。

## 1. 已安装内容与边界

- API 根地址：`http://127.0.0.1:8380`
- API Base：`http://127.0.0.1:8380/api/v1`
- 仅监听本机 IPv4 `127.0.0.1:8380`，默认供同机 FRPC / 反向代理使用。
- 根路径返回同源安全验证 HTML；`/health` 返回 JSON 服务状态，`theme_enabled=false`。
- 允许原生 `/api/v1/`、`/api/v2/` 下 guest、passport、user、client 命名空间，实际接口以现有 Xboard 路由为准；不开放节点端和后台管理 API。
- 不提供主题、后台 HTML、静态文件、直接 PHP 执行、WP 路径和 `/s/...` 短订阅路由。
- 复用现有 PHP 8.5 FastCGI `127.0.0.1:20085`；不启动第二套 PHP、数据库或队列。
- 8380 不改变原 80 / 8080 / 8090 的业务路由、主题、数据库设置、`.env`、APP_KEY、订阅地址或支付回调；原 80/8080 的 PHP FastCGI 已另行切换到 PHP 8.5。
- 没有在 Windows 防火墙中新增公网放行规则，没有安装或修改 FRPC。

**入口隔离不等于数据隔离。** 登录、订单、套餐、账号、订阅均属于原项目；从此入口购买会产生真实订单。流量仍使用原 PHP/Redis/数据库资源。PHP 或数据库停机时，本入口业务接口也会不可用；根路径可用并不代表后端健康。

## 2. 架构及文件

```text
原主题 / 其他原生 API 前端
    → HTTPS 公网入口 / FRP（自行配置）
    → 本机 127.0.0.1:8380（独立 nginx.exe）
    → 本机 127.0.0.1:20085（PHP 8.5 FastCGI）
    → 原 Xboard 代码、数据库、Redis、队列
```

安装位置：

```text
D:\wwwroot\dorocloud.net\10_API-8380-Manager.bat
D:\wwwroot\dorocloud.net\windows\api-8380\
  manage-api.ps1
  README.md
  runtime\nginx.exe              独立运行文件，不是宝塔 nginx_server 包装服务
  runtime\*.dll
  nginx\conf\nginx.conf          独立完整配置，不可 include 到原 Nginx
  nginx\conf\fastcgi-api.params
  nginx\logs\nginx.pid           独立 PID，不与原实例共用
  nginx\logs\access.log
  nginx\logs\error.log
  nginx\temp\
  logs\supervisor.log
  state\supervisor.json
  state\stop.flag                仅手动停止时存在
```

运行文件从本机现有 Nginx 复制，未升级/替换原 Nginx。日志、临时目录、PID、配置和守护任务都分离。服务目录仅 SYSTEM、Administrators 和当前管理员可管理，位于网站 public 根目录之外。

访问日志不记录查询串、Authorization、Cookie 或请求体；不要在 URL 路径内传递秘密。错误日志仍可能包含诊断上下文，只能在受控目录保存，不可公开。

## 3. 管理、重启、进程守护

双击：

```text
D:\wwwroot\dorocloud.net\10_API-8380-Manager.bat
```

菜单支持：启动、停止、**仅重启 8380**、状态、检查配置、开启/关闭开机自启。

非交互命令行（不暂停）：

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File D:\wwwroot\dorocloud.net\windows\api-8380\manage-api.ps1 -Action Status
powershell.exe -NoProfile -ExecutionPolicy Bypass -File D:\wwwroot\dorocloud.net\windows\api-8380\manage-api.ps1 -Action Test
powershell.exe -NoProfile -ExecutionPolicy Bypass -File D:\wwwroot\dorocloud.net\windows\api-8380\manage-api.ps1 -Action Start
powershell.exe -NoProfile -ExecutionPolicy Bypass -File D:\wwwroot\dorocloud.net\windows\api-8380\manage-api.ps1 -Action Stop
powershell.exe -NoProfile -ExecutionPolicy Bypass -File D:\wwwroot\dorocloud.net\windows\api-8380\manage-api.ps1 -Action Restart
```

启用或移除自启使用 `-Action EnableStartup` / `-Action DisableStartup`，需要管理员权限。

- 独立计划任务名：`Xboard-dorocloud.net-API8380`，SYSTEM 账户，开机延迟启动。
- 每约 3 秒检查运行状态；独立 Nginx 主进程退出后退避恢复，守护进程异常退出由计划任务重试。
- 启动/恢复只认本实例运行文件、专用 prefix、配置路径、PID 和启动时间；端口被未知进程占用时拒绝启动，不按端口/进程名杀进程。
- 手动停止会写维护标记，防止重启电脑后被误拉起；通过 Start 恢复。
- Stop 优先让守护以相同账户发出本实例 quit；兜底只终止经身份核验的本实例主/子进程。
- 8380 的 Restart **只处理此实例**，不调用 `Restart-Service nginx`，不碰 PHP、MySQL、Redis、队列或 Cap。
- 没有改写原 `03_Restart.bat` / `Xboard-Manager.bat`；它们不会管理新增入口。
- 同时不要再用 PM2、其他进程守护重复管理此实例。

`EntryHealthy=True` 仅代表 8380 根路径正常，不是数据库健康检查。修改独立 Nginx 配置后，先 Test，再通过此管理器 Restart，只影响 8380；不要用宝塔主服务重启按钮来管理它。

## 4. 原主题如何对接

### 4.1 当前原版主题实际行为：同源 API

当前 `theme/Xboard/assets/umi.js` 从 `window.routerBase` 与网页 origin 推导 `api/v1`，模板设置 `window.routerBase='/'`。也就是它默认请求：

```text
当前主题域名/api/v1/...
```

**它没有一个已核实的、独立可填的 API_HOST 配置项。** 不应为了指向 8380 直接把 routerBase 改成 API 域名，这会同时影响路由。此次没有改原主题或混淆 JS。

如果把原主题部署到一个新域名，推荐在那个前端站点内把 `/api/` 同源代理到 8380。示意补丁如下，仅在未来新前端站点中使用，不要无确认贴入当前 80/8090：

```nginx
location ^~ /api/ {
    # 不带尾部 URI，保持原 /api/v1/... 路径和查询串。
    proxy_pass http://127.0.0.1:8380;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_intercept_errors off;
}
```

上面假定前端 Nginx 本身终止 HTTPS。若 HTTPS 在 FRP 公网入口终止，应沿可信链路传递正确的协议头，不能让本地 HTTP 的 `$scheme` 覆盖掉真实 HTTPS；也不能无条件信任访客自填代理头。

**推荐结果：** 浏览器看见同源 HTTPS API，原主题和 Cap 桥接层的同源判断也能保持有效；新前端代理才连接本机 8380。

### 4.2 其他可配置 API 地址的前端

若你要对接的另一份主题明确支持 API Base 配置：

| 主题配置格式 | 填写方式 |
|---|---|
| 自动拼接 `/api/v1` | 填 API 域名根地址 |
| 要求完整 API Base | 填 API 域名加 `/api/v1` |

不要重复拼接路径。公开 HTTPS 前端不能直接调用 HTTP API，浏览器中的 `127.0.0.1` 是访客自己的电脑，不是这台服务器。

只在本机测试使用 `http://127.0.0.1:8380`；正式远程访问填写经 FRP 暴露并配置 HTTPS 的域名，而不是裸露本地 HTTP 端口。

## 5. FRP 对接

保留已有 FRPC 安装，不在项目内新增客户端。选择你的穿透配置，把所需隧道的本地目标设置为：

```text
localIP = 127.0.0.1
localPort = 8380
```

这些是目标字段说明，不是可直接粘贴到所有 FRP 版本的完整配置。FRP 具体字段、HTTPS 终止点和公网端口按你使用的版本与服务端配置为准。

如果 FRPC 不在同一台机器或容器中，127.0.0.1 不指向本 API，需要另外设计网络访问；当前不为此开放公网监听。

- 不要把 PHP 20085/20082、Cap 3010、Redis 6379 直接穿透。
- 公网入口覆盖不可信的 X-Forwarded-*，校验真实 IP 和协议；现有 Laravel 可信代理配置此次没有改动。
- API 公网入口建议限流并使用 HTTPS，不通过公网明文发送账号密码/令牌。
- 公网域名、证书、FRP 可达性和实际前端对接尚未验证，由你配置入口后再检查。

## 6. 接口与认证

下列路径相对于 `/api/v1`，具体字段以当前后端为准：

| 方法 | 路径 | 用途 / 注意 |
|---|---|---|
| GET | `/guest/comm/config` | 公开配置，无需登录 |
| GET | `/guest/plan/fetch` | 公开套餐 |
| POST | `/passport/auth/login` | 邮箱密码登录，会产生认证业务行为 |
| POST | `/passport/auth/register` | 注册，产生真实账号，可能要求 Cap/邮件验证码 |
| POST | `/passport/comm/sendEmailVerify` | 发送真实邮件，可能要求 Cap |
| GET | `/user/info` | 用户资料，要求 Authorization |
| GET | `/user/comm/config` | 用户配置 |
| GET | `/user/plan/fetch` | 用户可选套餐 |
| GET | `/user/getSubscribe` | 订阅资料 |
| GET | `/user/server/fetch` | 用户节点 |
| GET | `/user/order/fetch` | 订单列表 |
| POST | `/user/order/save` | 创建真实订单，不作为健康检查 |
| POST | `/user/order/checkout` | 发起真实支付，不作为健康检查 |
| POST | `/guest/captcha/challenge` | Cap 挑战，关闭时 404 |
| POST | `/guest/captcha/redeem` | Cap 兑换，关闭时 404 |

普通请求使用 `Accept: application/json`，JSON 请求加 `Content-Type: application/json`。认证接口按登录响应的 `auth_data` 和主题原逻辑传入 `Authorization`，不要擅自添加不同的 Bearer 格式，不把数据库账号或服务端密钥放进前端。

本入口使用 Laravel 现有 CORS，跨域预检 Authorization/Content-Type 已检查；不支持跨域 Cookie 凭据。原生主题建议同源代理；需要 Cookie 的其他前端不能直接套用本方案。

短订阅 `/s/...` 仍使用原服务返回的有效地址；不能把所有订阅/支付 URL 批量替换成 8380，也不要一律把订阅文件当 JSON。

## 7. Cap 兼容说明

本次保持部署前状态：`captcha_enable=0`，`captcha_type=cap`，没有调整开关或类型。关闭时公开 challenge/redeem 返回 404 属正常行为。

- API 层已复用现有 Cap PHP 分支和本机服务，不新增 Cap 密钥/服务。
- 内部 siteverify 不公开。
- 8380 是纯 API，不提供 cap-bridge.js / widget / WASM 静态资源；它们属于主题站点。
- 当前 Cap 桥接脚本仅拦截**同源**请求。若前端直接跨域调用 API 域名，仅 CORS 可用不足以保证以后开启 Cap 时可注册；推荐前端同源 `/api/` 代理到 8380。
- 如坚持不同域名直连，需单独适配前端的可信 API origin、配置获取、challenge/redeem 和 cap_token 注入，不能直接开启后假定所有请求都被桥接。
- 现有 8090 主题继续使用原 `/wp-json/wp/v2/` 转发，不需要为了本入口修改它。

## 8. 安全检查、状态和排查

无业务写入的检查示例：

```powershell
curl.exe -i http://127.0.0.1:8380/
curl.exe -i http://127.0.0.1:8380/api/v1/guest/comm/config
curl.exe -i http://127.0.0.1:8380/api/v1/guest/plan/fetch
curl.exe -i http://127.0.0.1:8380/api/v1/user/info
```

预期：根路径/公开接口 200，未登录用户接口 403；直接 PHP、主题、配置文件、节点 API、WP 路径等 404。目录不会被当静态站点开放。

部署时检查了公开配置与 8080 一致，跨域 OPTIONS 返回 204。没有提交登录、注册、发送邮件、订单或支付请求。

常见问题：

| 现象 | 排查 |
|---|---|
| 本机 8380 不通 | 先查看专用管理器 Status、计划任务、supervisor.log；不要重启原项目 |
| 端口被占用 | 管理器会拒绝抢占，查真实进程身份；不要 taskkill /IM nginx.exe |
| 根路径 200、接口 502 | PHP 8.5 FastCGI 20085 不可达；先核实，不自动重启宝塔服务 |
| 用户接口 403 | 未携带有效认证信息时正常 |
| 主题/静态文件 404 | 纯 API 入口的预期限制 |
| WP 路径 404 | 应使用原生 `/api/v1`，本入口不是 8090 主题的 WP 兼容入口 |
| 同机 FRP 正常、远端打不开 | 核查 FRP、公网 HTTPS 与域名；本入口仅监听本机 |
| 浏览器跨域 Cookie 错误 | 当前 CORS 不启用凭据；改用原主题 token 或同源代理，不全局改站点设置 |
| 将来开启 Cap 后失败 | 先检查前端是否同源、桥接资源和 cap_token，勿通过关闭后端校验来修复 |

## 9. 停用和回退

只停用新增入口：

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File D:\wwwroot\dorocloud.net\windows\api-8380\manage-api.ps1 -Action Stop
powershell.exe -NoProfile -ExecutionPolicy Bypass -File D:\wwwroot\dorocloud.net\windows\api-8380\manage-api.ps1 -Action DisableStartup
```

无需回滚数据库、主 Nginx 配置、原主题或 `.env`，因为本次没有改它们。先关闭前端/FRP 对此入口的依赖，再考虑清理新增文件；不要删除仍在使用的运行目录。

原项目自身的维护可能使 PHP 不可用，8380 仍依赖该后端；独立实例不代表能脱离原后端继续提供业务。日志保存在专用目录，按运维策略定期归档/轮转，不通过重启原项目清理。
## 8380 Cap 直连优化（2026-11-08）

原版 Xboard 模板现在在页面 head 中设置 `window.__XBOARD_API_BASE__` 为当前访问域名的 `8380` 端口，并把 `window.routerBase` 设为该 API 根地址。Cap bridge v1.1 支持这个独立 API origin：公开配置、challenge/redeem、注册和发送邮箱验证码可以跨端口通过 CORS 对接，受保护请求仍由桥接层补充 `cap_token`。

验证码开关仍由后台控制，不会因部署而自动开启。当前关闭时 challenge/redeem 返回 404 属正常状态。公网使用时必须让 FRP/HTTPS 为页面所在域名提供可达的 8380 API 入口；浏览器不能把 `127.0.0.1` 当作服务器地址使用。
