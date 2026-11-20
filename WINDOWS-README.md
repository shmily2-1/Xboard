# Xboard Windows 10 / 11 宝塔版

## 1. 已完成的本机安装

- 程序目录：D:\wwwroot\dorocloud.net
- 宝塔站点的运行目录：D:\wwwroot\dorocloud.net\public（务必保留 public）
- 管理入口：D:\wwwroot\dorocloud.net\Xboard-Manager.bat
- 主程序源：cedar2025/Xboard，master，提交 d826a3b32f73bde24acce2a3ab033c6538ce121d（2026-11-02）。
- 管理界面资源：cedar2025/xboard-admin-dist，提交 ef5f43da335092cbff8fdf0ad7ff9b4d92d7d0d7。
- 适配版本：20261114-d826a3b-win3。
- 原项目的 MIT 许可证保留在程序根目录 LICENSE；这不是上游官方 Windows 发行版。
- 本机检测系统：Windows 11 企业版 64 位，10.0.22631。脚本按 Windows 10 / Windows PowerShell 5.1 编写，**没有在独立 Windows 10 机器上实测**。

运行环境沿用现有宝塔：Nginx 1.21.1、MySQL 5.7.38、Redis 5.0.10。当前 Xboard 原 80、8080、8380 的 Web FastCGI、Windows CLI、队列和调度均使用 PHP 8.5.12；8090 为独立静态/WP 兼容入口。
数据库 dorocloud_net / 用户 dorocloud_net 已连接并初始化；密码只保存在本机 .env 及其受保护的配置缓存/备份中，不写进 BAT 或可分发安装包。

## 2. 先在本机登录，公网域名尚未切换

本机用户页：http://127.0.0.1/

管理员入口带随机安全路径，账号及随机初始密码保存在单独的“管理员登录信息.txt”。请在本机阅读该文件，不要转发；首次登录后更改密码，然后安全删除或妥善保管该文件。

**公网状态（本次验收时）：dorocloud.net 解析到 154.219.103.62，并跳转至 https://www.dorocloud.net/ 的现有站点；本机新程序不在该公网入口后面。**

- 没有改动 DNS、现有公网网站、路由器或云防火墙。
- 不要把新管理员账号密码输入现有公网网站。
- 本机网络地址为 172.16.7.88，只有内网地址不能证明公网可达。
- 计划正式使用的 APP_URL 仍是 http://dorocloud.net；对外订阅、回调等功能必须等公网接入完成后再使用。
- 正式接入前确认公网 IP/端口映射/安全组和域名归属，避免覆盖现有业务。仅开放所需 80/443，**不要为此开放 MySQL 3306、Redis 6379、PHP 20085/20082**。
- 在宝塔配置有效 HTTPS 证书，完成跳转后，将 .env 的 APP_URL 与后台“网站地址”一起改为正式 HTTPS 地址；使用 Cookie 时将 SESSION_SECURE_COOKIE 设为 true；然后重建配置缓存并重启本应用。
- 邮件 SMTP、支付、套餐、节点、远端节点连通性尚未配置或测试。登录邮箱只是登录名，不代表已创建邮箱。

## 3. 日常快捷管理

右键 Xboard-Manager.bat → “以管理员身份运行”。本次已启用系统计划任务 Xboard-dorocloud.net 开机自启（SYSTEM，启动延迟 30 秒）。未重启整机验证开机触发；已经验证同一计划任务可启动和恢复后台。

| 菜单 | 功能 |
|---|---|
| 1 启动 | 检查依赖，启动 3 组队列，退出网站维护模式，执行/检查定时任务 |
| 2 停止 | 网站返回 503 维护页，等待当前任务结束，停止本应用后台 |
| 3 重启 | 先停止，再启动本应用 |
| 4 检查状态 | 检查 PHP、MySQL、Redis、安装状态、队列心跳与定时任务 |
| 5 备份 | 创建数据库 SQL、.env 与完整应用 ZIP，并保存 SHA-256 清单 |
| 6 重建配置缓存 | 重建配置和模板缓存，通知队列重载；不清空整个 Redis |
| 7 检测全部队列 | 投递不发邮件、不调用外网的测试任务，检查全部 9 个业务队列 |
| 8 查看后台日志 | 查看最近的后台管理/队列/定时任务日志 |
| 9 启用开机自启 | 注册本应用的 Windows 计划任务 |
| 10 取消开机自启 | 删除本应用计划任务，不停止当前运行实例 |
| 11 重设管理员密码 | 隐藏输入；仅重设已有管理员，不生成额外账号 |

