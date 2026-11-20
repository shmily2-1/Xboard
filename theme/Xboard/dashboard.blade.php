<!doctype html>
<html lang="zh-CN">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no" />
  <title>{{$title}}</title>
  <link rel="stylesheet" href="/theme/{{$theme}}/assets/doro-plan-layout.css?v=20260920-2" />
  <script defer src="/theme/{{$theme}}/assets/doro-plan-layout.js?v=20260921-1"></script>
  <link rel="stylesheet" href="/assets/cap/cap-bridge.css?v=20261101-1" />
  <script src="/assets/cap/cap-bridge.js?v=20261108-1"></script>
  <script type="module" crossorigin src="/theme/{{$theme}}/assets/umi-native.js"></script>
</head>

<body>

  <script>
    window.routerBase = "/";
    window.settings = {
      title: '{{$title}}',
      assets_path: '/theme/{{$theme}}/assets',
      theme: {
        color: '{{ $theme_config['theme_color'] ?? "default" }}',
      },
      version: '{{$version}}',
      background_url: '{{$theme_config['background_url']}}',
      description: '{{$description}}',
      i18n: [
        'zh-CN',
        'en-US',
        'ja-JP',
        'vi-VN',
        'ko-KR',
        'zh-TW',
        'fa-IR'
      ],
      logo: '{{$logo}}'
    }
  </script>
  <div id="app"></div>
  {!! $theme_config['custom_html'] !!}
</body>

</html>
