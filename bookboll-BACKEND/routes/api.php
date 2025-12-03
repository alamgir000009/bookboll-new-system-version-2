<?php

use App\Http\Controllers\AddOnController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\GiftCardController;
use App\Http\Controllers\HelperController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\LoyaltyPointController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PostCategoryController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PromocodeController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SpecialDayController;
use App\Http\Controllers\SportsCategoryController;
use App\Http\Controllers\SportsTypeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VenueController;
use Illuminate\Support\Facades\Route;

Route::post('login', [UserController::class, 'login']);
Route::post('register', [UserController::class, 'register']);
Route::post('update-profile', [UserController::class, 'updateProfile'])->middleware('auth:sanctum');
Route::post('change-password', [UserController::class, 'changePassword'])->middleware('auth:sanctum');
Route::post('forgot-password', [UserController::class, 'sendResetLinkEmail']);
Route::post('reset-password', [UserController::class, 'reset']);

Route::middleware(['auth:sanctum'])->group(function () {

    Route::post('logout', [UserController::class, 'logout']);
    Route::get('me', [UserController::class, 'me']);

    Route::prefix('admin')->group(function () {

        Route::apiResource('post-categories', PostCategoryController::class);
        Route::apiResource('posts', PostController::class);
        Route::post('posts/change-status', [PostController::class, 'changeStatus']);
        Route::get('posts-tags', [PostController::class, 'postTagsList']);

        Route::post('venues/sort', [VenueController::class, 'sort']);
        Route::apiResource('venues', VenueController::class);

        Route::apiResource('users', UserController::class);
        Route::get('users/required-data', [UserController::class, 'requiredDataForUser']);
        Route::post('users/update-profile', [UserController::class, 'updateProfile']);
        Route::post('customers/register', [CustomerController::class, 'createCustomer']);

        Route::get('settings', [SettingController::class, 'index']);
        Route::post('settings/{key}', [SettingController::class, 'updateSingle']);
        Route::post('settings', [SettingController::class, 'update']);

        Route::apiResource('sports-types', SportsTypeController::class)->only(['store', 'update', 'destroy']);
        Route::post('sports-types/sort', [SportsTypeController::class, 'sort']);

        Route::post('sports-categories/sort', [SportsCategoryController::class, 'sort']);
        Route::apiResource('sports-categories', SportsCategoryController::class);
        Route::get('sports-categories/popular-emojis', [SportsCategoryController::class, 'popularEmojis']);

        Route::apiResource('gift-cards', GiftCardController::class);

        Route::apiResource('resources', ResourceController::class);

        Route::get('audit-logs', [AuditLogController::class, 'index']);
        Route::get('audit-logs/stats', [AuditLogController::class, 'stats']);
        Route::get('audit-logs/export', [AuditLogController::class, 'export']);

        Route::post('add-ons/sort', [AddOnController::class, 'sort']);
        Route::apiResource('add-ons', AddOnController::class);

        Route::post('listings/sort', [ListingController::class, 'sort']);
        Route::post('listings/{id}/images', [ListingController::class, 'uploadImage']);
        Route::delete('listings/{id}/images', [ListingController::class, 'deleteImage']);
        Route::apiResource('listings', ListingController::class);

        Route::apiResource('promocodes', PromocodeController::class);

        Route::get('promocodes/venues/search', [PromocodeController::class, 'searchVenues']);
        Route::get('promocodes/listings/search', [PromocodeController::class, 'searchListings']);
        Route::post('promocodes/validate', [PromocodeController::class, 'validate']);
        Route::post('promocodes/{promocode}/apply', [PromocodeController::class, 'apply']);
        Route::post('promocodes/{booking}/remove', [PromocodeController::class, 'remove']);

        Route::get('extra-data', [HelperController::class, 'extraData']);
        Route::get('extra-customers', [HelperController::class, 'extraCustomers']);
        Route::get('extra-resources', [HelperController::class, 'extraResources']);
        Route::get('extra-add-ons', [HelperController::class, 'extraAddOns']);
        Route::get('extra-sport-categories', [HelperController::class, 'extraSportCategories']);
        Route::get('extra-data-for-listing', [HelperController::class, 'extraDataForListing']);
        Route::get('extra-loyalty-data', [LoyaltyPointController::class, 'extraDataForLoyalty']);
        Route::get('extra-special-days', [HelperController::class, 'extraSpecialDays']);

        Route::post('test-email', [SettingController::class, 'sendTestEmail']);

        Route::get('dashboard-data', [BookingController::class, 'dashboardData']);
        Route::get('dashboard/chart', [BookingController::class, 'dashboardChart']);

        // Admin Mobile App
        Route::get('bookings/list', [BookingController::class, 'mobileBookingsList']);
        Route::get('bookings/filter-options', [BookingController::class, 'mobileBookingsFilterOptions']);

        Route::post('bookings/completed', [BookingController::class, 'completed']);
        Route::post('bookings/confirm', [BookingController::class, 'confirm']);
        Route::post('bookings/generate-invoice', [BookingController::class, 'generateInvoice']);
        Route::get('bookings/customer/{customerId}/history', [BookingController::class, 'customerHistory']);
        Route::get('bookings/form-data', [BookingController::class, 'formData']);
        Route::get('bookings/calendar', [BookingController::class, 'bookingsForCalendar']);
        Route::post('bookings/cancel', [BookingController::class, 'cancel']);
        Route::get('bookings/due/{customer_id?}', [BookingController::class, 'customerDueBookings']);
        Route::apiResource('bookings', BookingController::class);

        Route::post('payments/confirm', [PaymentController::class, 'confirmPayment']);
        Route::post('payments/generate-link', [PaymentController::class, 'generatePaymentLink']);
        Route::get('payments/{id}/invoice', [PaymentController::class, 'downloadInvoice']);
        Route::apiResource('payments', PaymentController::class);

        Route::apiResource('special-days', SpecialDayController::class);
        Route::get('special-days/calendar/{venueId}', [SpecialDayController::class, 'calendar']);
        Route::post('special-days/bulk', [SpecialDayController::class, 'bulkCreate']);

        Route::apiResource('customers', CustomerController::class);
        Route::put('customers/{id}/tags', [CustomerController::class, 'updateTags']);
        Route::put('customers/{id}/status', [CustomerController::class, 'updateStatus']);

    });

    Route::prefix('customer')->group(function () {

        Route::apiResource('reviews', ReviewController::class)->except(['show', 'index']);
        Route::get('reviews/my', [ReviewController::class, 'myReviews']);
        Route::patch('reviews/{review}/helpful', [ReviewController::class, 'helpful']);
        Route::get('bookings/reviewable', [ReviewController::class, 'reviewableBookings']);

        Route::get('gift-cards', [GiftCardController::class, 'fetchGiftCards']);
        Route::post('gift-cards/redeem', [GiftCardController::class, 'redeemGiftCard']);

        Route::post('bookings/validate', [BookingController::class, 'validateBooking']);
        Route::post('bookings/confirm', [BookingController::class, 'confirmBooking']);
        Route::get('bookings/upcoming', [BookingController::class, 'upcoming']);
        Route::get('bookings/recent', [BookingController::class, 'recent']);
        Route::post('bookings/{id}/pay', [BookingController::class, 'pay']);
        Route::post('bookings/{id}/cancel', [BookingController::class, 'cancel']);
        Route::post('payments/create-payment-intent', [PaymentController::class, 'createPaymentIntent']);

    });

});

