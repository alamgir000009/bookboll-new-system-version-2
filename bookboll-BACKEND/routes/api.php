<?php

use App\Http\Controllers\AddOnController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\GiftCardController;
use App\Http\Controllers\HelperController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\PostCategoryController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PromocodeController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SpecialDayController;
use App\Http\Controllers\SportsCategoryController;
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

        Route::apiResource('venues', VenueController::class);

        Route::apiResource('users', UserController::class);
        Route::get('users/required-data', [UserController::class, 'requiredDataForUser']);
        Route::post('users/update-profile', [UserController::class, 'updateProfile']);

        Route::get('settings', [SettingController::class, 'index']);
        Route::post('settings/{key}', [SettingController::class, 'updateSingle']);
        Route::post('settings', [SettingController::class, 'update']);

        Route::apiResource('sports-categories', SportsCategoryController::class);
        Route::get('sports-categories/popular-emojis', [SportsCategoryController::class, 'popularEmojis']);

        Route::apiResource('gift-cards', GiftCardController::class);

        Route::apiResource('resources', ResourceController::class);

        Route::get('audit-logs', [AuditLogController::class, 'index']);
        Route::get('audit-logs/stats', [AuditLogController::class, 'stats']);
        Route::get('audit-logs/export', [AuditLogController::class, 'export']);

        Route::apiResource('add-ons', AddOnController::class);

        Route::apiResource('listings', ListingController::class);

        Route::apiResource('promocodes', PromocodeController::class);

        Route::post('promocodes/{promocode}/validate', [PromocodeController::class, 'validate']);
        Route::post('promocodes/{promocode}/apply', [PromocodeController::class, 'apply']);
        Route::get('promocodes/venues/search', [PromocodeController::class, 'searchVenues']);
        Route::get('promocodes/listings/search', [PromocodeController::class, 'searchListings']);

        Route::get('extra-data', [HelperController::class, 'extraData']);
        Route::get('extra-customers', [HelperController::class, 'extraCustomers']);
        Route::get('extra-resources', [HelperController::class, 'extraResources']);
        Route::get('extra-add-ons', [HelperController::class, 'extraAddOns']);
        Route::get('extra-sport-categories', [HelperController::class, 'extraSportCategories']);
        Route::get('extra-data-for-listing', [HelperController::class, 'extraDataForListing']);
        Route::post('test-email', [SettingController::class, 'sendTestEmail']);

        Route::apiResource('bookings', BookingController::class);
        Route::put('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');

        Route::apiResource('special-days', SpecialDayController::class);
        Route::get('special-days/calendar/{venueId}', [SpecialDayController::class, 'calendar']);
        Route::post('special-days/bulk', [SpecialDayController::class, 'bulkCreate']);

        Route::apiResource('customers', CustomerController::class);
        Route::put('customers/{id}/tags', [CustomerController::class, 'updateTags']);
        Route::put('customers/{id}/status', [CustomerController::class, 'updateStatus']);

    });

    Route::prefix('customer')->group(function () {

        Route::apiResource('reviews', ReviewController::class)->except(['show']);
        Route::get('reviews/my', [ReviewController::class, 'myReviews']);
        Route::patch('reviews/{review}/helpful', [ReviewController::class, 'helpful']);
        Route::get('bookings/reviewable', [ReviewController::class, 'reviewableBookings']);

        Route::get('gift-cards', [GiftCardController::class, 'fetchGiftCards']);
        Route::post('gift-cards/redeem', [GiftCardController::class, 'redeemGiftCard']);

    });

});

Route::prefix('customer')->group(function () {

    Route::post('login', [UserController::class, 'customerLogin']);
    Route::post('google-login', [UserController::class, 'googleLogin']);

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('preferences', [UserController::class, 'customerPreferences']);
        Route::post('preferences', [UserController::class, 'updatePreferences']);
        Route::post('update-profile', [UserController::class, 'updateCustomerProfile']);
        Route::post('update-profile-image', [UserController::class, 'updateProfileImage']);
    });

});

Route::get('home-page-data', [HelperController::class, 'homePageData']);
Route::get('blog-categories', [PostCategoryController::class, 'blogCategories']);
Route::get('blog-articles', [PostController::class, 'blogArticles']);
Route::get('sports-categories', [SportsCategoryController::class, 'sportsCategories']);
Route::post('contact-us', [HelperController::class, 'contactUs']);
Route::get('fetch-venues', [VenueController::class, 'fetchVenues']);
Route::get('fetch-listings', [ListingController::class, 'fetchListings']);
Route::get('fetch-suggested-listings', [ListingController::class, 'fetchSuggestedListings']);
