@include('emails.header')

<div class="content">
    <p>Welcome {{ $user->name }},</p>
    <p>Your account has been created successfully.</p>
    <p>Here are your login credentials:</p>
    <p><strong>- **Email:** {{ $user->email }}</strong></p>
    <p><strong>- **Password:** {{ $plainPassword }}</strong></p>
    <p>If you have any questions or need further assistance, feel free to contact our support team at <a href="mailto:{{ config('app.support_email') }}">{{ config('app.support_email') }}</a>.</p>
</div>

@include('emails.footer')
