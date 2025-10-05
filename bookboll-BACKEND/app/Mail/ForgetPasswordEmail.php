<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ForgetPasswordEmail extends Mailable
{
    use Queueable, SerializesModels;
    public function __construct(public $url){}

    public function build()
    {
        return $this->subject('Forget Password Request')->view('emails.forget-password');
    }
}
