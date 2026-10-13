<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BusinessEmailOtpEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $otp, public string $email)
    {
    }

    public function build()
    {
        return $this->subject('Your Bookboll verification code')
            ->view('emails.business-email-otp');
    }
}
