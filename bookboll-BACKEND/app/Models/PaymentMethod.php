<?php
namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use LogsModelActivity, TracksCreator;

    protected $fillable = [
        'created_by',
        'brand',
        'user_id',
        'default',
        'exp_year',
        'last_four',
        'exp_month',
        'stripe_payment_method_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    protected function activityLogName(): string
    {
        return 'payment_method';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'brand',
            'user_id',
            'default',
            'exp_year',
            'last_four',
            'exp_month',
            'stripe_payment_method_id',
        ];
    }

}