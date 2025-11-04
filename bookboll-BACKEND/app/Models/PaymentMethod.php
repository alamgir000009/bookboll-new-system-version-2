<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $fillable = [
        'brand',
        'user_id',
        'exp_year',
        'last_four',
        'exp_month',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
