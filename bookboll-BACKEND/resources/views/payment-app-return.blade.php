<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $status === 'success' ? 'Payment received' : 'Payment not completed' }} - {{ config('app.name', 'BookBoll') }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: #0f172a;
            color: #e2e8f0;
        }
        .card {
            width: 100%;
            max-width: 420px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 24px;
            padding: 36px 28px;
            text-align: center;
        }
        .icon {
            width: 72px; height: 72px; border-radius: 50%;
            margin: 0 auto 20px;
            display: flex; align-items: center; justify-content: center;
        }
        .icon.ok { background: rgba(16, 185, 129, 0.18); border: 2px solid rgba(16, 185, 129, 0.5); }
        .icon.no { background: rgba(245, 158, 11, 0.18); border: 2px solid rgba(245, 158, 11, 0.5); }
        .icon svg { width: 36px; height: 36px; }
        h1 { font-size: 22px; font-weight: 700; color: #f8fafc; margin-bottom: 8px; }
        p { font-size: 15px; line-height: 1.55; color: #94a3b8; }
        .btn {
            display: block; width: 100%;
            margin-top: 24px; padding: 15px 20px;
            border-radius: 14px; border: 0;
            font-size: 16px; font-weight: 700; text-decoration: none;
            background: #3b82f6; color: #fff;
        }
        .link { display: inline-block; margin-top: 18px; font-size: 13px; color: #94a3b8; text-decoration: underline; }
        .hint { margin-top: 14px; font-size: 12px; color: #64748b; }
    </style>
</head>
<body>
    <div class="card">
        @if($status === 'success')
            <div class="icon ok">
                <svg fill="none" stroke="#34d399" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
            </div>
            <h1>Payment received</h1>
            <p>Taking you back to the Bookboll app to see your confirmation.</p>
        @else
            <div class="icon no">
                <svg fill="none" stroke="#fbbf24" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"></path></svg>
            </div>
            <h1>Payment not completed</h1>
            <p>Nothing was charged. Return to the Bookboll app to try again.</p>
        @endif

        <a id="open-app" class="btn" href="{{ $appUrl }}">Open Bookboll app</a>
        <p class="hint">If the app does not open on its own, tap the button above.</p>
        <a class="link" href="{{ $websiteUrl }}">Continue on the website instead</a>
    </div>

    <script>
        (function () {
            var appUrl = @json($appUrl);
            var intentUrl = @json($intentUrl);
            var isAndroid = /android/i.test(navigator.userAgent);
            var button = document.getElementById('open-app');

            // Chrome on Android honours an intent:// URL where it may ignore a bare
            // custom scheme; everything else takes the scheme directly.
            var target = isAndroid ? intentUrl : appUrl;
            button.setAttribute('href', target);

            // Jump straight away; the button stays for browsers that insist on a tap.
            setTimeout(function () {
                try { window.location.href = target; } catch (e) { /* button remains */ }
            }, 150);
        })();
    </script>
</body>
</html>
