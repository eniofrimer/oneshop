<!DOCTYPE html>
<html lang="hr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title') — OneShop</title>
    <style>
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; display:grid; align-items:center; background:#faf9f6; color:#28352b; font:16px/1.6 Arial,sans-serif; }
        main { width:min(620px,calc(100% - 40px)); margin:60px auto; }
        .brand { color:#28352b; font-size:26px; letter-spacing:-1px; text-decoration:none; }
        .brand span { font-weight:400; }
        .code { margin:55px 0 12px; color:#5f6b56; font-size:13px; letter-spacing:.12em; }
        h1 { font-size:clamp(32px,7vw,48px); line-height:1.15; font-weight:500; letter-spacing:-.035em; margin:0 0 24px; overflow-wrap:anywhere; }
        .message { color:#5f6b56; max-width:500px; margin-bottom:32px; }
        .button { display:inline-block; padding:13px 22px; background:#283c31; color:#fff; text-decoration:none; }
        .button:hover { background:#415444; }
        a:focus-visible { outline:3px solid #405a3c; outline-offset:5px; }
        .secondary { display:block; color:#405a3c; margin-top:24px; font-size:14px; }
    </style>
</head>
<body>
    <main>
        <a class="brand" href="/"><strong>one</strong><span>shop</span></a>
        <p class="code">@yield('code')</p>
        <h1>@yield('title')</h1>
        <p class="message">@yield('message')</p>
        <a class="button" href="/">Na početnu stranicu <span aria-hidden="true">↗</span></a>
        @yield('secondary')
    </main>
</body>
</html>
