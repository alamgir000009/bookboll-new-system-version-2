<?php

use App\Http\Controllers\AddOnController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\Admin\GeographyController;
use App\Http\Controllers\Admin\NotificationTemplateController;
use App\Http\Controllers\BusinessNotificationTemplateController;
use App\Http\Controllers\GiftCardController;
use App\Http\Controllers\HelperController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\LocationCityController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\LocationCountryController;
use App\Http\Controllers\LocationStateController;
use App\Http\Controllers\LoyaltyPointController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PostCategoryController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PromocodeController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SpecialDayController;
use App\Http\Controllers\SportsCategoryController;
use App\Http\Controllers\SportsTypeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VenueController;
use Illuminate\Support\Facades\Route;

Route::post('login', [UserController::class, 'login'])->name('login');
Route::post('register', [UserController::class, 'register'])->name('register');
Route::post('update-profile', [UserController::class, 'updateProfile'])->middleware('auth:sanctum')->name('update-profile');
Route::post('change-password', [UserController::class, 'changePassword'])->middleware('auth:sanctum')->name('change-password');
Route::post('forgot-password', [UserController::class, 'sendResetLinkEmail'])->name('forgot-password');
Route::post('reset-password', [UserController::class, 'reset'])->name('reset-password');

Route::post('admin-app/register', [UserController::class, 'registerAdminAppUser'])->name('admin-app.register');
Route::post('admin-app/forgot-password', [UserController::class, 'adminAppForgotPassword'])->name('admin-app.forgot-password');
Route::post('admin-app/reset-password', [UserController::class, 'adminAppResetPassword'])->name('admin-app.reset-password');

// Bookboll for Business — public provider signup
Route::prefix('business')->name('business.')->group(function () {
    Route::post('register', [BusinessController::class, 'register'])->name('register');
    Route::get('username-available', [BusinessController::class, 'usernameAvailable'])->name('username-available');
    Route::get('countries', [BusinessController::class, 'countries'])->name('countries');
});

