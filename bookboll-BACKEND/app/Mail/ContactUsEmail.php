<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ContactUsEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public $contact)
    {}

    public function build()
    {
        return $this->subject('Welcome to Our Platform')->view('emails.contact-us');
    }
}
