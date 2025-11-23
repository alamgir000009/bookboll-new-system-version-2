<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $fillable = [
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
}
