<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One already-sent scheduled reminder. See the migration for the idempotency rule. */
class NotificationLog extends Model
{
    protected $table = 'notification_log';

    protected $fillable = ['event_key', 'subject_type', 'subject_id', 'cycle_key'];
}
