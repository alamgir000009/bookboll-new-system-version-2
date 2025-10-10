<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $fillable = [
        'user_id',
        'comment',
        'booking_id',
        'listing_id',
        'review_text',
        'recommended',
        'staff_rating',
        'value_rating',
        'helpful_count',
        'overall_rating',
        'facilities_rating',
        'cleanliness_rating',
    ];

    protected $casts = [
        'recommend' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function listing()
    {
        return $this->belongsTo(Listing::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function venue()
    {
        return $this->booking->venue();
    }

    public function helpfulUsers()
    {
        return $this->belongsToMany(User::class, 'helpful_review', 'review_id', 'user_id')->withTimestamps();
    }

    public function getHelpfulCountAttribute(): int
    {
        return $this->helpfulUsers()->count();
    }
}
