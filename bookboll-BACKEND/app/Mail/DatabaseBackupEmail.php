<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DatabaseBackupEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public $backupPath, public $backupFileName)
    {}

    public function build()
    {
        $subject = 'Database Backup - ' . now()->format('Y-m-d H:i:s');
        
        return $this->subject($subject)
            ->view('emails.database-backup')
            ->attach($this->backupPath, [
                'as' => $this->backupFileName,
                'mime' => 'application/gzip',
            ]);
    }
}

