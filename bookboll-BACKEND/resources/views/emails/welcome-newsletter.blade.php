@include('emails.header')

<div class="content">
    <h1>Welcome, {{ $subscriber->name ?? 'Subscriber' }}!</h1>
    <p>Thanks for subscribing. We'll keep you updated.</p>
    <p>If you have any questions or need further assistance, feel free to contact our support team at <a href="mailto:{{ config('app.support_email') }}">{{ config('app.support_email') }}</a>.</p>
</div>

@include('emails.footer')
