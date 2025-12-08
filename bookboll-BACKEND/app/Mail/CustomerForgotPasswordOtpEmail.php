<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CustomerForgotPasswordOtpEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public $otp, public $user)
    {}

    public function build()
    {
        return $this->subject('Password Reset OTP')->view('emails.customer-forgot-password-otp');
    }
}
