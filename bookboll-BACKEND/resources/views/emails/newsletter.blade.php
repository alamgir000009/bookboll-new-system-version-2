@include('emails.header')

<div class="content">
    <h1 style="color: #333;">{{ $subject }}</h1>
    <div>
        {!! $content !!}
    </div>
    <hr style="margin-top: 30px; border: none; border-top: 1px solid #eee;">
    <p style="color: #666; font-size: 12px;">
        You received this email because you subscribed to our newsletter.<br>
        <a href="{{ route('newsletter.unsubscribe', $subscriber->email) }}" style="color: #999;">Unsubscribe</a> |
    </p>
</div>

@include('emails.footer')