也可使用根目录的 01_Start.bat、02_Stop.bat、03_Restart.bat、04_Status.bat、05_Backup.bat、06_Cache.bat、07_QueueTest.bat。
08_RepairDependencies.bat 只按 composer.lock 还原依赖，必须先进入维护模式，不会自动升级版本。

**不要在已安装的本机再次执行 00_Initialize.bat。** 它仅用于另一个空目录/空数据库的新安装，检测到已有配置或数据时会拒绝操作。

停止/重启只管理这个站点，不停止宝塔共享的 Nginx、MySQL、Redis。手动停止会留下 stop.flag，重启电脑后仍保持维护状态，需要手动“启动”恢复。
如果共享服务原本停止，“启动”会尝试启动它们；PHP-CGI 仍由宝塔原有服务管理。部署时为了应用 PHP ZIP 扩展和站点配置，曾重启一次 Nginx 宝塔服务。

## 4. Windows 适配内容

- HTTP 由现有 Nginx → 宝塔 PHP-CGI 处理，不运行 Swoole/Octane。
- Horizon 保留任务元数据/失败任务/统计功能，但不运行它依赖 pcntl/posix 的 Linux 进程管理器。
- 原生 Windows 后台管理器启动 Laravel queue:work，覆盖 9 个队列：
  - data：traffic_fetch、stat、user_alive_sync；
  - business：default、order_handle；
  - notification：send_email、send_telegram、send_email_mass、node_sync。
- 后台进程每 2 秒检查一次；通过进程 ID + 创建时间 + 站点完整路径避免误杀其他 PHP 程序；通过文件独占锁避免多开。
- Windows 缺少 pcntl，不能使用 Laravel 的 Unix 信号超时。因此使用工作进程心跳和外部超时检测，实际处理时间上限为任务声明的 timeout + 5 秒宽限，最高 120 秒；Redis 重试保留时间为 180 秒。
- 没有声明 timeout 的任务默认 60 秒。大批量导入/第三方插件需要更长时间时，必须同时评估并调整外部上限和 Redis retry_after，不能直接使用 Linux 的 timeout 参数。
- 队列是至少一次处理模型；工作进程强制终止后可能重试，业务任务仍需要幂等。
- 每分钟独立启动 schedule:run；Windows 下流量检查改为同步调度。单次调度最长 300 秒，超时记录日志并终止本应用对应子进程。大规模用户数据/第三方插件需重新压测。
- 后台系统状态、进程数量、队列工作量已接入 Windows 状态；Horizon 的自动扩缩容/Unix 信号控制不提供。
- Redis DB 8 用于业务队列/数据，DB 9 用于缓存，均采用本应用前缀；安装前确认这两个库为空。不要给其他业务共用这两个库。
- CLI 使用独立 windows\php-cli.ini，允许本地后台所需进程函数，不解除宝塔 Web PHP 的禁用函数列表。
- 宝塔 PHP 增加已有的 zip 扩展；没有下载来源不明的 PHP DLL。
- 程序依赖仍按 PHP 8.2 最低兼容约束固定在 composer.lock，生产实际已在 PHP 8.5.12 上通过语法与入口回归；审计结果见 windows\dependency-audit.json。审计无已知依赖漏洞不等于系统已完成全面安全审计。
- 网页一键升级已拒绝执行，防止 git reset 覆盖 Windows 补丁。远端节点的一键安装脚本仍是 Linux 脚本；这次适配的是面板，不包含将节点服务改造成 Windows 服务。

## 5. 配置位置和安全要求

- .env：数据库、Redis、应用密钥。不要删除或重新生成现有 APP_KEY，否则可能使加密数据/会话失效。
- windows\settings.json：PHP 路径、计划任务名、备份目录。
- windows\php-cli.ini：CLI 专用 PHP 配置，Windows PowerShell 子进程通过 PHPRC 继承。
- windows\nginx-baota.conf.example：这台机器已验证的 Nginx 配置示例。迁移时请保留新机器原有 SSL 等设置，不能盲目覆盖。
- storage\logs\windows：后台管理、队列、调度日志。各后台日志保留当前及上一轮文件；supervisor.log 超过 5 MB 滚动。
- storage\logs\laravel-日期.log：应用日志，可能含请求/业务信息，不要公开。

站点 root 必须指向 public，并保留 Laravel try_files 规则及敏感文件拦截；只允许 public/index.php 执行。已验证 .env、composer.json、windows/settings.json、主题 PHP 模板均返回 404。

