# Xboard Windows：V2bX-2 / Hysteria2 端口跳跃兼容说明

## 本次结果

本机 D:\wwwroot\dorocloud.net 已应用兼容更新，版本为 **20260829-4f48e61-win2 / Windows 包 1.1.0**，无需重新安装或导入数据库。
现有管理员、数据库账号、APP_KEY、Windows 快捷管理 BAT 和后台任务保留；没有修改数据库表结构、节点记录、域名解析或防火墙。

兼容目标来自用户指定文档：
- https://github.com/shmily2-1/V2bX-2/blob/main/docs/HYSTERIA2-PORT-HOPPING.md
- 本次核对的 V2bX-2 提交：7906e9edc910d2d0c0c986cb46daf7431d64ad03。
- Xboard 基线：4f48e61a2cbc6db5338872b6bdb45ef954ec1256。
- 对方提供的原补丁已审查并纳入等价行为；额外增加后台输入校验和 sing-box 的 0/null 默认间隔处理。
- 仅表示对接这份文档的端口跳跃功能，不代表完整验证了该项目全部功能或未来版本。

## 1. 面板与节点的系统要求不同

**面板继续运行在这台 Windows 电脑上；负责 UDP 端口跳跃的 V2bX 节点须运行在 Linux 上。**
本次没有把 V2bX 端口跳跃服务改造成 Windows 节点程序，也没有在本机安装 nftables/iptables 或添加 UDP 规则。

按该项目的实现：客户端在公网 UDP 范围内跳跃，由 Linux 节点的专用 NAT 规则转发到同一个固定监听端口。节点不会绑定整个范围中的每个端口，也不会每隔几十秒更换服务端监听端口。

## 2. 在 Xboard 后台填写

在节点管理中选择 Hysteria，然后设置协议版本为 2。以下只是示例，未替你创建节点：

| 配置项 | 示例 | 含义 |
|---|---|---|
| 类型 | Hysteria | Xboard 内部仍是 hysteria |
| 协议版本 | 2 | 不要选 Hysteria1 |
| 节点地址 host | node.example.com | 换成真实 Linux 节点域名或地址 |
| 连接端口 port | 20000-30000 | 客户端公网 UDP 跳跃范围 |
| 后端端口 server_port | 8443 | V2bX 固定监听端口，只能是单个数字 |
| 跳跃间隔 protocol_settings.hop_interval | 30 | 秒；至少 5；留空/0 使用客户端默认值 |
| TLS 域名 | node.example.com | 必须与真实证书匹配 |
| 混淆 | 按实际设置 | 两端的 salamander 配置和密码必须一致 |

端口限制：1–65535，范围必须递增，例如 20000-30000。
**只支持单端口或一个 start-end 范围，不支持逗号列表、多段范围或冒号格式。**
后端会在保存节点之前拒绝错误输入，不能把 20000-30000 填在 server_port 中。

目前已有的节点和套餐没有被自动添加或改写；你仍需要设置权限组、用户套餐和节点分配。

## 3. Linux V2bX 配置

同目录的 V2bX2-Hysteria2节点配置示例.json 是占位示例，不含实际面板密钥。
在程序内也可找到 D:\wwwroot\dorocloud.net\windows\examples\v2bx2-hysteria2.json。

必须替换：
- ApiHost：Linux 节点能够访问的本机新 Xboard 面板正式 HTTPS 地址；
- ApiKey：新 Xboard 的节点通信密钥，不是管理员密码，也不是数据库密码；
- NodeID：后台实际创建的节点 ID，示例中的 1 不代表当前已有节点；
- CertDomain / CertFile / KeyFile：Linux 节点真实证书域名和文件路径。

**NodeType 保持 hysteria。** V2bX 根据 API 返回的 version=2 切换为 Hysteria2。
示例使用独立 hysteria2 内核；若选择 sing，将 Cores[0].Type 和 Nodes[0].Core 一起改为 sing。
不要把 ports 直接加到本地 Nodes 配置中代替面板设置；范围通过面板节点 API 下发。

ListenIP=0.0.0.0 是仅 IPv4 的示例；双栈监听及 IPv6 规则须单独按节点环境验收。

### 公网连接提醒

上次本机检查发现 dorocloud.net 会跳转到另一个现有网站，尚未证实该公网入口指向本机新面板。
**不能把 127.0.0.1 填为远端 Linux 节点的 ApiHost；那会指向 Linux 节点自己。**
接节点前先完成新面板的域名/端口映射/HTTPS，并确认返回的确实是本机 Xboard。
不要把新面板账号或节点密钥提交给现有的其他公网网站。

## 4. 节点 API 现在下发什么

V1 /api/v1/server/UniProxy/config 和 V2 /api/v2/server/config 共用已修复的配置生成方法。
原有鉴权保留，不能匿名获取节点配置。

示例响应片段：

