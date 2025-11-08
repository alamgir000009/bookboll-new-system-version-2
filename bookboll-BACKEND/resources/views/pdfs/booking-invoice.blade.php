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
            padding: 40px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 40px;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 20px;
        }

        .company-info {
            text-align: left;
        }

        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .company-details {
            color: #7f8c8d;
            font-size: 11px;
        }

        .invoice-meta {
            text-align: right;
        }

        .invoice-number {
            font-size: 18px;
            font-weight: bold;
            color: #34495e;
            margin-bottom: 10px;
        }

        .invoice-date {
            color: #7f8c8d;
        }

        .bill-to {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            margin-bottom: 30px;
        }

        .bill-to-section {
            flex: 1;
        }

        .bill-to-title {
            font-size: 14px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 10px;
            text-transform: uppercase;
        }

        .bill-to-details {
            color: #555;
        }

        .booking-details {
            margin-bottom: 30px;
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
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            font-size: 11px;
        }

        .booking-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
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
            float: right;
            width: 40%;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 12px;
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
            border-radius: 4px;
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
        <div style="width: 100%; margin-bottom: 20px;">
            <table style="width: 100%; border-collapse: collapse; border-spacing: 0;">
                <tr>
                    <td style="width: 60%; vertical-align: top; padding-right: 40px;">
                        <div style="font-weight: bold; font-size: 18px; margin-bottom: 5px;">Venue Booking Service</div>
                        <div style="line-height: 1.4; font-size: 12px;">
                            {{ \CompnayInfo::ADDRESS }}<br>
                            {{ \CompnayInfo::CITY }}, {{ \CompnayInfo::POSTAL_CODE }}<br>
                            Email: {{ \CompnayInfo::EMAIL }}<br>
                            Phone: {{ \CompnayInfo::PHONE }}
                        </div>
                    </td>
                    <td style="width: 40%; vertical-align: top; text-align: right;">
                        <div style="font-weight: bold; font-size: 14px; margin-bottom: 5px;">Invoice #{{ $invoiceNumber }}</div>
                        <div style="font-size: 12px; line-height: 1.4;">
                            Date: {{ now()->format('F d, Y') }}
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <div style="width: 100%; margin-bottom: 20px;">
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
                            #{{ $booking->reference_number }}<br>
                            {{ Carbon\Carbon::parse($booking->date)->format('F d, Y') }}<br>
                            {{ \Carbon\Carbon::parse($booking->start_time)->format('h:i A') }} -
                            {{ \Carbon\Carbon::parse($booking->end_time)->format('h:i A') }}
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <div class="booking-details">
            <div class="booking-title">Booking Details</div>
            <div class="booking-info">
                <div>
                    <div class="booking-row"><span>Venue:</span> <span>{{ $booking->venue->name ?? 'N/A' }}</span></div>
                    <div class="booking-row"><span>Listing:</span> <span>{{ $booking->listing->name ?? 'N/A' }}</span></div>
                </div>
                <div>
                    <div class="booking-row"><span>Date:</span> <span>{{ Carbon\Carbon::parse($booking->date)->format('F d, Y') }}</span></div>
                    <div class="booking-row"><span>Time:</span> <span>
                            {{ \Carbon\Carbon::parse($booking->start_time)->format('h:i A') }} To
                            {{ \Carbon\Carbon::parse($booking->end_time)->format('h:i A') }}</span></div>
                    <div class="booking-row"><span>Duration:</span> <span>{{ $booking->duration }} hours</span></div>
                </div>
            </div>
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
                    <td>${{ number_format($booking->subtotal - ($booking->addOns->sum('price') ?? 0), 2) }}</td>
                    <td class="price">${{ number_format($booking->subtotal - ($booking->addOns->sum('price') ?? 0), 2) }}</td>
                </tr>
                @if($booking->addOns && $booking->addOns->count() > 0)
                @foreach($booking->addOns as $addOn)
                <tr>
                    <td>{{ $addOn->name }} (Add-on)</td>
                    <td>1</td>
                    <td>${{ number_format($addOn->price, 2) }}</td>
                    <td class="price">${{ number_format($addOn->price, 2) }}</td>
                </tr>
                @endforeach
                @endif
            </tbody>
        </table>

        <div class="totals">
            @if($booking->discount_amount > 0)
            <div class="total-row">
                <span class="total-label">Subtotal</span>
                <span class="total-value">${{ number_format($booking->subtotal, 2) }}</span>
            </div>
            <div class="total-row highlight">
                <span class="total-label">Discount ({{ $booking->promocode_id ? $booking->promocode->code : 'Other' }})</span>
                <span class="total-value">- ${{ number_format($booking->discount_amount, 2) }}</span>
            </div>
            @endif
            <div class="total-row grand-total">
                <span class="total-label">Total</span>
                <span class="total-value">${{ number_format($booking->total_price, 2) }}</span>
            </div>
        </div>

        @if($booking->payment_breakdown && ($booking->payment_breakdown['deposit_amount'] ?? 0) > 0)
        <div class="payment-info">
            <strong>Payment Breakdown:</strong><br>
            Deposit Paid: ${{ number_format($booking->payment_breakdown['deposit_amount'], 2) }} (Online)<br>
            Remaining Due: ${{ number_format($booking->payment_breakdown['remaining_amount'], 2) }} (Cash on Arrival)
        </div>
        @else
        <div class="payment-info">
            <strong>Payment Status:</strong> {{ ucfirst(str_replace('_', ' ', $booking->payment_status)) }}<br>
            <strong>Method:</strong> {{ ucfirst(str_replace('_', ' ', $booking->payment_method)) }}
        </div>
        @endif

        <div class="footer">
            <p>Thank you for your booking! If you have any questions, please contact us at {{ \CompnayInfo::EMAIL }}.</p>
            <p>This is an electronic invoice. For terms and conditions, visit our website.</p>
        </div>
    </div>
</body>
</html>
