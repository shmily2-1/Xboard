# Xboard Windows Cap 适配（1.0.0）

## 当前状态与约束

- 验证码仍关闭，原验证码类型未改变。2026-11-01 经用户授权应用 8090 映射；平滑重载被 Windows 权限拒绝后重启 Nginx。宝塔 nginx_server 同时重建了其管理的 PHP-CGI 子进程；MySQL、Redis、原队列和 Cap 服务未重启。
- 本机服务只监听 127.0.0.1:3010，不应通过 FRP 直接暴露此端口。
- 后台“系统设置 → 安全设置”新增 Cap（本机自建），并允许在验证码关闭时先选择/配置类型。密钥自动管理，无需填写。
- 注册和发送邮箱验证码各消费一个新令牌；普通密码登录维持原行为，不增加验证码。
- 新旧用户前端均有独立可读适配文件，第三方主题的混淆主包未改动。

## 管理

项目根目录运行 09_Cap-Manager.bat：启动、停止、状态、开机守护开关。
只控制本 Cap 适配服务，不调用原项目的重启/停止脚本。

命令行：

    powershell.exe -NoProfile -ExecutionPolicy Bypass -File D:\wwwroot\dorocloud.net\windows\cap\manage-cap.ps1 -Action Status

Start 启动独立守护程序；Stop 写维护标记并只结束经 PID、启动时间、可执行文件和命令行核验的本服务。
EnableStartup 注册独立任务 Xboard-dorocloud.net-Cap；不会修改原 Xboard-dorocloud.net 任务。
守护每三秒检查状态，进程意外退出后退避重启；Redis 暂不可达时客户端重连，不重启 Redis。

## 已应用：8090 主题的 Nginx 配置

2026-11-01 已经用户授权，在 D:\BtSoft\nginx\conf\vhost\user-dorocloud-8090.conf 的 server 块内加入：

    include D:/wwwroot/dorocloud.net/windows/cap/nginx-user-8090-cap.conf;

Nginx 配置测试通过；平滑重载因 OpenEvent Access is denied 失败，随后按授权重启 nginx 服务并生效。
注意：宝塔 nginx_server 包装服务也管理 PHP-CGI，重启它会同时重新拉起 PHP 工作进程。MySQL、Redis、队列及 Cap 未重启。
8090 的验证码入口已转发到后端：GET 返回405，关闭验证码时 POST challenge/redeem 返回 JSON 404，内部 siteverify 不公开。
不要为了验证路由而打开正式验证码；未来重载/重启仍须按用户授权执行。

前端公开地址为同源 /wp-json/wp/v2/captcha/；原端口 80 前端使用 /api/v1/guest/captcha/。
只放行 POST challenge/redeem；不能把内部 siteverify、private.json、健康端口或 Redis 暴露到公网。
代理必须取得可信入口提供的真实访客 IP；公网入口应覆盖而不是盲信访客自填的 X-Forwarded-For。否则限流可能按同一个穿透 IP 合并，或被伪造来源绕过。

## 将来启用

1. 确认管理 BAT 显示 Cap Healthy=True、SupervisorRunning=True。
2. 8090 同源路由已应用，确认后续配置未移除该 include。
3. 后台在关闭状态选择 Cap，保存。
4. 确认所有正在使用的前端已刷新到带 cap-bridge 的版本，再由用户主动启用验证码。
5. 检查发送邮箱验证码、注册、手机布局、取消/重试等完整流程。

当前验证码关闭时公开 challenge/redeem 返回 404，这是预期行为，安装测试不会为此打开正式开关。
没有使用外部 CDN；widget、两份 WASM 和解压兼容脚本都随项目自托管。

## 密钥与依赖

private.json 包含 Redis 连接信息和三个独立密钥（代理认证、令牌核验、挑战签名），只允许本机管理员/SYSTEM 访问，位于 public 网站根目录之外。不得提交 Git、发给浏览器、放进公开下载包或粘贴到聊天。
不要单独删除或重建它；更换密钥会使尚未完成的挑战/令牌失效。迁移时通过受保护方式一起迁移。

固定依赖：Node.js v24.19.0；capjs-core 0.1.6；cap-widget 0.1.58；@cap.js/wasm 0.0.8；redis 4.7.1；pako 2.1.0。pnpm-lock.yaml 保存完整解析版本与完整性摘要。
项目为自定义 Node 适配层，不是直接运行当前 Cap Standalone。令牌一次性消费用 Redis 5 兼容 Lua 原子 GET+DEL；禁止改为分开的 GET、DEL，也不使用 GETDEL。
Redis 键独立命名空间 dorocloud_cap_v1:，都有过期时间；绝不 FLUSHDB/FLUSHALL。
服务校验失败、不可达或响应畸形时拒绝受保护请求，不降级放行。请求超时不自动重试 siteverify，以免重复消费令牌。

## 本次测试

- Node：官方 WASM 解题、兑换/核验、防挑战重放、20 并发恰好一次成功、错误/过期输入、限流、浏览器探测挑战生成。
- PHP：独立 CLI 内存设置和假 HTTP，22 个断言；没有数据库设置写入。
- 浏览器：隔离夹具里的原站/WP 主题路径，Fetch 和 XHR 共四次真实解题与服务端核验；390px 手机布局；关闭状态无验证码；外部资源请求为零。
- 浏览器夹具不在正式站点发布，没有创建真实账号、发送邮件或开启正式验证码。

## 升级/回滚

先关闭验证码（如果未来已经开启），再回滚业务源码。安装前对应文件备份位于本次工作目录 backup。
原后台 JS 文件未覆盖，manifest 可切回原 entry；新增 bridge 独立文件容易移除。
不要回滚数据库、APP_KEY 或整体 .env，也不要清空 Redis。
Cap 不启用时新增文件可保留；如果退役，只停止/移除本 Cap 任务，不动其他项目服务。
