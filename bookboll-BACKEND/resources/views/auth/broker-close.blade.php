{{--
  The last document the Google sign-in popup renders.

  Its real job is to close. The postMessage is only a shortcut so the storefront can
  stop its poll a beat early — it is deliberately not relied on, because after the
  round-trip through accounts.google.com `window.opener` is frequently severed by
  Cross-Origin-Opener-Policy and the message silently goes nowhere. The storefront's
  poll is the mechanism; this is the optimisation.

  $origin  the storefront that opened the popup, already validated, or null
  $message a failure to show if there is no sid to carry it home
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $message ?? null ? 'Sign-in failed' : 'Signing you in…' }}</title>
    <style>
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #0f172a; background: #f8fafc; text-align: center; padding: 24px;
        }
        .m { max-width: 320px; }
        .s { width: 28px; height: 28px; margin: 0 auto 14px; border: 3px solid #e2e8f0; border-top-color: #0f766e; border-radius: 50%; animation: spin .8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        p { margin: 0; color: #475569; }
        @media (prefers-color-scheme: dark) {
            body { background: #0f172a; color: #e2e8f0; }
            p { color: #94a3b8; }
            .s { border-color: #1e293b; border-top-color: #2dd4bf; }
        }
    </style>
</head>
<body>
    <div class="m">
        @if (! empty($message))
            <p>{{ $message }}</p>
            <p style="margin-top:10px">You can close this window.</p>
        @else
            <div class="s"></div>
            <p>Signing you in…</p>
        @endif
    </div>

    <script>
        (function () {
            @if (! empty($origin))
                try {
                    if (window.opener && !window.opener.closed) {
                        window.opener.postMessage({ source: 'bookboll-auth', done: true }, @json($origin));
                    }
                } catch (e) { /* opener severed by COOP — the poll covers it */ }
            @endif

            @if (empty($message))
                try { window.close() } catch (e) { /* ignore */ }
            @endif
        })();
    </script>
</body>
</html>
