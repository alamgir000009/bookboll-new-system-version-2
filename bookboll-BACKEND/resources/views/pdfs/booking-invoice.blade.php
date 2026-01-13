<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #{{ $invoiceNumber }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 12px;
            color: #333;
            line-height: 1.4;
            margin: 0;
            padding: 20px;
            background-color: #f9f9f9;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 20px;
        }


        .booking-details {
            margin-bottom: 10px;
        }

        .booking-title {
            font-size: 14px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 10px;
            text-transform: uppercase;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 5px;
        }

        .booking-info {
            font-size: 11px;
        }

        .booking-row {
            margin-bottom: 5px;
        }

        .booking-row table {
            width: 100%;
            border-collapse: collapse;
        }

        .booking-row td:first-child {
            width: 40%;
            font-weight: 600;
            color: #555;
        }

        .booking-row td:last-child {
            width: 60%;
            text-align: right;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .items-table th,
        .items-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        .items-table th {
            background-color: #f8f9fa;
            font-weight: bold;
            color: #2c3e50;
            text-transform: uppercase;
            font-size: 10px;
        }

        .items-table td {
            font-size: 11px;
        }

        .price {
            text-align: right;
            font-weight: bold;
        }

        .totals {
            width: 40%;
            margin-left: auto;
        }

        .total-row {
            margin-bottom: 10px;
            font-size: 12px;
        }

        .total-row table {
            width: 100%;
            border-collapse: collapse;
        }

        .total-row td:first-child {
            text-align: left;
        }

        .total-row td:last-child {
            text-align: right;
        }

        .total-label {
            color: #555;
        }

        .total-value {
            color: #2c3e50;
            font-weight: bold;
        }

        .grand-total {
            border-top: 2px solid #2c3e50;
            padding-top: 10px;
            font-size: 16px !important;
            color: #2c3e50 !important;
        }

        .payment-info {
            margin-top: 30px;
            padding: 15px;
            background-color: #f8f9fa;
            font-size: 11px;
            color: #555;
        }

        .footer {
            margin-top: 40px;
            text-align: center;
            color: #7f8c8d;
            font-size: 10px;
            border-top: 1px solid #e0e0e0;
            padding-top: 20px;
        }

        .highlight {
            color: #e74c3c;
        }

        @page {
            margin: 20px;
        }

    </style>
</head>
<body>
    <div class="container">
        <!-- Header with Bookbool Logo -->
        <div style="width: 96%; margin-bottom: 10px; background-color: #667eea; padding: 15px;">
            <table style="width: 100%; border-collapse: collapse; border-spacing: 0;">
                <tr>
                    <td style="width: 70%; vertical-align: middle;">
                        <table style="border-collapse: collapse; border-spacing: 0;">
                            <tr>
                                <td style="vertical-align: middle; padding-right: 12px;">
                                    <img src="{{ public_path('bookboll-logo.jpeg') }}" alt="Bookboll" style="height: 45px; width: auto;" onerror="this.style.display='none'">
                                </td>
                                <td style="vertical-align: middle;">
                                    <div style="font-weight: bold; font-size: 18px; color: #ffffff; margin-bottom: 3px;">{{ \CompnayInfo::NAME ?? 'Bookbool' }}</div>
                                    <div style="line-height: 1.4; font-size: 10px; color: #ffffff;">
                                        {{ \CompnayInfo::ADDRESS ?? '' }}<br>
                                        {{ \CompnayInfo::EMAIL ?? '' }}
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </td>
                    <td style="width: 30%; vertical-align: middle; text-align: right;">
                        <table style="border-collapse: collapse; border-spacing: 0; margin-left: auto;">
                            <tr>
                                <td style="background-color: #ffffff; padding: 8px 15px; text-align: center;">
                                    <div style="font-size: 8px; color: #667eea; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px;">Booking Invoice</div>
                                    <div style="font-weight: bold; font-size: 18px; color: #667eea;">#{{ $invoiceNumber }}</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </div>

        <div style="width: 100%; margin-bottom: 10px;">
            <table style="width: 100%; border-collapse: collapse; border-spacing: 0;">
                <tr>
                    <td style="width: 50%; vertical-align: top; padding-right: 20px;">
                        <div style="font-weight: bold; margin-bottom: 5px;">Bill To</div>
                        <div style="line-height: 1.4;">
                            {{ $booking->user->name ?? 'N/A' }}<br>
                            {{ $booking->user->email ?? 'N/A' }}<br>
                            Phone: {{ $booking->user->phone ?? 'N/A' }}
                        </div>
                    </td>
                    <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                        <div style="font-weight: bold; margin-bottom: 5px;">Booking #</div>
                        <div style="line-height: 1.4;">
                            #{{ $booking->booking_id }}<br>
                            {{ Carbon\Carbon::parse($booking->date)->format('F d, Y') }}<br>
                            {{ \Carbon\Carbon::parse($booking->start_time)->format('h:i A') }} -
                            {{ \Carbon\Carbon::parse($booking->end_time)->format('h:i A') }}
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <div class="booking-details">
            <div class="booking-title">Booking & Payment Details</div>
            <table style="width: 100%; border-collapse: collapse; border-spacing: 0;">
                <tr>
                    <!-- Left Side: Booking Details -->
                    <td style="width: 50%; vertical-align: top; padding-right: 20px;">
                        <div style="font-weight: bold; color: #2c3e50; margin-bottom: 8px; font-size: 11px; text-transform: uppercase;">Booking Information</div>
                        <div class="booking-row">
                            <table>
                                <tr>
                                    <td>Venue:</td>
                                    <td>{{ $booking?->venue->name ?? 'N/A' }}</td>
                                </tr>
                            </table>
                        </div>
                        @if($booking?->venue->maps_url)
                        <div class="booking-row">
                            <table>
                                <tr>
                                    <td>Map Link:</td>
                                    <td><a href="{{ $booking->venue->maps_url }}" target="_blank" style="color: #3498db; text-decoration: underline;">View Map</a></td>
                                </tr>
                            </table>
                        </div>
                        @endif
                        <div class="booking-row">
                            <table>
                                <tr>
                                    <td>Listing:</td>
                                    <td>{{ $booking?->listing->name ?? 'N/A' }}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="booking-row">
                            <table>
                                <tr>
                                    <td>Date:</td>
                                    <td>{{ Carbon\Carbon::parse($booking->date)->format('F d, Y') }}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="booking-row">
                            <table>
                                <tr>
                                    <td>Time:</td>
                                    <td>{{ \Carbon\Carbon::parse($booking->start_time)->format('h:i A') }} - {{ \Carbon\Carbon::parse($booking->end_time)->format('h:i A') }}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="booking-row">
                            <table>
                                <tr>
                                    <td>Duration:</td>
                                    <td>{{ $booking->duration ?? 0 }} hours</td>
                                </tr>
                            </table>
                        </div>
                        @if($booking->status)
                        <div class="booking-row">
                            <table>
                                <tr>
                                    <td>Status:</td>
                                    <td style="font-weight: 600; color: {{ $booking->status === 'confirmed' ? '#27ae60' : ($booking->status === 'pending' ? '#f39c12' : '#e74c3c') }};">{{ ucfirst($booking->status) }}</td>
                                </tr>
                            </table>
                        </div>
                        @endif
                    </td>
                    <!-- Right Side: Payment Details -->
                    <td style="width: 50%; vertical-align: top; padding-left: 20px;">
                        <div style="font-weight: bold; color: #2c3e50; margin-bottom: 8px; font-size: 11px; text-transform: uppercase;">Payment Information</div>
                        @if($booking->payments && $booking->payments->count() > 0)
                        @foreach($booking->payments as $index => $payment)
                        <div class="booking-row">
                            <table>
                                <tr>
                                    <td>Payment ID:</td>
                                    <td>{{ $payment->payment_id ?? 'N/A' }}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="booking-row">
                            <table>
                                <tr>
                                    <td>Amount:</td>
                                    <td>QAR {{ number_format($payment->amount ?? 0, 2) }}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="booking-row">
                            <table>
                                <tr>
                                    <td>Type:</td>
                                    <td>{{ ucfirst(str_replace('_', ' ', $payment->type ?? 'Full')) }}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="booking-row">
                            <table>
                                <tr>
                                    <td>Payment Method:</td>
                                    <td>{{ ucfirst(str_replace('_', ' ', $payment->method ?? 'Cash')) }}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="booking-row">
                            <table>
                                <tr>
                                    <td>Status:</td>
                                    <td style="font-weight: 600; color: {{ $payment->status === 'paid' ? '#27ae60' : ($payment->status === 'pending' ? '#f39c12' : '#e74c3c') }};">{{ ucfirst($payment->status ?? 'N/A') }}</td>
                                </tr>
                            </table>
                        </div>
                        @if($payment->date)
                        <div class="booking-row">
                            <table>
                                <tr>
                                    <td>Date:</td>
                                    <td>{{ \Carbon\Carbon::parse($payment->date)->format('F d, Y') }}</td>
                                </tr>
                            </table>
                        </div>
                        @endif
                        @if(!$loop->last)
                        <div style="margin: 8px 0; border-top: 1px solid #e0e0e0;"></div>
                        @endif
                        @endforeach
                        @else
                        <div class="booking-row">
                            <table>
                                <tr>
                                    <td>Payment Status:</td>
                                    <td style="font-weight: 600; color: {{ $booking->payment_status === 'fully_paid' ? '#27ae60' : ($booking->payment_status === 'pending' ? '#f39c12' : '#e74c3c') }};">{{ ucfirst(str_replace('_', ' ', $booking->payment_status ?? 'Pending')) }}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="booking-row">
                            <table>
                                <tr>
                                    <td>Payment Method:</td>
                                    <td>{{ ucfirst(str_replace('_', ' ', $booking->payment_method ?? 'N/A')) }}</td>
                                </tr>
                            </table>
                        </div>
                        @endif
                    </td>
                </tr>
            </table>
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="width: 100px;">Qty</th>
                    <th style="width: 120px;">Unit Price</th>
                    <th style="width: 100px;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $booking->listing->name ?? 'Booking Slot' }}</td>
                    <td>1</td>
                    <td>QAR {{ number_format($booking->subtotal - ($booking->addOns->sum('price') ?? 0), 2) }}</td>
                    <td class="price">QAR {{ number_format($booking->subtotal - ($booking->addOns->sum('price') ?? 0), 2) }}</td>
                </tr>
                @if($booking->addOns && $booking->addOns->count() > 0)
                @foreach($booking->addOns as $addOn)
                <tr>
                    <td>{{ $addOn->name }} (Add-on)</td>
                    <td>1</td>
                    <td>QAR {{ number_format($addOn->price, 2) }}</td>
                    <td class="price">QAR {{ number_format($addOn->price, 2) }}</td>
                </tr>
                @endforeach
                @endif
            </tbody>
        </table>

        <div class="totals">
            @if($booking->discount_amount > 0)
            <div class="total-row">
                <table>
                    <tr>
                        <td class="total-label">Subtotal</td>
                        <td class="total-value">QAR {{ number_format($booking->subtotal, 2) }}</td>
                    </tr>
                </table>
            </div>
            <div class="total-row highlight">
                <table>
                    <tr>
                        <td class="total-label">Discount ({{ $booking->promocode_id ? $booking->promocode->code : 'Other' }})</td>
                        <td class="total-value">- QAR {{ number_format($booking->discount_amount, 2) }}</td>
                    </tr>
                </table>
            </div>
            @endif
            <div class="total-row grand-total">
                <table>
                    <tr>
                        <td class="total-label">Total</td>
                        <td class="total-value">QAR {{ number_format($booking->total_price, 2) }}</td>
                    </tr>
                </table>
            </div>
        </div>

        @if($booking->payment_breakdown && ($booking->payment_breakdown['deposit_amount'] ?? 0) > 0)
        <div class="payment-info">
            <strong>Payment Breakdown:</strong><br>
            Deposit Paid: QAR {{ number_format($booking->payment_breakdown['deposit_amount'], 2) }} (Online)<br>
            Remaining Due: QAR {{ number_format($booking->payment_breakdown['remaining_amount'], 2) }} (Cash on Arrival)
        </div>
        @else
        <div class="payment-info">
            <strong>Payment Status:</strong> {{ ucfirst(str_replace('_', ' ', $booking->payment_status)) }}<br>
            <strong>Method:</strong> {{ ucfirst(str_replace('_', ' ', $booking->payment_method)) }}
        </div>
        @endif

        {{-- <div class="footer">
            <p>Thank you for your booking! If you have any questions, please contact us at {{ \CompnayInfo::EMAIL }}.</p>
            <p>This is an electronic invoice. For terms and conditions, visit our website.</p>
        </div> --}}
    </div>
</body>
</html>
