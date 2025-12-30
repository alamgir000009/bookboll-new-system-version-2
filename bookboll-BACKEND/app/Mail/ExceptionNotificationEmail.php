<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ExceptionNotificationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Throwable $exception,
        public array $context = []
    ) {}

    public function build()
    {
        return $this->subject('🚨 Exception Error in ' . config('app.name'))
            ->view('emails.exception-notification')
            ->with([
                'exception' => $this->exception,
                'context' => $this->context,
                'appName' => config('app.name'),
                'appUrl' => config('app.url'),
                'environment' => config('app.env'),
            ]);
    }
}

