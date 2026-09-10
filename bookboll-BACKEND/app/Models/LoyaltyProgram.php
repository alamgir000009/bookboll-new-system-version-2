<?php

namespace App\Models;

use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;

class LoyaltyProgram extends Model
{
    use LogsModelActivity;

    protected $fillable = [
        'country_id',
        'name',
        'earn_rate',
        'redeem_rate',
        'redeem_value',
        'min_redeem',
        'max_redeem_pct',
        'expiry_months',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'country_id'     => 'integer',
            'earn_rate'      => 'float',
            'redeem_rate'    => 'integer',
            'redeem_value'   => 'float',
            'min_redeem'     => 'integer',
            'max_redeem_pct' => 'integer',
            'expiry_months'  => 'integer',
            'is_active'      => 'boolean',
        ];
    }

    /**
     * Defaults applied to every country without a row: 1 point per unit spent,
     * 100 points = 1 currency unit off. Mirrors the migration column defaults.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'country_id'     => null,
            'name'           => 'Bookboll Rewards',
            'earn_rate'      => 1.0,
            'redeem_rate'    => 100,
            'redeem_value'   => 1.0,
            'min_redeem'     => 100,
            'max_redeem_pct' => 100,
            'expiry_months'  => 12,
            'is_active'      => true,
        ];
    }

    protected function activityLogName(): string
    {
        return 'loyalty_program';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'country_id',
            'name',
            'earn_rate',
            'redeem_rate',
            'redeem_value',
            'min_redeem',
            'max_redeem_pct',
            'expiry_months',
            'is_active',
        ];
    }

    /** This country's tier ladder, in display order. */
    public function tiers()
    {
        return $this->hasMany(LoyaltyTier::class, 'program_id');
    }
}
