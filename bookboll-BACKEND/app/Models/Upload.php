<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * A file this platform stored, and the account that uploaded it.
 *
 * Created by UploadController for every successful upload. Deleting the row
 * deletes the file, so removing an account (which cascades to these rows) does
 * not leave its images on disk.
 */
class Upload extends Model
{
    protected $fillable = [
        'user_id',
        'disk',
        'path',
        'folder',
        'mime',
        'size_bytes',
        'original_name',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    protected static function booted(): void
    {
        // The bytes follow the row. Done on `deleted` rather than `deleting` so
        // a failed delete does not remove the file while the row survives.
        static::deleted(function (Upload $upload) {
            try {
                Storage::disk($upload->disk ?: 'public')->delete($upload->path);
            } catch (\Throwable $e) {
                // The row is already gone; a leftover file is for the sweeper to
                // find, and must not turn account deletion into an error.
                Log::warning('Could not delete an upload file', [
                    'path' => $upload->path, 'error' => $e->getMessage(),
                ]);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The record for a stored path, or null when the file predates tracking. */
    public static function forPath(string $path): ?self
    {
        return static::where('path', $path)->first();
    }
}
