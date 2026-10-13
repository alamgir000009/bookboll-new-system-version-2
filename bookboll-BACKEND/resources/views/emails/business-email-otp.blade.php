@include('emails.header')

<div class="content">
    <h1>Confirm your email</h1>
    <p>Welcome to Bookboll. Enter this code on the sign-up page to confirm <strong>{{ $email }}</strong> is yours:</p>

    <div style="text-align: center; margin: 30px 0;">
        <div style="display: inline-block; padding: 15px 30px; background: #f0fdfa; border: 2px solid #0d9488; border-radius: 8px; font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #0d9488;">
            {{ $otp }}
        </div>
    </div>

    <p><strong>The code expires in {{ \App\Services\EmailVerificationService::OTP_TTL_MINUTES }} minutes.</strong></p>
    <p>If you did not start creating a Bookboll business account, you can ignore this email — nothing has been created, and no account exists for this address until the code is entered.</p>
    <p>Questions? Reach us at <a href="mailto:{{ config('app.support_email') }}">{{ config('app.support_email') }}</a>.</p>
</div>

@include('emails.footer')
