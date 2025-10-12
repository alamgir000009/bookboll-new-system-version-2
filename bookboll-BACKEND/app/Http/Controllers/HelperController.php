<?php
namespace App\Http\Controllers;

use App\Mail\ContactUsEmail;
use App\Models\AddOn;
use App\Models\Contact;
use App\Models\Listing;
use App\Models\Resource;
use App\Models\Review;
use App\Models\SportsCategory;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class HelperController extends Controller
{
    public function extraData()
    {
        $venues   = Venue::with(['listings:id,venue_id,name', 'schedules'])->get(['id', 'name']);
        $listings = Listing::with(['venue'])->get(['id', 'name', 'venue_id']);
        return sendSuccess('Data fetched successfully', compact('venues', 'listings'));
    }

    public function extraCustomers()
    {
        $customers = User::role('customer')->get(['id', 'name']);
        return sendSuccess('Data fetched successfully', compact('customers'));
    }

    public function extraResources()
    {
        $resources = Resource::latest()->get();
        return sendSuccess('Data fetched successfully', compact('resources'));
    }

    public function extraAddOns()
    {
        $addons = AddOn::latest()->get();
        return sendSuccess('Data fetched successfully', compact('addons'));
    }
    public function extraSportCategories()
    {
        $sportCategories = SportsCategory::latest()->get();
        return sendSuccess('Data fetched successfully', compact('sportCategories'));
    }

    public function extraDataForListing()
    {
        $addons          = AddOn::latest()->get();
        $resources       = Resource::latest()->get();
        $sportCategories = SportsCategory::latest()->get();
        $venues          = Venue::with(['listings:id,venue_id,name', 'schedules'])->get(['id', 'name']);

        return sendSuccess('Data fetched successfully', compact('venues', 'resources', 'addons', 'sportCategories'));
    }

    public function homePageData()
    {
        $total_reviews  = Review::count();
        $average_rating = Review::avg('overall_rating') ?? 0;

        $reviews = Review::with([
            'user:id,name,image',
            'booking.listing:id,name,venue_id',
            'booking.venue:id,name',
        ])
            ->whereHas('user')
            ->inRandomOrder()->take(2)->get()->map(function ($review) {
            return [
                "user_name"      => $review->user->name,
                "created_at"     => $review->created_at,
                "overall_rating" => $review->overall_rating,
                "review_text"    => $review->review_text,
                "listing_name"   => $review->booking->listing->name,
            ];
        });

        $data = [
            'reviews'        => $reviews,
            'total_reviews'  => $total_reviews,
            'average_rating' => round($average_rating, 1),
        ];

        return sendSuccess('Data fetched successfully', $data);
    }

    public function contactUs(Request $request)
    {
        $request->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'required|email|max:255',
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        try {

            $contact    = Contact::create($request->only(['name', 'email', 'subject', 'message']));
            $adminEmail = config('app.support_email');
            Mail::to($adminEmail)->send(new ContactUsEmail($contact));

            return sendSuccess("Message sent successfully! We\'ll get back to you soon.");
        } catch (\Exception $e) {
            logger("Contact Us mail failed: " . $e->getMessage());

            return sendError("Failed to send message: Something went wrong");
        }

    }
}
