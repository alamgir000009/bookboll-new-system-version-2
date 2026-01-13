<?php
namespace App\Jobs;

use App\Mail\NewsletterEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendNewsletter
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public $subject, public $content, public $subscribers)
    {}

    public function handle(): void
    {
        foreach ($this->subscribers as $subscriber) {
            Mail::to($subscriber->email)->queue(new NewsletterEmail($this->subject, $this->content, $subscriber));
        }
    }
}
