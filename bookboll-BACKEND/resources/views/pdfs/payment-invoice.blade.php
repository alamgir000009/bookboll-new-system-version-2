<!doctype html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0">
    <title>Invoice #{{ $payment->id }}</title>

    <style>
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
            border: 0;
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
            border: 0;
            margin-bottom: 25px;
        }

        table th {
            background: #f8f9fb;
            color: #555;
            text-align: left;
            padding: 10px;
            font-size: 13px;
            border: 0;
            border-bottom: 1px solid #ddd;
        }

        table td {
            padding: 10px;
            border: 0;
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

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 20px;
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
                                        {{ \CompnayInfo::EMAIL ?? '' }} | {{ \CompnayInfo::PHONE ?? '' }}
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
                                    <div style="font-weight: bold; font-size: 18px; color: #667eea;">#{{ $payment->payment_id }}</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </div>

        <div style="width: 100%; margin-bottom: 10px;">
            <table style="width: 100%; border-collapse: collapse; border-spacing: 0; border: 0;">
                <tr>
                    <td style="width: 50%; vertical-align: top; padding-right: 20px; border: 0;">
                        <div style="font-weight: bold; margin-bottom: 5px;">Bill To</div>
                        <div style="line-height: 1.4;">
                            {{ $payment->user->name ?? 'N/A' }}<br>
                            {{ $payment->user->email ?? 'N/A' }}<br>
                            Phone: {{ $payment->user->phone ?? 'N/A' }}
                        </div>
                    </td>
                    <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px; border: 0;">
                        <div style="font-weight: bold; margin-bottom: 5px;">Payment #</div>
                        <div style="line-height: 1.4;">
                            #{{ $payment->payment_id ?? $payment->id }}<br>
                            {{ $date }}
                        </div>
                    </td>
                </tr>
            </table>
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
