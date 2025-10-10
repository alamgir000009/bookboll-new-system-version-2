<?php
namespace App\Services;

use App\Models\Booking;
use App\Models\Review;
use BookingStatus;
use Exception;

class ReviewService
{
    public function __construct(protected Review $reviewModel, protected Booking $bookingModel)
    {}

    public function reviews()
    {
        $records_per_page = request()->get('records_per_page', 10);

        $total_reviews  = $this->reviewModel->count();
        $average_rating = $this->reviewModel->avg('overall_rating') ?? 0;

        $star_distribution = $this->reviewModel
            ->selectRaw('overall_rating, COUNT(*) as count')
            ->groupBy('overall_rating')
            ->pluck('count', 'overall_rating')
            ->toArray();

        $star_distribution = array_fill_keys([1, 2, 3, 4, 5], 0) + $star_distribution;

        $stats = compact('average_rating', 'total_reviews', 'star_distribution');

        $reviews = $this->reviewModel
            ->with([
                'user:id,name,image',
                'booking.listing:id,name,venue_id',
                'booking.venue:id,name',
            ])
            ->latest()
            ->simplePaginate($records_per_page);

        return sendSuccess('Reviews fetched successfully.', compact('reviews', 'stats'));
    }

    public function myReviews()
    {
        $reviews = $this->reviewModel
            ->where('user_id', auth()->id())
            ->with([
                'user:id,name,image',
                'booking:id,listing_id,venue_id,status,created_at',
                'booking.listing:id,name,venue_id',
                'booking.venue:id,name',
            ])
            ->latest()
            ->get();

        $total_my_reviews = $this->reviewModel->where('user_id', auth()->id())->count();

        return sendSuccess('My reviews fetched successfully.', compact('reviews', 'total_my_reviews'));
    }

    public function createReview($request)
    {

        $formData = $request->validate([
            'booking_id'         => 'required|exists:bookings,id',
            'overall_rating'     => 'required|integer|min:1|max:5',
            'cleanliness_rating' => 'nullable|integer|min:1|max:5',
            'facilities_rating'  => 'nullable|integer|min:1|max:5',
            'staff_rating'       => 'nullable|integer|min:1|max:5',
            'value_rating'       => 'nullable|integer|min:1|max:5',
            'review_text'        => 'nullable|string|max:500',
            'recommend'          => 'boolean',
        ]);

        $bookingId = $formData['booking_id'];
        $userId    = auth()->id();

        $booking = $this->bookingModel->where('id', $bookingId)
            ->where('user_id', $userId)
            ->where('status', BookingStatus::COMPLETED)->firstOrFail();

        $review = $booking->review()->updateOrCreate([
            'user_id' => $userId,
        ], [
            'overall_rating'     => $formData['overall_rating'],
            'listing_id'         => $booking->listing_id,
            'cleanliness_rating' => $formData['cleanliness_rating'] ?? 0,
            'facilities_rating'  => $formData['facilities_rating'] ?? 0,
            'staff_rating'       => $formData['staff_rating'] ?? 0,
            'value_rating'       => $formData['value_rating'] ?? 0,
            'review_text'        => $formData['review_text'] ?? null,
            'recommend'          => $formData['recommend'] ?? false,
        ]);

        return sendSuccess('Review submitted successfully.', compact('review'));

    }

    public function updateReview($request, $id)
    {
        $review = $this->reviewModel->findOrFail($id);

        $formData = $request->validate([
            'booking_id'         => 'required|exists:bookings,id',
            'overall_rating'     => 'required|integer|min:1|max:5',
            'cleanliness_rating' => 'nullable|integer|min:1|max:5',
            'facilities_rating'  => 'nullable|integer|min:1|max:5',
            'staff_rating'       => 'nullable|integer|min:1|max:5',
            'value_rating'       => 'nullable|integer|min:1|max:5',
            'review_text'        => 'nullable|string|max:500',
            'recommend'          => 'boolean',
        ]);

        if ($review->user_id !== auth()->id()) {
            throw new Exception('Unauthorized.');
        }

        $review->update($formData);

        return sendSuccess('Review updated successfully.', compact('review'));
    }

    public function deleteReview($id)
    {
        $review = $this->reviewModel->findOrFail($id);

        if ($review->user_id != auth()->id()) {
            throw new Exception('Unauthorized.');
        }
        $review->delete();
        return sendSuccess('Review deleted successfully.');
    }

    public function incrementHelpful($id)
    {
        $review = $this->reviewModel::with('helpfulUsers:id')->findOrFail($id);
        $userId = auth()->id();

        if ($review->user_id == $userId) {
            return sendError('Cannot mark your own review as helpful.', [], 400);
        }

        try {

            $exists = $review->helpfulUsers()->where('user_id', $userId)->exists();

            if ($exists) {
                $review->helpfulUsers()->detach($userId);
                $isMarked = false;
                $action   = 'decrement';
            } else {
                $review->helpfulUsers()->attach($userId);
                $isMarked = true;
                $action   = 'increment';
            }

            $review->refresh();

            $result = [
                'helpful_count' => $review->helpful_count,
                'action'        => $action,
                'is_marked'     => $isMarked,
            ];

            $action = $result['is_marked'] ? 'marked' : 'unmarked';

            return sendSuccess("Review {$action} as helpful.", $result);
        } catch (Exception $e) {
            return sendError($e->getMessage());
        }
    }
}
