<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $table = 'settings';

    protected $fillable = [
        'key',
        'type',
        'value',
        'encrypted',
        'description',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'encrypted' => 'boolean',
        'value'     => 'string',
    ];

    public function getValueAttribute($value)
    {
        if ($this->encrypted && $value) {
            $value = decrypt($value);
        }

        return $this->castValue($value);
    }

    public function setValueAttribute($value)
    {
        $castedValue = $this->castValue($value);

        if ($this->encrypted && $castedValue !== null) {
            $this->attributes['value'] = encrypt($castedValue);
        } else {
            $this->attributes['value'] = $castedValue;
        }
    }

    private function castValue($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value) || is_bool($value) || is_int($value)) {
            return $value;
        }

        return match ($this->type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'json'    => is_string($value) ? json_decode($value, true) : $value,
            default   => $value,
        };
    }

    public function scopeByGroup($query, $group)
    {
        return $query->where('key', 'like', $group . '.%');
    }

    public function scopeActive($query)
    {
        return $query->whereNotNull('value');
    }

    public static function getCached(string $key, $default = null)
    {
        return Cache::remember("setting_{$key}", 60 * 60, function () use ($key, $default) {
            $setting = static::where('key', $key)->first();
            return $setting ? $setting->value : $default;
        });
    }

    public static function forgetCache(string $key): void
    {
        Cache::forget("setting_{$key}");
        Cache::forget('settings_all');
    }

    public static function clearAllCache(): void
    {
        Cache::forget('settings_all');
    }

    public static function exists(string $key): bool
    {
        return static::where('key', $key)->exists();
    }

    public function getRawValueAttribute()
    {
        if ($this->encrypted && ! empty($this->attributes['value'])) {
            return decrypt($this->attributes['value']);
        }
        return $this->attributes['value'] ?? null;
    }

    public function setRawValueAttribute($value)
    {
        $this->attributes['value'] = $value;
    }
}