~~~json
{
  "protocol": "hysteria",
  "version": 2,
  "server_port": 8443,
  "ports": "20000-30000",
  "hop_interval": 30,
  "base_config": { "push_interval": 60, "pull_interval": 60 }
}
~~~

- 固定监听端口保持 8443，不会变成字符串范围；
- ports 取当前节点自身的 port，不会误用父节点范围；
- 单端口节点返回 ports:null，不触发端口跳跃规则；
- TLS、混淆、带宽、推送/拉取间隔保留；
- 改变端口范围会改变 ETag，避免节点继续使用旧缓存；
- Hysteria1 和其他协议不增加这两个 Hysteria2 字段。

## 5. 订阅行为

本次回归验证：
- Clash Meta / Mihomo：ports=20000-30000，hop-interval=30；
- sing-box 1.11.0 及以后：server_ports=["20000:30000"]，hop_interval="30s"；
- sing-box 旧版本：保留现有版本门控，不下发不支持的跳跃字段；
- 通用 Hysteria2 链接：保留 mport 范围参数；各客户端对 URI 参数和跳跃间隔的实际支持仍需客户端验收。

额外修复：Hysteria2 的 hop_interval=0/null 时，sing-box 订阅不再输出 "0s"，而是省略间隔，让客户端使用默认值。
没有改变前端静态资源或增加新的管理页面；已有节点表单即可填写。

## 6. Linux 节点上线前检查

以下源于对方文档，本机未替你执行：
1. Linux 节点安装该 V2bX-2 分支的受信任构建，确认使用 hysteria2 或 sing 内核；
2. 节点有 nft 或 iptables，并具备 root/CAP_NET_ADMIN；非 Linux 收到非空 ports 会被该项目拒绝；
3. 云安全组/上游防火墙放行实际 UDP 跳跃范围，例如 UDP 20000-30000；仅放行 TCP 不够；
4. 主机过滤规则通常还须允许 NAT 后的 UDP 8443，不要全局清空防火墙；
5. 多节点范围不得重叠或占用其他 UDP 服务；容器网络命名空间与端口映射需单独规划；
6. 用真实域名和证书测试连接、端口跳跃、用户鉴权、流量入账、长连接和重载清理。

本机没有远端 Linux 节点访问资料或真实客户端，因此未声称完成 nftables/iptables 转发和 QUIC 实际跳跃测试。

## 7. 已完成的验收

- 已部署代码直接运行：70 项测试、136 个断言全部通过，无警告。
- 测试使用内存模型、真实配置构建与校验器；不启动生产应用、不连接 MySQL/Redis、不运行防火墙命令。
- 核对 V1/V2 路由的鉴权中间件、真实控制器输出和 ETag 变更。
- 本机首页与公共配置接口 HTTP 200，页面显示 win2 版本。
- 两个节点配置入口未提供凭据时保持 HTTP 422，不因兼容修改绕过鉴权。
- .env、windows 配置、测试 PHP 文件的网页访问仍被拦截。
- Windows 9 个业务队列往返测试全部通过，3 组进程和定时任务正常。
- 安装目录 .env 的校验值前后相同。

正式代码中的无数据库测试清单：D:\wwwroot\dorocloud.net\windows\compatibility-tests.xml。
生产程序包不额外捆绑 PHPUnit；需要复测时使用已验证的 PHPUnit 11 PHAR，命令示例（将 PHPUnit 路径替换为实际位置）：

~~~powershell
& 'D:\BtSoft\php\82\php.exe' -c 'D:\wwwroot\dorocloud.net\windows\php-cli.ini' 'D:\tools\phpunit-11.phar' --configuration 'D:\wwwroot\dorocloud.net\windows\compatibility-tests.xml'
~~~

只执行该清单；不要在生产数据库上随意运行原项目带 RefreshDatabase 的整套测试。

## 8. 升级包与回滚

- Xboard-Windows10.zip：已更新的完整新装包，不含本机 .env、APP_KEY、管理员密码或运行缓存；本机已经应用更新，不要重复初始化。
- Xboard-V2bX2-兼容补丁.patch：仅供人工审查及对照合并，针对之前的本机 win1 基线；会覆盖同名文件的旧内容，必须先检查本地差异。
- 兼容前完整备份：D:\backup\Xboard-dorocloud.net\20260920-034731-b3ddff。

升级旧包应先备份、停止网站、审查补丁并检查是否能干净应用，再清理配置缓存、启动并验收。不要解压覆盖现有 .env 或重新生成 APP_KEY。

回滚前先把已使用范围端口的节点改回单端口，让 V2bX 正常重载并确认其专用 NAT 规则清理。然后停站，在确认没有后续文件改动时反向应用补丁或从备份恢复对应代码，再重建缓存并启动。本次没有数据库迁移，无需为了回滚代码而覆盖整个数据库。