Route::middleware(['auth:sanctum'])->group(function () {

    Route::post('logout', [UserController::class, 'logout'])->name('logout');
    Route::post('admin-app/delete-account', [UserController::class, 'deleteAdminAppAccount'])->name('admin-app.delete-account');
    Route::get('me', [UserController::class, 'me'])->name('me');
    Route::post('subscribe-onesignal', [UserController::class, 'subscribeOneSignal'])->name('customer.subscribe-onesignal');

    Route::put('business/brand', [BusinessController::class, 'updateBrand'])->name('business.brand.update');
    Route::get('business/notification-templates', [BusinessNotificationTemplateController::class, 'show'])->name('business.notification-templates.show');
    Route::put('business/notification-templates', [BusinessNotificationTemplateController::class, 'update'])->name('business.notification-templates.update');

    Route::prefix('business/team')->name('business.team.')->group(function () {
        Route::get('/', [BusinessController::class, 'teamIndex'])->name('index');
        Route::post('/', [BusinessController::class, 'teamStore'])->name('store');
        Route::put('{user}', [BusinessController::class, 'teamUpdate'])->name('update');
        Route::delete('{user}', [BusinessController::class, 'teamDestroy'])->name('destroy');
    });

    Route::prefix('admin')->middleware(['checkRoles:super_admin,country_admin,platform_staff,admin,senior_employee,junior_employee,guard'])->group(function () {

        Route::apiResource('post-categories', PostCategoryController::class);
        Route::apiResource('posts', PostController::class);
        Route::post('posts/change-status', [PostController::class, 'changeStatus']);
        Route::get('posts-tags', [PostController::class, 'postTagsList']);

        Route::get('locations/countries', [LocationController::class, 'countries'])->name('locations.countries');
        Route::get('locations/states', [LocationController::class, 'states'])->name('locations.states');
        Route::get('locations/cities', [LocationController::class, 'cities'])->name('locations.cities');

        Route::prefix('location-management')->name('location-management.')->middleware('role:super_admin')->group(function () {
            Route::apiResource('countries', LocationCountryController::class);
            Route::apiResource('states', LocationStateController::class);
            Route::apiResource('cities', LocationCityController::class);
        });

        // Countries & Cities admin page (World package + commission rates)
        Route::prefix('geography')->name('geography.')->group(function () {
            Route::get('countries', [GeographyController::class, 'countries'])->name('countries');
            Route::get('cities', [GeographyController::class, 'cities'])->name('cities');
            Route::patch('countries/{id}/status', [GeographyController::class, 'updateCountryStatus'])->name('countries.status');
            Route::patch('cities/{id}/status', [GeographyController::class, 'updateCityStatus'])->name('cities.status');
            Route::post('cities/bulk-status', [GeographyController::class, 'bulkUpdateCityStatus'])->name('cities.bulk-status');
            Route::put('commission-rates', [GeographyController::class, 'upsertCommission'])->name('commission-rates');
        });

        Route::prefix('roles')->name('roles.')->middleware('role:super_admin')->group(function () {
            Route::get('catalog', [RolePermissionController::class, 'catalog'])->name('catalog');
            Route::get('/', [RolePermissionController::class, 'index'])->name('index');
            Route::post('/', [RolePermissionController::class, 'store'])->name('store');
            Route::get('{role}', [RolePermissionController::class, 'show'])->name('show');
            Route::put('{role}/permissions', [RolePermissionController::class, 'updatePermissions'])->name('permissions.update');
            Route::delete('{role}', [RolePermissionController::class, 'destroy'])->name('destroy');
        });

        Route::post('venues/sort', [VenueController::class, 'sort'])->name('venues.sort');
        Route::post('venues/{id}/move', [VenueController::class, 'move'])->name('venues.move');
        Route::get('fetch-venues', [VenueController::class, 'adminFetchVenues'])->name('fetch-venues');
        Route::apiResource('venues', VenueController::class);

        Route::get('registrations/requests', [UserController::class, 'listPendingUsers'])->name('users.pending');
        Route::post('registrations/{id}/approve', [UserController::class, 'approveUser'])->name('users.approve');
        Route::post('registrations/{id}/reject', [UserController::class, 'rejectUser'])->name('users.reject');

        Route::apiResource('users', UserController::class);
        Route::get('users/required-data', [UserController::class, 'requiredDataForUser']);
        Route::get('businesses', [BusinessController::class, 'adminIndex'])->name('businesses.index');
        Route::post('users/update-profile', [UserController::class, 'updateProfile']);
        Route::post('customers/register', [CustomerController::class, 'createCustomer']);

        Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
        Route::post('settings/{key}', [SettingController::class, 'updateSingle'])->name('settings.updateSingle');
        Route::post('settings', [SettingController::class, 'update'])->name('settings.update');

        Route::get('notification-templates', [NotificationTemplateController::class, 'showPlatform'])->name('notification-templates.show');
        Route::put('notification-templates', [NotificationTemplateController::class, 'updatePlatform'])->name('notification-templates.update');

        Route::apiResource('sports-types', SportsTypeController::class)->only(['store', 'update', 'destroy']);
        Route::post('sports-types/sort', [SportsTypeController::class, 'sort'])->name('sports-types.sort');

        Route::post('sports-categories/sort', [SportsCategoryController::class, 'sort'])->name('sports-categories.sort');
        Route::apiResource('sports-categories', SportsCategoryController::class);
        Route::get('sports-categories/popular-emojis', [SportsCategoryController::class, 'popularEmojis'])->name('sports-categories.popular-emojis');

        Route::apiResource('gift-cards', GiftCardController::class);

        Route::apiResource('resources', ResourceController::class);

        Route::get('audit-logs', [AuditLogController::class, 'index']);
        Route::post('audit-logs', [AuditLogController::class, 'store']);
        Route::get('audit-logs/stats', [AuditLogController::class, 'stats']);
        Route::get('audit-logs/export', [AuditLogController::class, 'export']);

        Route::post('add-ons/sort', [AddOnController::class, 'sort'])->name('add-ons.sort');
        Route::apiResource('add-ons', AddOnController::class);

        Route::post('listings/sort', [ListingController::class, 'sort'])->name('listings.sort');
        Route::post('listings/{id}/images', [ListingController::class, 'uploadImage'])->name('listings.uploadImage');
        Route::delete('listings/{id}/images', [ListingController::class, 'deleteImage'])->name('listings.deleteImage');
        Route::apiResource('listings', ListingController::class);

        Route::get('promocodes/options', [PromocodeController::class, 'options'])->name('promocodes.options');
        Route::get('promocodes/venues/search', [PromocodeController::class, 'searchVenues'])->name('promocodes.venues.search');
        Route::get('promocodes/listings/search', [PromocodeController::class, 'searchListings'])->name('promocodes.listings.search');
        Route::post('promocodes/validate', [PromocodeController::class, 'validate'])->name('promocodes.validate');
        Route::get('promocodes/{promocode}/redemptions', [PromocodeController::class, 'redemptions'])->name('promocodes.redemptions');
        Route::post('promocodes/{promocode}/redemptions', [PromocodeController::class, 'storeRedemption'])->name('promocodes.redemptions.store');
        Route::post('promocodes/{promocode}/apply', [PromocodeController::class, 'apply'])->name('promocodes.apply');
        Route::post('promocodes/{booking}/remove', [PromocodeController::class, 'remove'])->name('promocodes.remove');
        Route::apiResource('promocodes', PromocodeController::class);

        Route::get('extra-data', [HelperController::class, 'extraData'])->name('extra-data');
        Route::get('extra-customers', [HelperController::class, 'extraCustomers'])->name('extra-customers');
        Route::get('extra-resources', [HelperController::class, 'extraResources'])->name('extra-resources');
        Route::get('extra-add-ons', [HelperController::class, 'extraAddOns'])->name('extra-add-ons');
        Route::get('extra-sport-categories', [HelperController::class, 'extraSportCategories'])->name('extra-sport-categories');
        Route::get('extra-data-for-listing', [HelperController::class, 'extraDataForListing'])->name('extra-data-for-listing');
        Route::get('extra-loyalty-data', [LoyaltyPointController::class, 'extraDataForLoyalty'])->name('extra-loyalty-data');
        Route::get('extra-special-days', [HelperController::class, 'extraSpecialDays'])->name('extra-special-days');

        Route::get('dashboard-data', [BookingController::class, 'dashboardData'])->name('dashboard-data');
        Route::get('dashboard/chart', [BookingController::class, 'dashboardChart'])->name('dashboard.chart');

        Route::get('bookings/list', [BookingController::class, 'mobileBookingsList'])->name('bookings.list');
        Route::get('bookings/filter-options', [BookingController::class, 'mobileBookingsFilterOptions'])->name('bookings.filter-options');

        Route::post('bookings/completed', [BookingController::class, 'completed'])->name('bookings.completed');
        Route::post('bookings/confirm', [BookingController::class, 'confirm'])->name('bookings.confirm');
        Route::post('bookings/generate-invoice', [BookingController::class, 'generateInvoice'])->name('bookings.generate-invoice');
        Route::get('bookings/customer/{customerId}/history', [BookingController::class, 'customerHistory'])->name('bookings.customer.history');
        Route::get('bookings/form-data', [BookingController::class, 'formData'])->name('bookings.form-data');
        Route::get('bookings/payment-methods', [BookingController::class, 'getPaymentMethods'])->name('bookings.payment-methods');
        Route::get('bookings/calendar', [BookingController::class, 'bookingsForCalendar'])->name('bookings.calendar');
        Route::post('bookings/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
        Route::get('bookings/due/{customer_id?}', [BookingController::class, 'customerDueBookings'])->name('bookings.due');
        Route::get('bookings/export', [BookingController::class, 'export'])->name('bookings.export');
        Route::apiResource('bookings', BookingController::class);

        Route::post('payments/confirm', [PaymentController::class, 'confirmPayment'])->name('payments.confirm');
        Route::post('payments/generate-link', [PaymentController::class, 'generatePaymentLink'])->name('payments.generate-link');
        Route::post('payments/mark-booking-paid', [PaymentController::class, 'markBookingAsPaid'])->name('payments.mark-booking-paid');
        Route::post('payments/{id}/mark-received', [PaymentController::class, 'markPaymentAsReceived'])->name('payments.mark-received');
        Route::get('payments/{id}/invoice', [PaymentController::class, 'downloadInvoice'])->name('payments.invoice');
        Route::get('payments/export', [PaymentController::class, 'export'])->name('payments.export');
        Route::apiResource('payments', PaymentController::class);

        Route::apiResource('special-days', SpecialDayController::class);
        Route::get('special-days/calendar/{venueId}', [SpecialDayController::class, 'calendar'])->name('special-days.calendar');
        Route::post('special-days/bulk', [SpecialDayController::class, 'bulkCreate'])->name('special-days.bulk');

        Route::get('customers/export', [CustomerController::class, 'export'])->name('customers.export');
        Route::get('customers/list', [CustomerController::class, 'listOptimized'])->name('customers.listOptimized');
        Route::get('customers/{id}/profile', [CustomerController::class, 'adminProfile'])->name('customers.profile');
        Route::get('customers/{id}/bookings', [CustomerController::class, 'adminBookings'])->name('customers.bookings');
        Route::put('customers/{id}/tags', [CustomerController::class, 'updateTags'])->name('customers.updateTags');
        Route::put('customers/{id}/status', [CustomerController::class, 'updateStatus'])->name('customers.updateStatus');
        Route::apiResource('customers', CustomerController::class);

    });

    Route::prefix('customer')->group(function () {

        Route::get('my-reviews', [ReviewController::class, 'myReviews'])->name('customer.my-reviews');
        Route::patch('reviews/{review}/helpful', [ReviewController::class, 'helpful'])->name('reviews.helpful');
        Route::apiResource('reviews', ReviewController::class)->except(['show', 'index']);

        Route::get('gift-cards', [GiftCardController::class, 'fetchGiftCards'])->name('customer.gift-cards.index');
        Route::post('gift-cards/redeem', [GiftCardController::class, 'redeemGiftCard'])->name('gift-cards.redeem');
        Route::get('gift-cards/balance', [GiftCardController::class, 'getBalance'])->name('gift-cards.balance');
        Route::get('gift-cards/promocodes', [GiftCardController::class, 'getGiftCardPromocodes'])->name('gift-cards.promocodes');

        Route::get('bookings/reviewable', [ReviewController::class, 'reviewableBookings'])->name('bookings.reviewable');
        Route::post('bookings/validate', [BookingController::class, 'validateBooking'])->name('bookings.validate');
        Route::post('bookings/confirm', [BookingController::class, 'confirmBooking'])->name('bookings.confirmBooking');
        Route::get('bookings/upcoming', [BookingController::class, 'upcoming'])->name('bookings.upcoming');
        Route::get('bookings/recent', [BookingController::class, 'recent'])->name('bookings.recent');
        Route::get('bookings/by-ids', [BookingController::class, 'getBookingsByIds'])->name('bookings.byIds');
        Route::post('bookings/cancel', [BookingController::class, 'cancel'])->name('customer.bookings.cancel');
        Route::put('bookings/{id}/update', [BookingController::class, 'mobileUpdateBooking'])->name('bookings.mobileUpdate');
        Route::post('payments/create-payment-intent', [PaymentController::class, 'createPaymentIntent'])->name('payments.createPaymentIntent');
        Route::post('payments/create-checkout-session', [PaymentController::class, 'createCheckoutSession'])->name('payments.createCheckoutSession');

        Route::get('promocodes', [PromocodeController::class, 'customerIndex'])->name('promocodes.customer.index');
    });

});

Route::prefix('customer')->group(function () {

    Route::get('reviews', [ReviewController::class, 'index'])->name('customer.reviews.index');
    Route::get('reviews/my', [ReviewController::class, 'myReviews'])->name('customer.reviews.my');
    Route::post('login', [UserController::class, 'customerLogin'])->name('customer.login');
    Route::post('google-login', [UserController::class, 'googleLogin'])->name('customer.google-login');
    Route::post('forgot-password', [UserController::class, 'customerForgotPassword'])->name('customer.forgot-password');
    Route::post('reset-password', [UserController::class, 'customerResetPassword'])->name('customer.reset-password');

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('preferences', [UserController::class, 'customerPreferences'])->name('customer.preferences');
        Route::post('preferences', [UserController::class, 'updatePreferences'])->name('customer.updatePreferences');
        Route::get('profile', [UserController::class, 'getCustomerProfile'])->name('customer.profile');
        Route::post('update-profile', [UserController::class, 'updateCustomerProfile'])->name('customer.updateCustomerProfile');
        Route::post('update-profile-image', [UserController::class, 'updateProfileImage'])->name('customer.updateProfileImage');
        Route::get('payment-methods', [PaymentController::class, 'getPaymentMethods'])->name('customer.payment-methods');
    });

});

