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
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
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
        <div class="header">
            <div class="company-info">
                <div class="company-name">Venue Booking Service</div>
                <div class="company-details">
                    123 Booking Street<br>
                    City, State 12345<br>
                    Email: booking@bookboll.com<br>
                    Phone: (123) 456-7890
                </div>
            </div>
            <div class="invoice-meta">
                <div class="invoice-number">Invoice #{{ $invoiceNumber }}</div>
                <div class="invoice-date">Date: {{ now()->format('F d, Y') }}</div>
            </div>
        </div>

        <div class="bill-to">
            <div class="bill-to-section">
                <div class="bill-to-title">Bill To</div>
                <div class="bill-to-details">
                    {{ $booking->user->name ?? 'N/A' }}<br>
                    {{ $booking->user->email ?? 'N/A' }}<br>
                    Phone: {{ $booking->user->phone ?? 'N/A' }}
                </div>
            </div>
            <div class="bill-to-section" style="text-align: right;">
                <div class="bill-to-title">Booking #</div>
                <div class="bill-to-details">
                    #{{ $booking->id }}<br>
                    {{ Carbon\Carbon::parse($booking->date)->format('F d, Y') }}<br>
                    {{ $booking->start_time }} - {{ $booking->end_time }}
                </div>
            </div>
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
                    <div class="booking-row"><span>Time:</span> <span>{{ $booking->start_time }} to {{ $booking->end_time }}</span></div>
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
                    <span class="total-label">Discount ({{ $booking->promocode_id ? 'Promo Code' : 'Other' }})</span>
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
                Method: {{ ucfirst(str_replace('_', ' ', $booking->payment_method)) }}
            </div>
        @endif

        <div class="footer">
            <p>Thank you for your booking! If you have any questions, please contact us at booking@bookboll.com.</p>
            <p>This is an electronic invoice. For terms and conditions, visit our website.</p>
        </div>
    </div>
</body>
</html>
