# Xboard / V2bX 适配迁移指南

本文用于在另一台电脑的原版 Xboard 上复现当前适配。适配沿用原 Xboard 的登录、节点管理、用户管理、订阅和服务器管理入口，不需要新建独立站点。

## 固定版本

- Xboard 适配提交：`c51f5ec`（包含安装命令指向 `.20` 的修复）
- V2bX 主分支提交：`b4de78e376bc0096ce1eaf6f4e4e56327834d13e`
- V2bX 一键安装版本：`v0.1.0-core-upgrade.23`
- Xboard 原版基线：`d826a3b32f73bde24acce2a3ab033c6538ce121d`

## Xboard 安装与迁移

1. 备份目标 Xboard 的数据库、`.env`、`APP_KEY`、`public/assets/admin` 和 `theme/Xboard`。
2. 将适配源码包按原路径复制到 Xboard 根目录，允许覆盖同名文件。
3. 使用目标项目 `composer.lock` 安装 Composer 依赖，不升级依赖版本。
4. 执行数据库迁移和缓存清理：

   ```sh
   php artisan migrate --force
   php artisan optimize:clear
   ```

5. 如果使用 Xray 原生密钥生成按钮，在面板服务器配置：

   ```env
   V2BX_XRAY_BIN=/usr/local/bin/xray
   ```

   也可以使用 `XRAY_BIN`。配置必须进入 PHP-FPM/Octane 的运行环境，并重启对应进程。

## V2bX 节点安装

```sh
wget -N https://raw.githubusercontent.com/shmily2-1/V2bX-2/v0.1.0-core-upgrade.23/install.sh
bash install.sh
```

构建源码时使用：

```sh
go build -tags 'sing xray hysteria2 with_quic with_grpc with_utls with_wireguard with_acme with_gvisor' -o V2bX .
```

## 节点配置入口

### VLESS

在原“节点管理 → 新建/编辑节点 → VLESS”中：

- 选择 V2bX 后端后可以配置 VLESS Encryption、XDRIVE 和传输参数。
- 点击“按 Xray 生成成对参数”会调用 Xray 的 `vlessenc`，生成服务端 `decryption` 和客户端 `encryption`。
- TLS 选择 REALITY 后，可以点击“按 Xray 自动生成”，生成 X25519、Short ID、MLDSA65 Seed/Verify。
- 域名、Dest、端口、证书和 XDRIVE 存储凭据仍需手动填写。

VLESS Encryption、ML-KEM-768、MLDSA65 和 XDRIVE 仅适用于 Xray 后端；sing-box 后端应使用普通 VLESS、TLS/REALITY 和其支持的传输方式。

### Hysteria2

- 选择 Hysteria/Hysteria2 后端时会进入 V2bX HY2 配置。
- Salamander/Gecko 密码可以自动生成。
- Gecko 默认分片范围为 `512-1200`，可手动调整。
- UDP idle timeout、QUIC、Masquerade 继续按节点实际证书和客户端能力填写。

机器服务（Tunnel、TUN、WireGuard、AmneziaWG、MTProto）管理入口已停用。本次迁移保留历史数据库记录和节点端旧同步接口，不再提供新建、编辑或面板操作入口。

## 生成接口

管理员接口：

```text
POST /{secure_path}/server/manage/generateNodeKeys
```

支持的 `kind`：

- `vless_encryption`
- `x25519`
- `mlkem768`
- `mldsa65`
- `short_id`
- `hy2_password`
其中 `vless_encryption`、`mlkem768`、`mldsa65` 需要面板服务器能执行锁定版本的 Xray。X25519 和 HY2 密码可由面板本地生成。

## 验证命令

```sh
php artisan optimize:clear
php artisan about
python3 scripts/check-native-build.py
```

确认 Xray 生成器：

```sh
$V2BX_XRAY_BIN vlessenc
$V2BX_XRAY_BIN mldsa65
```

生产环境还需要使用实际域名、证书、端口和客户端做一次互通验证。压缩包不包含 `vendor/`、测试数据库、账号、Token 或生产配置。