Route::get('home-page-data', [HelperController::class, 'homePageData'])->name('home-page-data');
Route::get('blog-categories', [PostCategoryController::class, 'blogCategories'])->name('blog-categories');
Route::get('blog-articles', [PostController::class, 'blogArticles'])->name('blog-articles');
Route::get('blog-articles/{slug}', [PostController::class, 'blogPostDetails'])->name('blog-articles.show');
Route::get('sports-categories', [SportsCategoryController::class, 'sportsCategories'])->name('sports-categories.public');
Route::post('contact-us', [HelperController::class, 'contactUs'])->name('contact-us');
Route::get('fetch-venues', [VenueController::class, 'fetchVenues'])->name('customer.fetch-venues');
Route::get('fetch-listings', [ListingController::class, 'fetchListings'])->name('fetch-listings');
Route::get('fetch-suggested-listings', [ListingController::class, 'fetchSuggestedListings'])->name('fetch-suggested-listings');
Route::get('fetch-listing/{id}', [ListingController::class, 'fetchListing'])->name('fetch-listing');
Route::get('fetch-time-slots', [ListingController::class, 'fetchTimeSlots'])->name('fetch-time-slots');
Route::post('lock-slot', [ListingController::class, 'lockSlot'])->name('lock-slot');
Route::post('unlock-slot', [ListingController::class, 'unlockSlot'])->name('unlock-slot');
Route::post('unlock-all-slots', [ListingController::class, 'unlockAllSlots'])->name('unlock-all-slots');
Route::post('extend-slot-lock', [ListingController::class, 'extendSlotLock'])->name('extend-slot-lock');
Route::post('check-slot-availability', [ListingController::class, 'checkSlotAvailability'])->name('check-slot-availability');
Route::get('fetch-addons/{id}', [ListingController::class, 'fetchAddons'])->name('fetch-addons');
Route::get('fetch-recurring-booked-slots', [ListingController::class, 'fetchRecurringBookedSlots'])->name('fetch-recurring-booked-slots');
Route::get('listings', [ListingController::class, 'mobileListingsList'])->name('mobile.listings')->middleware('auth:sanctum');
Route::post('promocodes/apply', [PromocodeController::class, 'applyPromocode'])->name('promocodes.applyPromocode');

Route::post('newsletter/subscribe', [NewsletterController::class, 'subscribe'])->name('newsletter.subscribe');
