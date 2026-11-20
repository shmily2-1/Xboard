// 统一配置入口：后端地址、网站外观、主题色和客服均在本文件修改。
// 网站外观配置：修改后刷新即可，不需要重新打包。
// 图片可填写 HTTPS 地址或本站相对地址，例如 ./assets/images/logo.png。
// 本文件公开可读，不要填写账号、密码、令牌等私密信息。
window.siteConfig = {
  theme_color: 'blue',   // 原主题配色：default 默认绿色、blue 蓝色、black 黑色、darkblue 暗蓝色
  title: 'DORO加速器',       // 网站名称：浏览器标题、登录页、侧栏
  description: '🎉以卓越的性能，为您连接世界的每一个角落🎉',   // 登录页副标题；不需要时填写空字符串
  logo: '',                 // 网站 Logo；留空显示网站名称
  background_url: 'https://console.hizui.cn/bg2.838b225a.webp',       // 登录/注册/找回密码页面背景；留空使用原背景
  favicon_url: '',          // 浏览器图标；留空时使用 Logo
}

// 同源接口：浏览器只请求 /wp-json/ 站点自身路径，由 Nginx 转发到后端，不出现跨域。
// 站点路由位于 Nginx 配置目录之外的专用 include 文件；后端由本机 Nginx 代理到 127.0.0.1:8080。
window.apiBase = '/'

// 全部页面使用 WordPress 风格永久链接，不再使用原项目路径或 #/。
// 子目录部署请修改 index.html 中的 <base href="/子目录/">。
window.routeMode = 'history'
window.routerBase = new URL(document.baseURI).pathname

// 内部运行设置；网站外观统一在上方 window.siteConfig 中修改。
window.settings = {
  assets_path: '/wp-content/themes/doro-notes/assets',
  version: '1.6.1-wp-20261024-233652',
}

window.GoCustomerServiceConfig = {
  'serverUrl': 'https://kfmini.hizui.cn',
  'groupKey': '3ivj5kmk'
}
