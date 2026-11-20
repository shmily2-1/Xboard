# user.dorocloud.net（8090 用户中心主题）

本目录保存 `user.dorocloud.net` 的 8090 端口前端站点源码。它与 Xboard 主站的 `theme/Xboard` 是两个独立主题，不应互相覆盖。

## 站点用途

- 站点目录：`D:\wwwroot\user.dorocloud.net`
- 运行端口：8090（由外部 Nginx/站点配置负责监听和转发）
- 前端路由：WordPress 风格的 History 路由
- 后端接口：同源 `/wp-json/` 路径，由 Nginx 转发到本机 Xboard API
- 主题目录：`wp-content/themes/doro-notes`

## 目录说明

```text
index.html                              站点入口
404.html                                404 页面
feed/                                   Feed 页面
a-quiet-static-site/                    静态文章页
reading-notes-2026-autumn/              静态文章页
why-i-keep-a-plain-text-log/            静态文章页
wp-content/themes/doro-notes/           用户中心主题
wp-includes/wlwmanifest.xml             WordPress 兼容清单
robots.txt / sitemap.xml                搜索引擎文件
```

主题主要文件：

```text
wp-content/themes/doro-notes/env.js
wp-content/themes/doro-notes/style.css
wp-content/themes/doro-notes/assets/umi.js
wp-content/themes/doro-notes/assets/navigation.js
wp-content/themes/doro-notes/assets/theme-customizations.js
wp-content/themes/doro-notes/assets/theme-customizations.css
wp-content/themes/doro-notes/assets/cap/
wp-content/themes/doro-notes/assets/vendor/
```

## Cap 验证码

主题内包含浏览器端 Cap 资源和桥接脚本：

```text
wp-content/themes/doro-notes/assets/cap/cap-bridge.js
wp-content/themes/doro-notes/assets/cap/cap-bridge.css
wp-content/themes/doro-notes/assets/cap/widget.js
wp-content/themes/doro-notes/assets/cap/cap_wasm_bg.wasm
wp-content/themes/doro-notes/assets/cap/hashwx.wasm
```

Cap 的私钥、Redis 配置、代理密钥等服务端秘密不应放在此目录，也不应提交到 Git 仓库。主题中的 `env.js` 只能保存公开的前端配置，例如站点标题、资源路径和同源 API 路径。

## 部署注意事项

1. 本目录是 8090 站点的独立静态发布目录，不是 `theme/Xboard` 的替代品。
2. 修改 `env.js`、主题资源或入口 HTML 后，需要同步到 8090 站点目录并清理浏览器/CDN 缓存。
3. `/wp-json/` 的代理规则应继续由外部 Nginx 配置维护；不要把真实密钥写入前端文件。
4. `windows/cap/nginx-user-8090-cap.conf` 是 Xboard 项目中的 8090 Cap 代理配置片段，本目录只保存前端站点文件。
