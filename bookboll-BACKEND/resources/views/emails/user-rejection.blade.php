@include('emails.header')

<div class="content">
    <h1>Dear {{ $user->name }},</h1>
    
    <p>Thank you for your interest in joining our platform. We have carefully reviewed your registration request.</p>
    
    <p>Unfortunately, we are unable to approve your account registration at this time.</p>
    
    @if($reason)
    <p><strong>Reason:</strong> {{ $reason }}</p>
    @endif
    
    <p>We appreciate your understanding and encourage you to reach out to our support team if you have any questions or would like to discuss this decision further.</p>
    
    <p>If you believe this decision was made in error or if you have additional information that may be relevant, please don't hesitate to contact us.</p>
    
    <p>Thank you for your interest, and we wish you the best in your future endeavors.</p>
    
    <p>If you have any questions or need further assistance, please feel free to contact our support team at <a href="mailto:{{ config('app.support_email') }}">{{ config('app.support_email') }}</a>.</p>
    
    <p>Best regards,<br>
    <strong>The BookBoll Team</strong></p>
</div>

@include('emails.footer')

