@include('emails.header')

<div class="content">
    <h1>Password Reset Request</h1>
    <p>Hello {{ $user->name }},</p>
    <p>You have requested to reset your password. Please use the following OTP to proceed:</p>

    <div style="text-align: center; margin: 30px 0;">
        <div style="display: inline-block; padding: 15px 30px; background: #f0f0f0; border: 2px solid #4CAF50; border-radius: 8px; font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #4CAF50;">
            {{ $otp }}
        </div>
    </div>

    <p><strong>This OTP will expire in 10 minutes.</strong></p>
    <p>If you did not request this password reset, please ignore this email or contact our support team.</p>
    <p>If you have any questions or need further assistance, feel free to contact our support team at <a href="mailto:{{ config('app.support_email') }}">{{ config('app.support_email') }}</a>.</p>
</div>

@include('emails.footer')
