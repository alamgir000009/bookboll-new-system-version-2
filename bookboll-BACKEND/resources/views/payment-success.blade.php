<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Successful - {{ config('app.name', 'BookBoll') }}</title>

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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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

        .success-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
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

        .success-icon svg {
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

        .booking-info {
            background: #f9fafb;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
            text-align: left;
        }

        .booking-info h2 {
            font-size: 20px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 16px;
            text-align: center;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 500;
            color: #6b7280;
            font-size: 14px;
        }

        .info-value {
            font-weight: 600;
            color: #1f2937;
            font-size: 14px;
            text-align: right;
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

        .error-container {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 600px;
            width: 100%;
            padding: 48px;
            text-align: center;
        }

        .error-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }

        .error-icon svg {
            width: 48px;
            height: 48px;
            color: white;
        }

        @media (max-width: 640px) {
            .container, .error-container {
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
    @if(isset($error))
        <div class="error-container">
            <div class="error-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </div>
            <h1>Payment Verification Failed</h1>
            <p class="subtitle">{{ $error }}</p>
            <div class="actions" style="margin-top: 32px;">
                <a href="/" class="btn btn-primary">Go to Homepage</a>
            </div>
        </div>
    @else
        <div class="container">
            <div class="success-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>

            <h1>Payment Successful!</h1>
            <p class="subtitle">Thank you for your payment. Your booking has been confirmed and you will receive a confirmation email shortly.</p>

            @if(isset($bookings) && count($bookings) > 0)
                <div class="booking-info">
                    <h2>Booking Details</h2>
                    @foreach($bookings as $booking)
                        <div class="info-row">
                            <span class="info-label">Booking ID:</span>
                            <span class="info-value">{{ $booking->booking_id ?? 'N/A' }}</span>
                        </div>
                        @if($booking->venue)
                            <div class="info-row">
                                <span class="info-label">Venue:</span>
                                <span class="info-value">{{ $booking->venue->name }}</span>
                            </div>
                        @endif
                        @if($booking->listing)
                            <div class="info-row">
                                <span class="info-label">Listing:</span>
                                <span class="info-value">{{ $booking->listing->name }}</span>
                            </div>
                        @endif
                        @if($booking->date)
                            <div class="info-row">
                                <span class="info-label">Date:</span>
                                <span class="info-value">{{ date('M d, Y', strtotime($booking->date)) }}</span>
                            </div>
                        @endif
                        @if($booking->start_time && $booking->end_time)
                            <div class="info-row">
                                <span class="info-label">Time:</span>
                                <span class="info-value">{{ date('h:i A', strtotime($booking->start_time)) }} - {{ date('h:i A', strtotime($booking->end_time)) }}</span>
                            </div>
                        @endif
                        @if($booking->total_price)
                            <div class="info-row">
                                <span class="info-label">Amount Paid:</span>
                                <span class="info-value">QAR {{ number_format($booking->total_price, 2) }}</span>
                            </div>
                        @endif
                        @if(!$loop->last)
                            <hr style="margin: 16px 0; border: none; border-top: 2px solid #e5e7eb;">
                        @endif
                    @endforeach
                </div>
            @endif

            {{-- <div class="actions">
                <a href="/" class="btn btn-primary">Return to Homepage</a>
                @if(isset($bookings) && count($bookings) > 0)
                    <button onclick="window.print()" class="btn btn-secondary">Print Receipt</button>
                @endif
            </div> --}}
        </div>
    @endif
</body>
</html>

