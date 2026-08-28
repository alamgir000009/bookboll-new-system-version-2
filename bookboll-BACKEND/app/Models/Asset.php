<?php

namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Asset extends Model
{
    use LogsModelActivity, HasFactory, TracksCreator;

    public const TYPE_IMAGE    = 'image';
    public const TYPE_VIDEO    = 'video';
    public const TYPE_DOCUMENT = 'document';

    protected $fillable = [
        'path',
        'type',
        'created_by',
        'sort_order',
        'assetable_id',
        'assetable_type',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    protected $appends = [
        'url',
    ];

    public function assetable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @deprecated Use assetable(); kept for older listing code paths. */
    public function listing()
    {
        return $this->belongsTo(Listing::class, 'assetable_id')
            ->where($this->getTable() . '.assetable_type', Listing::class);
    }

    public function getUrlAttribute(): string
    {
        $path = (string) ($this->path ?? '');
        if ($path === '') {
            return '';
        }
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }
        if (str_starts_with($path, '/storage/')) {
            return url($path);
        }
        if (str_contains($path, '/storage/')) {
            return url($path);
        }

        return url('storage/' . ltrim($path, '/'));
    }

    public function scopeForModel($query, Model $model)
    {
        return $query
            ->where('assetable_type', $model->getMorphClass())
            ->where('assetable_id', $model->getKey());
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    protected function activityLogName(): string
    {
        return 'asset';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'assetable_type',
            'assetable_id',
            'path',
            'type',
            'sort_order',
        ];
    }
}
