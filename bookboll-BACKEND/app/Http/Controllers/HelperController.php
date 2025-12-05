<?php
namespace App\Http\Controllers;

use App\Mail\ContactUsEmail;
use App\Models\AddOn;
use App\Models\Contact;
use App\Models\Listing;
use App\Models\Resource;
use App\Models\Review;
use App\Models\SpecialDay;
use App\Models\SportsCategory;
use App\Models\User;
use App\Models\Venue;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class HelperController extends Controller
{
    public function extraData()
    {
        $venues = Venue::with(['listings:id,venue_id,name', 'schedules'])
            ->orderBy('sort_order', 'ASC')
            ->get(['id', 'name']);
        $listings = Listing::with(['venue'])
            ->get(['id', 'name', 'venue_id', 'calendar_color']);

        return sendSuccess('Data fetched successfully', compact('venues', 'listings'));
    }

    public function extraCustomers()
    {
        $customers = User::role('customer')->get(['id', 'name', 'email']);
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

    public function extraSpecialDays()
    {
        $specialDays = SpecialDay::with(['timeBlocks'])->get()->map(function ($day) {
            return [
                'id'         => $day->id,
                'date'       => $day->date,
                'type'       => $day->type,
                'venue_id'   => $day->venue_id,
                'timeBlocks' => $day->timeBlocks->map(function ($block) {
                    return [
                        'start_time'     => $block->start_time,
                        'end_time'       => $block->end_time,
                        'price_per_hour' => $block->price_per_hour,
                        'price_currency' => $block->price_currency,
                    ];
                }),
            ];
        });

        return sendSuccess('Special days fetched successfully', compact('specialDays'));
    }

    public function homePageData()
    {
        $total_reviews  = Review::count();
        $average_rating = Review::avg('overall_rating') ?? 0;

        $reviews = Review::withWhereHas('user:id,name,image')
            ->withWhereHas('booking.listing:id,name,venue_id')
            ->withWhereHas('booking.venue:id,name')
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
        } catch (Exception $e) {
            Log::error("Contact Us mail failed: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Failed to send message: Something went wrong");
        }
    }
}
