<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait GeneratesUuid
{
    protected static function bootGeneratesUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
