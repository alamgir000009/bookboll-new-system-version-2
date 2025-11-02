<?php
namespace App\Mail;

use App\Models\Subscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeNewsletterEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Subscriber $subscriber)
    {}

    public function build()
    {
        return $this->subject('Welcome to Our Newsletter!')->view('emails.welcome-newsletter');
    }
}
