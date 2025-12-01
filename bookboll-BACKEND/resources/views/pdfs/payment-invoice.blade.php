<!doctype html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0">
    <title>Invoice #{{ $payment->id }}</title>

    <style>
        @page {
            margin: 40px 25px;
        }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 12px;
            color: #333;
            margin: 0;
            padding: 0;
            background-color: #fff;
        }

        .invoice-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 25px;
            border: 1px solid #eee;
            border-radius: 8px;
            background: #fff;
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #4a90e2;
            padding-bottom: 10px;
            margin-bottom: 30px;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
            color: #4a90e2;
            letter-spacing: 1px;
        }

        .header p {
            margin: 3px 0;
            color: #666;
            font-size: 12px;
        }

        .invoice-title {
            text-align: right;
            margin-bottom: 20px;
        }

        .invoice-title h2 {
            font-size: 20px;
            margin: 0;
            color: #222;
        }

        .invoice-title span {
            font-size: 13px;
            color: #888;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }

        table th {
            background: #f8f9fb;
            color: #555;
            text-align: left;
            padding: 10px;
            font-size: 13px;
            border-bottom: 1px solid #ddd;
        }

        table td {
            padding: 10px;
            border-bottom: 1px solid #eee;
            font-size: 13px;
        }

        .total-section {
            text-align: right;
            margin-top: 20px;
            padding: 10px;
            border-top: 2px solid #4a90e2;
        }

        .total-section h3 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }

        .footer {
            text-align: center;
            font-size: 11px;
            color: #777;
            margin-top: 50px;
            border-top: 1px solid #eee;
            padding-top: 10px;
        }

        .branding {
            text-align: left;
            margin-bottom: 10px;
        }

        .branding img {
            width: 100px;
            height: auto;
            margin-bottom: 5px;
        }

        .two-column {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .two-column div {
            width: 48%;
        }

        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 60px;
        }

        .signature {
            width: 45%;
            text-align: center;
        }

        .signature-line {
            margin-top: 40px;
            border-top: 1px solid #ccc;
            width: 80%;
            margin-left: auto;
            margin-right: auto;
        }

    </style>
</head>
<body>
    <div class="invoice-container">
        <!-- Header -->
        <div class="header">
            @if(isset($company['logo']))
            <img src="{{ public_path($company['logo']) }}" alt="Company Logo" style="max-height: 60px; margin-bottom: 10px;">
            @endif
            <h1>{{ $company['name'] }}</h1>
            <p>{{ $company['address'] }}</p>
            <p>{{ $company['email'] }} | {{ $company['phone'] }}</p>
        </div>

        <!-- Invoice Info -->
        <div class="two-column">
            <div>
                <strong>Invoice To:</strong><br>
                {{ $payment?->user?->name ?? 'N/A' }}<br>
                {{ $payment?->user?->email ?? '' }}<br>
                {{ $payment?->user?->phone ?? '' }}<br>
            </div>
            <div class="invoice-title">
                <h2>Invoice #{{ $payment->id }}</h2>
                <span>Date: {{ $date }}</span>
            </div>
        </div>

        <!-- Payment Details -->
        <table>
            <tr>
                <th>Booking ID</th>
                <td>{{ $payment?->booking?->booking_id }}</td>
            </tr>
            <tr>
                <th>Payment ID</th>
                <td>{{ $payment->payment_id }}</td>
            </tr>
            <tr>
                <th>Venue</th>
                <td>{{ $payment?->booking?->venue->name ?? 'N/A' }}</td>
            </tr>
            <tr>
                <th>Payment Type</th>
                <td>{{ ucfirst($payment->type) }}</td>
            </tr>
            <tr>
                <th>Payment Method</th>
                <td>{{ ucfirst(str_replace('_', ' ', $payment->method)) }}</td>
            </tr>
            <tr>
                <th>Status</th>
                <td>{{ ucfirst($payment->status) }}</td>
            </tr>
            <tr>
                <th>Notes</th>
                <td>{{ $payment->notes }}</td>
            </tr>
        </table>

        <!-- Total -->
        <div class="total-section">
            <h3>Total Amount: QAR {{ number_format($payment->amount, 2) }}</h3>
        </div>

        <!-- Signatures -->
        <div class="signature-section">
            <div class="signature">
                <p>_________________________</p>
                <p><strong>Authorized Signature</strong></p>
            </div>
            <div class="signature">
                <p>_________________________</p>
                <p><strong>Customer Signature</strong></p>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>Thank you for your business!</p>
            <p>This invoice was generated automatically on {{ $date }}</p>
        </div>
    </div>
</body>
</html>
