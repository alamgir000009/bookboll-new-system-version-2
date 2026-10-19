<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/** A signed landlord contract, held on the private disk. */
class VenueContractDocument extends Model
{
    protected $fillable = [
        'venue_id', 'uploaded_by', 'disk', 'path', 'original_name',
        'mime', 'size_bytes', 'verified_type', 'linked_at', 'idempotency_key',
    ];

    protected $casts = [
        'linked_at'  => 'datetime',
        'size_bytes' => 'integer',
    ];

    protected static function booted(): void
    {
        // The file follows the row, so pruning an abandoned upload actually
        // removes it rather than leaving it on disk with nothing pointing at it.
        static::deleted(function (self $doc) {
            try {
                Storage::disk($doc->disk ?: 'local')->delete($doc->path);
            } catch (\Throwable $e) {
                Log::warning('Could not delete a contract file', [
                    'path' => $doc->path, 'error' => $e->getMessage(),
                ]);
            }
        });
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /** Still a temporary upload nothing has claimed. */
    public function isTemporary(): bool
    {
        return $this->linked_at === null;
    }
}
