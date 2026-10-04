<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Multi-Agent Book Writer')</title>
    <style>
        :root {
            --ink: #16181d;
            --muted: #626873;
            --line: #e3e6ea;
            --bg: #f7f8fa;
            --card: #ffffff;
            --accent: #2b6cb0;
            --pass: #1a7f4b;
            --fail: #b42318;
            --warn: #b54708;
        }
        * { box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            color: var(--ink); background: var(--bg); margin: 0;
            line-height: 1.6;
        }
        .wrap { max-width: 60rem; margin: 0 auto; padding: 2rem 1.25rem 4rem; }
        header.site {
            background: var(--card); border-bottom: 1px solid var(--line);
            padding: 1.25rem 1.25rem; margin-bottom: 2rem;
        }
        header.site .inner { max-width: 60rem; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
        h1 { font-size: 1.35rem; margin: 0; }
        h2 { font-size: 1.15rem; margin: 2rem 0 0.75rem; }
        .card {
            background: var(--card); border: 1px solid var(--line);
            border-radius: 8px; padding: 1.5rem; margin-bottom: 1.5rem;
        }
        label { display: block; font-weight: 600; font-size: 0.9rem; margin-bottom: 0.35rem; }
        .hint { font-weight: 400; color: var(--muted); font-size: 0.82rem; margin-top: 0.25rem; }
        input[type=text] {
            width: 100%; padding: 0.6rem 0.7rem; border: 1px solid var(--line);
            border-radius: 6px; font-size: 0.95rem; font-family: inherit;
        }
        input[type=text]:focus { outline: 2px solid var(--accent); outline-offset: 1px; border-color: var(--accent); }
        .field { margin-bottom: 1.1rem; }
        button {
            background: var(--accent); color: #fff; border: 0; border-radius: 6px;
            padding: 0.65rem 1.2rem; font-size: 0.95rem; font-weight: 600; cursor: pointer;
        }
        button:hover { background: #245a94; }
        button.secondary { background: #fff; color: var(--muted); border: 1px solid var(--line); }
        .notice { padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1.5rem; font-size: 0.92rem; }
        .notice.ok { background: #e7f5ee; color: var(--pass); }
        .notice.err { background: #fdeceb; color: var(--fail); }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        th, td { text-align: left; padding: 0.55rem 0.5rem; border-bottom: 1px solid var(--line); }
        th { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); }
        .badge { display: inline-block; padding: 0.15rem 0.5rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700; }
        .badge.pass { background: #e7f5ee; color: var(--pass); }
        .badge.fail { background: #fdeceb; color: var(--fail); }
        .badge.warn { background: #fdf3e7; color: var(--warn); }
        .badge.run { background: #eaf1f8; color: var(--accent); }
        .prose { font-family: Georgia, serif; font-size: 1.03rem; }
        .prose p { margin: 0 0 1.05rem; }
        .takeaway { background: #f2f6fb; border-left: 4px solid var(--accent); padding: 0.85rem 1rem; border-radius: 0 6px 6px 0; margin: 1.75rem 0; }
        .refs { font-size: 0.86rem; color: #333; }
        .refs ol { padding-left: 1.25rem; }
        .refs li { margin-bottom: 0.4rem; word-break: break-word; }
        .refs a { color: var(--accent); }
        details { margin-bottom: 0.75rem; border: 1px solid var(--line); border-radius: 6px; background: #fff; }
        summary { padding: 0.7rem 1rem; cursor: pointer; font-weight: 600; font-size: 0.92rem; }
        details .body { padding: 0 1rem 1rem; }
        .log { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 0.8rem; max-height: 24rem; overflow-y: auto; background: #fbfcfd; border: 1px solid var(--line); border-radius: 6px; padding: 0.75rem; }
        .log div { padding: 0.12rem 0; border-bottom: 1px solid #f0f2f4; }
        .log .msg { color: var(--ink); }
        .log .at { color: var(--muted); margin-right: 0.5rem; }
        .empty { color: var(--muted); font-style: italic; }
        a { color: var(--accent); }
        .row { display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: center; }
        .muted { color: var(--muted); font-size: 0.88rem; }
    </style>
</head>
<body>
<header class="site">
    <div class="inner">
        <h1>Multi-Agent Book Writer</h1>
        <a class="muted" href="{{ route('book.index') }}">New book</a>
    </div>
</header>

<div class="wrap">
    @if (session('status'))
        <div class="notice ok">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="notice err">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="notice err">
            <strong>Please fix the following:</strong>
            <ul style="margin: 0.5rem 0 0; padding-left: 1.25rem;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</div>
</body>
</html>