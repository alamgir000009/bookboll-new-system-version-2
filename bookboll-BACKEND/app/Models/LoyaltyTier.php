<?php
namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use Illuminate\Database\Eloquent\Model;

class LoyaltyTier extends Model
{
    use LogsModelActivity, TracksCreator;

    protected $fillable = [
        'created_by',
        'program_id',
        'name',
        'slug',
        'color',
        'bg_color',
        'multiplier',
        'order',
        'is_active',
        'min_points',
        'max_points',
        'description',
    ];

    /**
     * The Supabase-shaped row the dashboard expects.
     *
     * The dashboard was written against loyalty_tiers as Supabase defined it
     * (threshold / sort_order); those live here as min_points / order, so the
     * mapping happens once, here, rather than in every consumer.
     *
     * @return array<string, mixed>
     */
    public function toDashboardRow(): array
    {
        return [
            'id'         => $this->id,
            'program_id' => $this->program_id,
            'name'       => $this->name,
            'color'      => $this->color ?: '#92400e',
            'bg_color'   => $this->bg_color ?: '#fef3c7',
            'threshold'  => (int) ($this->min_points ?? 0),
            'multiplier' => (float) ($this->multiplier ?? 1),
            'sort_order' => (int) ($this->order ?? 0),
        ];
    }

    public function program()
    {
        return $this->belongsTo(LoyaltyProgram::class, 'program_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    public function getNextTier()
    {
        return self::active()->ordered()->where('order', '>', $this->order)->first();
    }

    public function qualifiesFor(int $points): bool
    {
        $max = $this->max_points === 2147483647 ? PHP_INT_MAX : $this->max_points;
        return $points >= $this->min_points && $points <= $max;
    }
    protected function activityLogName(): string
    {
        return 'loyalty_tier';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'name',
            'slug',
            'color',
            'order',
            'is_active',
            'min_points',
            'max_points',
            'description',
        ];
    }

}