站点根目录、备份目录和交付资料目录已限制为当前管理员/Administrators/SYSTEM 可访问。本机 PHP-CGI 以 SYSTEM 运行；若将来改服务账号，需要授予该账号合理的目录权限，不要直接开放 Everyone。

## 6. 备份与恢复

备份目录：D:\backup\Xboard-dorocloud.net

正常备份的子目录包含：
- database.sql：MySQL 单事务导出；
- environment.env：敏感配置，包含数据库密码及 APP_KEY；
- application.zip：应用文件（排除 .env、缓存、日志、运行状态）；
- manifest.json：文件大小与 SHA-256；
- COMPLETE：只有全部步骤成功才会生成。

没有 COMPLETE 的备份不能当作成功备份。备份不会自动上传，也没有自动删除/保留天数规则；需要自行监控磁盘空间。代码归档中包含依赖，可用于离线恢复。
本次已经实际生成备份、核对文件校验值，并检查 ZIP；**没有在另一个 MySQL 实例执行破坏性的完整还原演练**。

原宝塔配置备份保存在 preinstall-开头的子目录，含原站点 Nginx 配置与 PHP ini。

恢复前建议先备份当前状态并停站；在维护窗口由管理员操作：
1. 在空的暂存目录检查备份和 manifest，确认目标备份时间及数据库；
2. 恢复应用文件，并将 environment.env 复制为站点根目录 .env，保留其原 APP_KEY；
3. 使用宝塔导入 SQL 到明确选择的数据库。导入会覆盖同名数据，必须先确认，不提供一键无确认恢复按钮；
4. 核对 PHP/数据库路径、目录权限、Nginx public 根目录；
5. 重建配置缓存，启动本应用，运行状态与队列测试。

## 7. 新机器安装（不是本机重复安装）

1. 准备 Win10/11 64 位、Windows PowerShell 5.1、宝塔上述环境和空 MySQL 数据库；确认 Redis DB 8/9 未被占用，必要时调整 .env.windows.example。
2. 将不含密码的 Xboard-Windows10.zip 解压到 D:\wwwroot\dorocloud.net；包内已含 vendor 和前后台资源，无须现场构建前端。
3. 在宝塔建立站点，运行目录设为 /public，生产建议使用 PHP 8.5；项目最低兼容约束仍为 PHP 8.2。按 windows\nginx-baota.conf.example 合并 rewrite 和敏感文件规则。不要覆盖已有站点/证书。
4. 确认 PHP 扩展 pdo_mysql、redis、mbstring、openssl、curl、zip、fileinfo、bcmath、intl、sodium、sockets、xsl 已开启。Web PHP 的 zip 也需在宝塔启用并重新加载。
5. 若 PHP 路径或站点目录不同，先同步调整 windows\settings.json、Nginx 示例与 .env.windows.example 中的备份/mysqldump 路径。
6. 右键 00_Initialize.bat，以管理员身份运行。隐藏输入数据库密码和管理员邮箱；自动生成 APP_KEY 和随机管理员密码。只允许空数据库初始化。
7. 登录资料保存在受保护的备份目录，不写进 BAT。运行菜单 4 和 7，完成后再配置公网接入、HTTPS、SMTP、支付、套餐与节点。

当前 MySQL/Redis/Nginx 仍为用户指定环境；本次仅将 Xboard Web/CLI 切换到已安装的 PHP 8.5.12。正式承载公网业务前建议单独安排维护窗口进行受支持版本评估、备份与升级测试。

## 8. 升级说明

不要使用上游 init.sh / update.sh / 网页自动更新，不要直接 git reset --hard 或无评估 composer update。
正确顺序：完整备份 → 维护模式 → 在副本中合并上游改动和 windows 兼容文件 → 安装/固定依赖 → 数据迁移检查 → 回归测试 → 切换并启动。

重新安装锁定依赖仅豁免 ext-pcntl 和 ext-posix，这是 Horizon Linux 进程管理功能的依赖；其他平台要求不可全部忽略。修复脚本已固定这两个豁免。


## 9. V2bX-2 Hysteria2 端口跳跃兼容更新

本机已更新为 Windows 包 1.1.0，支持对方文档要求的 ports / hop_interval 节点配置下发，保留固定 server_port，并增加输入校验和 sing-box 默认间隔兼容。
详见 D:\wwwroot\dorocloud.net\windows\V2BX2-HYSTERIA2.md。面板仍在 Windows 运行；端口跳跃的 V2bX 节点须是 Linux，尚未实测远端 QUIC 和防火墙转发。
