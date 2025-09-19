<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmation</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            font-family: 'Poppins', sans-serif;
        }

        .container {
            max-width: 600px;
            margin: 30px auto;
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .header {
            text-align: center;
            padding: 10px 0px;
            border-bottom: 2px solid #ddd;
            background: #CCF5F5 !important;
        }

        .header img {
            max-width: 200px;
            width: 200px;
            height: auto;
        }

        .header h2 {
            color: #007bff;
            font-size: 24px;
            margin: 0;
        }

        .content {
            padding: 20px 20px;
            line-height: 1.6;
        }

        .content p {
            font-size: 16px;
            color: #333;
            margin: 10px 0;
        }

        .content p strong {
            color: #007bff;
        }

        .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #007bff;
            color: #ffffff;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            margin: 15px 0;
            text-align: center;
        }

        .footer {
            text-align: center;
            padding-top: 20px;
            border-top: 2px solid #ddd;
            font-size: 14px;
            color: #666;
        }

        .footer a {
            color: #007bff;
            text-decoration: none;
        }

        @media screen and (max-width: 600px) {
            .container {
                padding: 15px;
                margin: 15px;
            }

            .header h2 {
                background: #65C7FC;
                font-size: 20px;
            }

            .content p {
                font-size: 14px;
            }

            .button {
                padding: 10px 20px;
                font-size: 14px;
            }
        }

    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="https://bookboll.ae/assets/images/logo_dark.png" alt="bookboll Logo">
        </div>
        <div class="content">
            <p>Welcome {{ $user->name }},</p>
            <p>Your account has been created successfully.</p>
            <p>Here are your login credentials:</p>
            <p><strong>- **Email:** {{ $user->email }}</strong></p>
            <p><strong>- **Password:** {{ $plainPassword }}</strong></p>
            <p>If you have any questions or need further assistance, feel free to contact our support team at <a href="mailto:{{ config('app.support_email') }}">{{ config('app.support_email') }}</a>.</p>
        </div>
        <div class="footer">
            <p>Thank you for trusting bookboll with your laundry needs!<br>
                <a href="{{ config('app.url') }}">Visit our website</a> | <a href="mailto:{{ config('app.support_email') }}">Contact Support</a></p>
        </div>
    </div>
</body>
</html>
