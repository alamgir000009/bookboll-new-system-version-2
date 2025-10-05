@include('emails.header')

<div class="content">
    <p>Password Reset Request</p>
    <p>Click the button below to reset your password:</p>

    <a href="{{ $url }}" style="padding:10px 20px; background:#4CAF50; color:white; text-decoration:none;">
        Reset Password
    </a>

    <p>If you have any questions or need further assistance, feel free to contact our support team at <a href="mailto:{{ config('app.support_email') }}">{{ config('app.support_email') }}</a>.</p>
</div>

@include('emails.footer')
