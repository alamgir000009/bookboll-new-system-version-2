<?php
namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class UserRejectionEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public ?string $reason = null)
    {}

    public function build()
    {
        return $this->subject('Account Registration Request - Not Approved')
            ->view('emails.user-rejection');
    }
}

