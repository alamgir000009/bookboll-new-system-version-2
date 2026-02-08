<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Cancelled - {{ config('app.name', 'BookBoll') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #0b28f5 0%, #06d9ad 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 600px;
            width: 100%;
            padding: 48px;
            text-align: center;
            animation: slideUp 0.6s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .cancel-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            animation: scaleIn 0.5s ease-out 0.2s both;
        }

        @keyframes scaleIn {
            from {
                transform: scale(0);
            }
            to {
                transform: scale(1);
            }
        }

        .cancel-icon svg {
            width: 48px;
            height: 48px;
            color: white;
        }

        h1 {
            font-size: 32px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 12px;
        }

        .subtitle {
            font-size: 18px;
            color: #6b7280;
            margin-bottom: 32px;
            line-height: 1.6;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-direction: column;
        }

        .btn {
            padding: 14px 28px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 16px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }

        .btn-secondary:hover {
            background: #e5e7eb;
        }

        @media (max-width: 640px) {
            .container {
                padding: 32px 24px;
            }

            h1 {
                font-size: 24px;
            }

            .subtitle {
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="cancel-icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </div>

        <h1>Payment Cancelled</h1>
        @if(isset($isAdminPayment) && $isAdminPayment)
            <p class="subtitle">The payment process was cancelled. Your booking is still active. You can try the payment again using the button below.</p>
        @else
            <p class="subtitle">Your payment was cancelled. Your booking has been cancelled as well. If you would like to complete your booking, please try again.</p>
        @endif

        <div class="actions">
            @if(isset($isAdminPayment) && $isAdminPayment && isset($paymentLinkUrl) && $paymentLinkUrl)
                <a href="{{ $paymentLinkUrl }}" class="btn btn-primary">Try Again</a>
            @else
                <a href="/" class="btn btn-primary">Return to Homepage</a>
            @endif
            <a href="javascript:history.back()" class="btn btn-secondary">Go Back</a>
        </div>
    </div>
</body>
</html>
