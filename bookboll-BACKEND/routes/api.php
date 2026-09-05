<?php

use App\Http\Controllers\AddOnController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\Admin\GeographyController;
use App\Http\Controllers\Admin\NotificationTemplateController;
use App\Http\Controllers\BusinessNotificationTemplateController;
use App\Http\Controllers\CheckInController;
use App\Http\Controllers\NotificationFeedController;
use App\Http\Controllers\GiftCardController;
use App\Http\Controllers\HelperController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\ClassMembershipController;
use App\Http\Controllers\ApprovalsController;
use App\Http\Controllers\WalletPayoutController;
use App\Http\Controllers\EventModerationController;
use App\Http\Controllers\CommunityModerationController;
use App\Http\Controllers\CustomerSocialController;
use App\Http\Controllers\CustomerCommunityController;
use App\Http\Controllers\CustomerGameController;
use App\Http\Controllers\HostPayoutController;
use App\Http\Controllers\CustomerProgramController;
use App\Http\Controllers\PublicProfileController;
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
use App\Http\Controllers\MarketplaceCatalogController;
use App\Http\Controllers\VenueController;
use App\Http\Controllers\WhatsappController;
use Illuminate\Support\Facades\Route;

Route::post('login', [UserController::class, 'login'])->name('api.login');
Route::post('register', [UserController::class, 'register'])->name('api.register');
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

    Route::post('logout', [UserController::class, 'logout'])->name('api.logout');
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
            Route::get('loyalty-programs', [GeographyController::class, 'loyaltyPrograms'])->name('loyalty-programs.index');
            Route::put('loyalty-programs', [GeographyController::class, 'upsertLoyaltyProgram'])->name('loyalty-programs');
        });

        Route::prefix('roles')->name('roles.')->middleware('role:super_admin')->group(function () {
            Route::get('catalog', [RolePermissionController::class, 'catalog'])->name('catalog');
            Route::get('/', [RolePermissionController::class, 'index'])->name('index');
            Route::post('/', [RolePermissionController::class, 'store'])->name('store');
            Route::get('{role}', [RolePermissionController::class, 'show'])->name('show');
            Route::put('{role}/permissions', [RolePermissionController::class, 'updatePermissions'])->name('permissions.update');
            Route::delete('{role}', [RolePermissionController::class, 'destroy'])->name('destroy');
        });

        Route::get('venues/dashboard', [VenueController::class, 'dashboard'])->name('venues.dashboard');
        Route::post('venues/dashboard', [VenueController::class, 'dashboardStore'])->name('venues.dashboard.store');
        Route::post('venues/dashboard/sort', [VenueController::class, 'dashboardSort'])->name('venues.dashboard.sort');
        Route::get('venues/dashboard/{id}', [VenueController::class, 'dashboardShow'])->name('venues.dashboard.show');
        Route::put('venues/dashboard/{id}', [VenueController::class, 'dashboardUpdate'])->name('venues.dashboard.update');
        Route::patch('venues/dashboard/{id}', [VenueController::class, 'dashboardPatch'])->name('venues.dashboard.patch');
        Route::delete('venues/dashboard/{id}', [VenueController::class, 'dashboardDestroy'])->name('venues.dashboard.destroy');
        Route::post('venues/dashboard/{id}/apply-hours', [VenueController::class, 'dashboardApplyHours'])->name('venues.dashboard.apply-hours');
        Route::post('venues/sort', [VenueController::class, 'sort'])->name('venues.sort');
        Route::post('venues/{id}/move', [VenueController::class, 'move'])->name('venues.move');
        Route::get('fetch-venues', [VenueController::class, 'adminFetchVenues'])->name('fetch-venues');
        Route::apiResource('venues', VenueController::class);

        Route::get('listings/dashboard', [ListingController::class, 'dashboard'])->name('listings.dashboard');
        Route::post('listings/dashboard', [ListingController::class, 'dashboardStore'])->name('listings.dashboard.store');
        Route::post('listings/dashboard/sort', [ListingController::class, 'dashboardSort'])->name('listings.dashboard.sort');
        Route::get('listings/dashboard/{id}', [ListingController::class, 'dashboardShow'])->name('listings.dashboard.show');
        Route::put('listings/dashboard/{id}', [ListingController::class, 'dashboardUpdate'])->name('listings.dashboard.update');
        Route::patch('listings/dashboard/{id}', [ListingController::class, 'dashboardPatch'])->name('listings.dashboard.patch');
        Route::delete('listings/dashboard/{id}', [ListingController::class, 'dashboardDestroy'])->name('listings.dashboard.destroy');

        Route::get('approvals/dashboard', [ApprovalsController::class, 'dashboard'])->name('approvals.dashboard');
        Route::post('approvals/bulk-approve', [ApprovalsController::class, 'bulkApprove'])->name('approvals.bulk-approve');
        Route::post('approvals/{type}/{id}/approve', [ApprovalsController::class, 'approve'])->name('approvals.approve');
        Route::post('approvals/{type}/{id}/request-changes', [ApprovalsController::class, 'requestChanges'])->name('approvals.request-changes');

        Route::get('moderation/events/dashboard', [EventModerationController::class, 'dashboard'])->name('moderation.events.dashboard');
        Route::post('moderation/events/{id}/flag', [EventModerationController::class, 'flag'])->name('moderation.events.flag');
        Route::post('moderation/events/{id}/remove', [EventModerationController::class, 'remove'])->name('moderation.events.remove');
        Route::post('moderation/events/{id}/restore', [EventModerationController::class, 'restore'])->name('moderation.events.restore');

        Route::get('moderation/communities/dashboard', [CommunityModerationController::class, 'dashboard'])->name('moderation.communities.dashboard');
        Route::post('moderation/communities/{id}/flag', [CommunityModerationController::class, 'flag'])->name('moderation.communities.flag');
        Route::post('moderation/communities/{id}/remove', [CommunityModerationController::class, 'remove'])->name('moderation.communities.remove');
        Route::post('moderation/communities/{id}/restore', [CommunityModerationController::class, 'restore'])->name('moderation.communities.restore');

        Route::get('wallet-payouts/dashboard', [WalletPayoutController::class, 'dashboard'])->name('wallet-payouts.dashboard');
        Route::get('wallet-payouts/owners/{ownerUserId}/review', [WalletPayoutController::class, 'ownerReview'])->name('wallet-payouts.owners.review');
        Route::get('wallet-payouts/wallets', [WalletPayoutController::class, 'listWallets'])->name('wallet-payouts.wallets.index');
        Route::post('wallet-payouts/wallets', [WalletPayoutController::class, 'createWallet'])->name('wallet-payouts.wallets.store');
        Route::put('wallet-payouts/wallets/{id}', [WalletPayoutController::class, 'updateWallet'])->name('wallet-payouts.wallets.update');
        Route::delete('wallet-payouts/wallets/{id}', [WalletPayoutController::class, 'deleteWallet'])->name('wallet-payouts.wallets.destroy');
        Route::post('wallet-payouts/wallets/apply-method', [WalletPayoutController::class, 'applyMethod'])->name('wallet-payouts.wallets.apply-method');
        Route::post('wallet-payouts/kyc/{ownerUserId}/approve', [WalletPayoutController::class, 'approveKyc'])->name('wallet-payouts.kyc.approve');
        Route::post('wallet-payouts/kyc/{ownerUserId}/reject', [WalletPayoutController::class, 'rejectKyc'])->name('wallet-payouts.kyc.reject');
        Route::get('wallet-payouts/withdrawals', [WalletPayoutController::class, 'listWithdrawals'])->name('wallet-payouts.withdrawals.index');
        Route::post('wallet-payouts/withdrawals', [WalletPayoutController::class, 'createWithdrawal'])->name('wallet-payouts.withdrawals.store');
        Route::post('wallet-payouts/withdrawals/paid-record', [WalletPayoutController::class, 'createPaidWithdrawal'])->name('wallet-payouts.withdrawals.paid-record');
        Route::post('wallet-payouts/withdrawals/{id}/approve', [WalletPayoutController::class, 'approveWithdrawal'])->name('wallet-payouts.withdrawals.approve');
        Route::post('wallet-payouts/withdrawals/{id}/mark-paid', [WalletPayoutController::class, 'markWithdrawalPaid'])->name('wallet-payouts.withdrawals.mark-paid');
        Route::post('wallet-payouts/withdrawals/{id}/reject', [WalletPayoutController::class, 'rejectWithdrawal'])->name('wallet-payouts.withdrawals.reject');
        Route::post('wallet-payouts/withdrawals/{id}/cancel', [WalletPayoutController::class, 'cancelWithdrawal'])->name('wallet-payouts.withdrawals.cancel');
        Route::post('wallet-payouts/delta', [WalletPayoutController::class, 'applyDelta'])->name('wallet-payouts.delta');
        Route::get('wallet-payouts/transactions', [WalletPayoutController::class, 'listTransactions'])->name('wallet-payouts.transactions.index');
        Route::post('wallet-payouts/transactions', [WalletPayoutController::class, 'createTransaction'])->name('wallet-payouts.transactions.store');
        Route::put('wallet-payouts/transactions/{id}', [WalletPayoutController::class, 'updateTransaction'])->name('wallet-payouts.transactions.update');

        Route::get('subscriptions/dashboard', [ClassMembershipController::class, 'dashboard'])->name('subscriptions.dashboard');
        Route::post('subscriptions/dashboard', [ClassMembershipController::class, 'store'])->name('subscriptions.dashboard.store');
        Route::post('subscriptions/dashboard/bulk-cancel', [ClassMembershipController::class, 'bulkCancel'])->name('subscriptions.dashboard.bulk-cancel');
        Route::delete('subscriptions/programs/{id}', [ClassMembershipController::class, 'destroyProgram'])->name('subscriptions.programs.destroy');
        Route::get('subscriptions/dashboard/{id}', [ClassMembershipController::class, 'show'])->name('subscriptions.dashboard.show');
        Route::get('subscriptions/dashboard/{id}/payments', [ClassMembershipController::class, 'payments'])->name('subscriptions.dashboard.payments');
        Route::post('subscriptions/dashboard/{id}/charge', [ClassMembershipController::class, 'charge'])->name('subscriptions.dashboard.charge');
        Route::post('subscriptions/dashboard/{id}/retry-charge', [ClassMembershipController::class, 'retryCharge'])->name('subscriptions.dashboard.retry-charge');
        Route::post('subscriptions/dashboard/{id}/cancel', [ClassMembershipController::class, 'cancel'])->name('subscriptions.dashboard.cancel');
        Route::post('subscriptions/dashboard/{id}/pause', [ClassMembershipController::class, 'pause'])->name('subscriptions.dashboard.pause');
        Route::post('subscriptions/dashboard/{id}/resume', [ClassMembershipController::class, 'resume'])->name('subscriptions.dashboard.resume');
        Route::post('subscriptions/dashboard/{id}/mark-past-due', [ClassMembershipController::class, 'markPastDue'])->name('subscriptions.dashboard.mark-past-due');
        Route::post('subscriptions/dashboard/{id}/retention/month-off', [ClassMembershipController::class, 'retentionMonthOff'])->name('subscriptions.dashboard.retention');
        Route::post('subscriptions/dashboard/{id}/winback', [ClassMembershipController::class, 'winback'])->name('subscriptions.dashboard.winback');

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

        // Notification bell feed (derived from recent domain activity)
        Route::get('notifications/feed', [NotificationFeedController::class, 'index'])->name('notifications.feed');
        Route::post('notifications/read-all', [NotificationFeedController::class, 'markAllRead'])->name('notifications.read-all');

        // Door check-in (admin app QR scanner)
        Route::prefix('checkin')->name('checkin.')->group(function () {
            Route::get('lookup', [CheckInController::class, 'lookup'])->name('lookup');
            Route::get('recent', [CheckInController::class, 'recent'])->name('recent');
            Route::get('roster', [CheckInController::class, 'roster'])->name('roster');
            Route::post('attendance', [CheckInController::class, 'record'])->name('attendance');
            Route::post('mark-present', [CheckInController::class, 'markPresent'])->name('mark-present');
        });

        Route::get('audit-logs', [AuditLogController::class, 'index']);
        Route::post('audit-logs', [AuditLogController::class, 'store']);
        Route::get('audit-logs/stats', [AuditLogController::class, 'stats']);
        Route::get('audit-logs/export', [AuditLogController::class, 'export']);
        Route::post('notifications/whatsapp', [WhatsappController::class, 'send']);
        Route::get('notifications/owner-phone', [WhatsappController::class, 'ownerPhone']);

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
        Route::get('promocodes/{id}/redemptions', [PromocodeController::class, 'redemptions'])->name('promocodes.redemptions');
        Route::post('promocodes/{id}/redemptions', [PromocodeController::class, 'storeRedemption'])->name('promocodes.redemptions.store');
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

        Route::get('bookings/dashboard', [BookingController::class, 'dashboard'])->name('bookings.dashboard');
        Route::get('bookings/occupancy', [BookingController::class, 'occupancy'])->name('bookings.occupancy');
        Route::get('bookings/special-days', [BookingController::class, 'dashboardSpecialDays'])->name('bookings.special-days');
        Route::post('bookings/dashboard', [BookingController::class, 'dashboardStore'])->name('bookings.dashboard.store');
        Route::post('bookings/dashboard-cancel', [BookingController::class, 'dashboardCancel'])->name('bookings.dashboard.cancel');
        Route::post('bookings/dashboard-payment-link', [BookingController::class, 'dashboardPaymentLink'])->name('bookings.dashboard.payment-link');
        Route::get('bookings/{id}/crm', [BookingController::class, 'crm'])->name('bookings.crm');
        Route::put('bookings/{id}/dashboard', [BookingController::class, 'dashboardUpdate'])->name('bookings.dashboard.update');
        Route::put('bookings/{id}/payments/{paymentId}/collect', [BookingController::class, 'dashboardCollect'])->name('bookings.dashboard.collect');
        Route::get('bookings/list', [BookingController::class, 'mobileBookingsList'])->name('bookings.list');
        Route::get('bookings/filter-options', [BookingController::class, 'mobileBookingsFilterOptions'])->name('bookings.filter-options');

        Route::post('bookings/completed', [BookingController::class, 'completed'])->name('bookings.completed');
        Route::post('bookings/confirm', [BookingController::class, 'confirm'])->name('bookings.confirm');
        Route::post('bookings/generate-invoice', [BookingController::class, 'generateInvoice'])->name('bookings.generate-invoice');
        Route::get('bookings/customer/{customerId}/history', [BookingController::class, 'customerHistory'])->name('bookings.customer.history');
        Route::get('bookings/form-data', [BookingController::class, 'formData'])->name('bookings.form-data');
        Route::get('bookings/payment-methods', [BookingController::class, 'getPaymentMethods'])->name('bookings.payment-methods');
        Route::get('bookings/calendar', [BookingController::class, 'bookingsForCalendar'])->name('bookings.calendar');
        Route::get('bookings/calendar-dashboard', [BookingController::class, 'calendarDashboard'])->name('bookings.calendar-dashboard');
        Route::post('bookings/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
        Route::get('bookings/due/{customer_id?}', [BookingController::class, 'customerDueBookings'])->name('bookings.due');
        Route::get('bookings/export', [BookingController::class, 'export'])->name('bookings.export');
        Route::apiResource('bookings', BookingController::class);

        Route::post('payments/confirm', [PaymentController::class, 'confirmPayment'])->name('payments.confirm');
        Route::post('payments/generate-link', [PaymentController::class, 'generatePaymentLink'])->name('payments.generate-link');
        Route::post('payments/mark-booking-paid', [PaymentController::class, 'markBookingAsPaid'])->name('payments.mark-booking-paid');
        Route::get('payments/dashboard', [PaymentController::class, 'dashboard'])->name('payments.dashboard');
        Route::get('payments/export', [PaymentController::class, 'export'])->name('payments.export');
        Route::post('payments/{id}/mark-received', [PaymentController::class, 'markPaymentAsReceived'])->name('payments.mark-received');
        Route::get('payments/{id}/invoice', [PaymentController::class, 'downloadInvoice'])->name('payments.invoice');
        Route::apiResource('payments', PaymentController::class);

        Route::get('special-days/dashboard', [SpecialDayController::class, 'dashboard'])->name('special-days.dashboard');
        Route::post('special-days/dashboard', [SpecialDayController::class, 'dashboardStore'])->name('special-days.dashboard.store');
        Route::put('special-days/dashboard/{id}', [SpecialDayController::class, 'dashboardUpdate'])->name('special-days.dashboard.update');
        Route::delete('special-days/dashboard/{id}', [SpecialDayController::class, 'dashboardDestroy'])->name('special-days.dashboard.destroy');
        Route::post('special-days/dashboard-clear', [SpecialDayController::class, 'dashboardBulkClear'])->name('special-days.dashboard.clear');
        Route::post('special-days/dashboard-conflicts', [SpecialDayController::class, 'dashboardConflicts'])->name('special-days.dashboard.conflicts');
        Route::get('special-days/calendar/{venueId}', [SpecialDayController::class, 'calendar'])->name('special-days.calendar');
        Route::post('special-days/bulk', [SpecialDayController::class, 'bulkCreate'])->name('special-days.bulk');
        Route::apiResource('special-days', SpecialDayController::class);

        Route::get('customers/dashboard', [CustomerController::class, 'dashboard'])->name('customers.dashboard');
        Route::get('customers/export', [CustomerController::class, 'export'])->name('customers.export');
        Route::get('customers/list', [CustomerController::class, 'listOptimized'])->name('customers.listOptimized');
        Route::get('customers/{id}/crm', [CustomerController::class, 'crm'])->name('customers.crm');
        Route::get('customers/{id}/profile', [CustomerController::class, 'adminProfile'])->name('customers.profile');
        Route::get('customers/{id}/bookings', [CustomerController::class, 'adminBookings'])->name('customers.bookings');
        Route::put('customers/{id}/tags', [CustomerController::class, 'updateTags'])->name('customers.updateTags');
        Route::put('customers/{id}/status', [CustomerController::class, 'updateStatus'])->name('customers.updateStatus');
        Route::put('customers/{id}/loyalty', [CustomerController::class, 'replaceLoyalty'])->name('customers.loyalty.replace');
        Route::post('customers/{id}/loyalty', [CustomerController::class, 'upsertLoyalty'])->name('customers.loyalty.upsert');
        Route::delete('customers/{id}/loyalty/{countryId}', [CustomerController::class, 'deleteLoyalty'])->name('customers.loyalty.delete');
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
        Route::post('bookings/create', [BookingController::class, 'createCustomerBooking'])->name('customer.bookings.create');
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
    Route::post('apple-login', [UserController::class, 'appleLogin'])->name('customer.apple-login');
    Route::post('forgot-password', [UserController::class, 'customerForgotPassword'])->name('customer.forgot-password');
    Route::post('reset-password', [UserController::class, 'customerResetPassword'])->name('customer.reset-password');

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('preferences', [UserController::class, 'customerPreferences'])->name('customer.preferences');
        Route::post('preferences', [UserController::class, 'updatePreferences'])->name('customer.updatePreferences');
        Route::get('profile', [UserController::class, 'getCustomerProfile'])->name('customer.profile');
        Route::get('loyalty', [LoyaltyPointController::class, 'customerLoyalty'])->name('customer.loyalty');
        Route::post('update-profile', [UserController::class, 'updateCustomerProfile'])->name('customer.updateCustomerProfile');
        Route::post('update-profile-image', [UserController::class, 'updateProfileImage'])->name('customer.updateProfileImage');
        Route::get('payment-methods', [PaymentController::class, 'getPaymentMethods'])->name('customer.payment-methods');
        Route::post('delete-account', [UserController::class, 'deleteCustomerAccount'])->name('customer.delete-account');

        Route::post('social/follow/{uuid}', [CustomerSocialController::class, 'follow'])->name('customer.social.follow');
        Route::delete('social/follow/{uuid}', [CustomerSocialController::class, 'unfollow'])->name('customer.social.unfollow');
        Route::get('social/follows/{uuid}', [CustomerSocialController::class, 'followList'])->name('customer.social.follows');
        Route::post('social/follow-requests/{id}/respond', [CustomerSocialController::class, 'respondFollowRequest'])->name('customer.social.follow-respond');
        Route::post('social/posts', [CustomerSocialController::class, 'createPost'])->name('customer.social.posts.store');
        Route::delete('social/posts/{id}', [CustomerSocialController::class, 'deletePost'])->name('customer.social.posts.destroy');
        Route::post('social/street-cred', [CustomerCommunityController::class, 'giveStreetCred'])->name('customer.social.street-cred');
        Route::get('social/street-cred', [CustomerCommunityController::class, 'myPropsForGame'])->name('customer.social.street-cred.mine');
        Route::get('social/street-cred/counts', [CustomerCommunityController::class, 'streetCredCounts'])->name('customer.social.street-cred.counts');

        Route::get('communities/mine', [CustomerCommunityController::class, 'mine'])->name('customer.communities.mine');
        Route::get('communities/membership-check', [CustomerCommunityController::class, 'membershipCheck'])->name('customer.communities.membership-check');
        Route::get('communities/pending-members', [CustomerCommunityController::class, 'pendingMembers'])->name('customer.communities.pending-members');
        Route::post('communities', [CustomerCommunityController::class, 'store'])->name('customer.communities.store');
        Route::patch('communities/{uuid}', [CustomerCommunityController::class, 'update'])->name('customer.communities.update');
        Route::post('communities/{uuid}/join', [CustomerCommunityController::class, 'join'])->name('customer.communities.join');
        Route::delete('communities/{uuid}/leave', [CustomerCommunityController::class, 'leave'])->name('customer.communities.leave');
        Route::get('communities/{uuid}/members', [CustomerCommunityController::class, 'members'])->name('customer.communities.members');
        Route::post('communities/{uuid}/members/{memberUuid}/respond', [CustomerCommunityController::class, 'respondToMember'])->name('customer.communities.members.respond');
        Route::delete('communities/{uuid}/members/{memberUuid}', [CustomerCommunityController::class, 'removeMember'])->name('customer.communities.members.remove');
        Route::patch('communities/{uuid}/members/{memberUuid}/role', [CustomerCommunityController::class, 'setMemberRole'])->name('customer.communities.members.role');
        Route::post('community-members/{memberUuid}/respond', [CustomerCommunityController::class, 'respondToMemberById'])->name('customer.community-members.respond');
        Route::delete('community-members/{memberUuid}', [CustomerCommunityController::class, 'removeMemberById'])->name('customer.community-members.remove');
        Route::patch('community-members/{memberUuid}/role', [CustomerCommunityController::class, 'setMemberRoleById'])->name('customer.community-members.role');
        Route::get('communities/{uuid}/posts', [CustomerCommunityController::class, 'feed'])->name('customer.communities.posts.index');
        Route::post('communities/{uuid}/posts', [CustomerCommunityController::class, 'createPost'])->name('customer.communities.posts.store');
        Route::delete('communities/{uuid}/posts/{postUuid}', [CustomerCommunityController::class, 'deletePost'])->name('customer.communities.posts.destroy');
        Route::delete('community-posts/{postUuid}', [CustomerCommunityController::class, 'deletePostById'])->name('customer.community-posts.destroy');
        Route::post('communities/{uuid}/chat/read', [CustomerCommunityController::class, 'markChatRead'])->name('customer.communities.chat.read');
        Route::get('communities/{uuid}/chat/unread', [CustomerCommunityController::class, 'chatUnread'])->name('customer.communities.chat.unread');
        Route::get('messages/unread-count', [CustomerCommunityController::class, 'unreadMessages'])->name('customer.messages.unread');
        Route::get('notifications/feed', [CustomerGameController::class, 'notificationFeed'])->name('customer.notifications.feed');
        Route::get('messages/group', [CustomerCommunityController::class, 'loadGroupThread'])->name('customer.messages.group.index');
        Route::post('messages/group', [CustomerCommunityController::class, 'sendGroupMessage'])->name('customer.messages.group.store');

        Route::get('games/mine/due-matches', [CustomerGameController::class, 'dueMatches'])->name('customer.games.mine.due-matches');
        Route::get('games/mine/hosted', [CustomerGameController::class, 'mineHosted'])->name('customer.games.mine.hosted');
        Route::get('games/mine/joined', [CustomerGameController::class, 'mineJoined'])->name('customer.games.mine.joined');
        Route::get('stripe/payouts', [HostPayoutController::class, 'show'])->name('customer.stripe.payouts.show');
        Route::post('stripe/payouts/connect', [HostPayoutController::class, 'connect'])->name('customer.stripe.payouts.connect');
        Route::post('stripe/payouts/sync', [HostPayoutController::class, 'sync'])->name('customer.stripe.payouts.sync');
        Route::post('stripe/payouts/keys', [HostPayoutController::class, 'saveKeys'])->name('customer.stripe.payouts.keys');
        Route::delete('stripe/payouts', [HostPayoutController::class, 'destroy'])->name('customer.stripe.payouts.destroy');
        Route::post('games/quote', [CustomerGameController::class, 'quote'])->name('customer.games.quote');
        Route::post('games', [CustomerGameController::class, 'store'])->name('customer.games.store');
        Route::patch('games/{uuid}/community', [CustomerGameController::class, 'setCommunity'])->name('customer.games.community');
        Route::get('games/{uuid}/requests', [CustomerGameController::class, 'requests'])->name('customer.games.requests');
        Route::post('games/{uuid}/requests/accept-all', [CustomerGameController::class, 'acceptAllRequests'])->name('customer.games.requests.accept-all');
        Route::get('games/{uuid}/my-request', [CustomerGameController::class, 'myRequest'])->name('customer.games.my-request');
        Route::post('games/{uuid}/join', [CustomerGameController::class, 'join'])->name('customer.games.join');
        Route::patch('games/{uuid}', [CustomerGameController::class, 'update'])->name('customer.games.update');
        Route::post('games/{uuid}/cancel', [CustomerGameController::class, 'cancel'])->name('customer.games.cancel');
        Route::post('games/{uuid}/rounds', [CustomerGameController::class, 'saveRounds'])->name('customer.games.rounds');
        Route::patch('game-requests/{requestUuid}', [CustomerGameController::class, 'respondToRequest'])->name('customer.game-requests.respond');
        Route::delete('game-requests/{requestUuid}', [CustomerGameController::class, 'cancelRequest'])->name('customer.game-requests.cancel');
        Route::post('game-requests/{requestUuid}/attendance', [CustomerGameController::class, 'attendance'])->name('customer.game-requests.attendance');

        Route::post('tournaments', [CustomerGameController::class, 'storeTournament'])->name('customer.tournaments.store');
        Route::post('tournaments/{uuid}/matchups', [CustomerGameController::class, 'generateMatchups'])->name('customer.tournaments.matchups');
        Route::patch('tournament-matches/{matchUuid}/winner', [CustomerGameController::class, 'setMatchWinner'])->name('customer.tournament-matches.winner');
        Route::delete('tournament-matches/{matchUuid}/winner', [CustomerGameController::class, 'clearMatchWinner'])->name('customer.tournament-matches.winner.clear');

        Route::get('programs/mine', [CustomerProgramController::class, 'mine'])->name('customer.programs.mine');
        Route::get('programs/passes', [CustomerProgramController::class, 'passes'])->name('customer.programs.passes');
        Route::get('programs/offers/check', [CustomerProgramController::class, 'checkOffer'])->name('customer.programs.offers.check');
        Route::post('programs/offers/check', [CustomerProgramController::class, 'checkOffer'])->name('customer.programs.offers.check.post');
        Route::post('programs/{uuid}/enroll', [CustomerProgramController::class, 'enroll'])->name('customer.programs.enroll');
        Route::get('programs/checkout/{sessionId}', [CustomerProgramController::class, 'checkoutState'])->name('customer.programs.checkout-state');
        Route::post('programs/subscriptions/{uuid}/cancel', [CustomerProgramController::class, 'cancel'])->name('customer.programs.cancel');
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

Route::prefix('marketplace')->name('marketplace.')->group(function () {
    Route::get('listings', [MarketplaceCatalogController::class, 'listings'])->name('listings');
    Route::get('venues', [MarketplaceCatalogController::class, 'venues'])->name('venues');
    Route::get('venues/{id}', [MarketplaceCatalogController::class, 'venue'])->name('venues.show');
    Route::get('listings/{id}', [MarketplaceCatalogController::class, 'listing'])->name('listings.show');
    Route::get('platform-brand', [MarketplaceCatalogController::class, 'platformBrand'])->name('platform-brand');
    Route::get('countries', [MarketplaceCatalogController::class, 'countries'])->name('countries');
    Route::get('cities', [MarketplaceCatalogController::class, 'cities'])->name('cities');
    Route::get('cities/{id}', [MarketplaceCatalogController::class, 'city'])->name('cities.show');
    Route::get('directory', [MarketplaceCatalogController::class, 'directory'])->name('directory');
    Route::get('default-city', [MarketplaceCatalogController::class, 'defaultCity'])->name('default-city');
    Route::get('served-regions', [MarketplaceCatalogController::class, 'servedRegions'])->name('served-regions');
    Route::get('loyalty-config', [MarketplaceCatalogController::class, 'loyaltyConfig'])->name('loyalty-config');
    Route::get('profiles/{uuid}', [PublicProfileController::class, 'show'])->name('profiles.show');
    Route::get('communities/probe', [CustomerCommunityController::class, 'probe'])->name('communities.probe');
    Route::get('communities', [CustomerCommunityController::class, 'index'])->name('communities.index');
    Route::get('communities/{uuid}', [CustomerCommunityController::class, 'show'])->name('communities.show');
    Route::get('communities/{uuid}/members', [CustomerCommunityController::class, 'members'])->name('communities.members');
    Route::get('communities/{uuid}/posts', [CustomerCommunityController::class, 'feed'])->name('communities.posts');
    Route::get('communities/{uuid}/events', [CustomerGameController::class, 'communityEvents'])->name('communities.events');
    Route::get('games', [CustomerGameController::class, 'index'])->name('games.index');
    Route::get('games/config', [CustomerGameController::class, 'config'])->name('games.config');
    Route::get('tournaments/{uuid}', [CustomerGameController::class, 'showTournament'])->name('tournaments.show');
    Route::get('games/{uuid}', [CustomerGameController::class, 'show'])->name('games.show');
    Route::get('programs', [CustomerProgramController::class, 'index'])->name('programs.index');
    Route::get('programs/{uuid}', [CustomerProgramController::class, 'show'])->name('programs.show');
    Route::get('programs/{uuid}/hours', [CustomerProgramController::class, 'hours'])->name('programs.hours');
});
Route::get('fetch-suggested-listings', [ListingController::class, 'fetchSuggestedListings'])->name('fetch-suggested-listings');
Route::get('fetch-listing/{id}', [ListingController::class, 'fetchListing'])->name('fetch-listing');
Route::get('fetch-time-slots', [ListingController::class, 'fetchTimeSlots'])->name('fetch-time-slots');
Route::post('lock-slot', [ListingController::class, 'lockSlot'])->name('lock-slot');
Route::post('lock-slots', [ListingController::class, 'lockSlots'])->name('lock-slots');
Route::post('unlock-slot', [ListingController::class, 'unlockSlot'])->name('unlock-slot');
Route::post('unlock-all-slots', [ListingController::class, 'unlockAllSlots'])->name('unlock-all-slots');
Route::post('extend-slot-lock', [ListingController::class, 'extendSlotLock'])->name('extend-slot-lock');
Route::post('check-slot-availability', [ListingController::class, 'checkSlotAvailability'])->name('check-slot-availability');
Route::get('fetch-addons/{id}', [ListingController::class, 'fetchAddons'])->name('fetch-addons');
Route::get('fetch-recurring-booked-slots', [ListingController::class, 'fetchRecurringBookedSlots'])->name('fetch-recurring-booked-slots');
Route::get('listings', [ListingController::class, 'mobileListingsList'])->name('mobile.listings')->middleware('auth:sanctum');
Route::post('promocodes/apply', [PromocodeController::class, 'applyPromocode'])->name('promocodes.applyPromocode');

Route::post('newsletter/subscribe', [NewsletterController::class, 'subscribe'])->name('newsletter.subscribe');