Route::prefix('customer')->group(function () {

    Route::get('reviews', [ReviewController::class, 'index']);
    Route::get('reviews/my', [ReviewController::class, 'myReviews']);
    Route::post('login', [UserController::class, 'customerLogin']);
    Route::post('google-login', [UserController::class, 'googleLogin']);

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('preferences', [UserController::class, 'customerPreferences']);
        Route::post('preferences', [UserController::class, 'updatePreferences']);
        Route::get('profile', [UserController::class, 'getCustomerProfile']);
        Route::post('update-profile', [UserController::class, 'updateCustomerProfile']);
        Route::post('update-profile-image', [UserController::class, 'updateProfileImage']);
        Route::get('payment-methods', [PaymentController::class, 'getPaymentMethods']);
    });

});

Route::get('home-page-data', [HelperController::class, 'homePageData']);
Route::get('blog-categories', [PostCategoryController::class, 'blogCategories']);
Route::get('blog-articles', [PostController::class, 'blogArticles']);
Route::get('blog-articles/{slug}', [PostController::class, 'blogPostDetails']);
Route::get('sports-categories', [SportsCategoryController::class, 'sportsCategories']);
Route::post('contact-us', [HelperController::class, 'contactUs']);
Route::get('fetch-venues', [VenueController::class, 'fetchVenues']);
Route::get('fetch-listings', [ListingController::class, 'fetchListings']);
Route::get('fetch-suggested-listings', [ListingController::class, 'fetchSuggestedListings']);
Route::get('fetch-listing/{id}', [ListingController::class, 'fetchListing']);
Route::get('fetch-time-slots', [ListingController::class, 'fetchTimeSlots']);
Route::get('fetch-addons/{id}', [ListingController::class, 'fetchAddons']);
Route::get('fetch-recurring-booked-slots', [ListingController::class, 'fetchRecurringBookedSlots']);
Route::post('promocodes/apply', [PromocodeController::class, 'applyPromocode']);

Route::post('newsletter/subscribe', [NewsletterController::class, 'subscribe']);

