<?php
namespace App\Http\Controllers;

use App\Services\ReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReviewController extends Controller
{
    public function __construct(protected ReviewService $reviewService)
    {}

    public function index()
    {
        return $this->reviewService->reviews();
    }

    public function store(Request $request)
    {
        return $this->reviewService->createReview($request);
    }

    public function myReviews()
    {
        return $this->reviewService->myReviews();
    }

    public function update(Request $request, $id)
    {
        return $this->reviewService->updateReview($request, $id);
    }

    public function destroy($id)
    {
        return $this->reviewService->deleteReview($id);
    }

    public function helpful($id)
    {
        return $this->reviewService->incrementHelpful($id);
    }

    public function reviewableBookings() {
        return $this->reviewService->bookingsForReview();
    }
}
