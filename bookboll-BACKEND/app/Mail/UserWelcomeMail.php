<?php
namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class UserWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;


    public function __construct(public $user, public $plainPassword = null){}

    public function build()
    {
        return $this->subject('Welcome to Our Platform')->view('emails.user-welcome');
    }
}
