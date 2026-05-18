<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirming payment - {{ config('app.name', 'BookBoll') }}</title>
    <style>
        body {
            font-family: Inter, system-ui, sans-serif;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f3f4f6;
            color: #111827;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            padding: 40px 32px;
            max-width: 420px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,.08);
        }
        .spinner {
            width: 48px;
            height: 48px;
            border: 4px solid #e5e7eb;
            border-top-color: #4f46e5;
            border-radius: 50%;
            margin: 0 auto 20px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        p { color: #6b7280; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="card">
        <div class="spinner"></div>
        <h1>Confirming your payment</h1>
        <p id="status-text">Please wait while we confirm your booking.</p>
        <p style="font-size: 12px; margin-top: 16px;">You can close this window once confirmation completes.</p>
    </div>
    <script>
        (function () {
            var sessionId = @json($session_id ?? '');
            var statusUrl = @json(url('/payment/status')) + '/' + encodeURIComponent(sessionId);
            var successUrl = @json(url('/payment/success')) + '/' + encodeURIComponent(sessionId);

            function poll() {
                fetch(statusUrl, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.status === 'completed') {
                            window.location.href = successUrl;
                            return;
                        }
                        if (data.status === 'cancelled' || data.status === 'error') {
                            document.getElementById('status-text').textContent =
                                data.message || 'Payment could not be confirmed. Please contact support.';
                            return;
                        }
                        setTimeout(poll, 2500);
                    })
                    .catch(function () { setTimeout(poll, 3000); });
            }

            if (sessionId) {
                poll();
            } else {
                setTimeout(function () { window.location.reload(); }, 3000);
            }
        })();
    </script>
</body>
</html>
