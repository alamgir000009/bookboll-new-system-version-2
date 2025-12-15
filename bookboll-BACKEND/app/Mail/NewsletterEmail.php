<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewsletterEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public $subject, public $content, public $subscriber)
    {}

    public function build()
    {
        return $this->subject($this->subject)->view('emails.newsletter');
    }
}
