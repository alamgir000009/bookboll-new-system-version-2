<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;
use Rap2hpoutre\LaravelLogViewer\LogViewerController;

Route::get('newsletter/unsubscribe/{email}', [NewsletterController::class, 'unsubscribe'])->name('newsletter.unsubscribe');

Route::post('/stripe/payment-webhook', [StripeWebhookController::class, 'handle'])->name('stripe.payment.webhook');

Route::get('/payment/success/{session_id}', [PaymentController::class, 'paymentSuccess'])->name('payment.success');
Route::get('/payment/status/{session_id}', [PaymentController::class, 'paymentStatus'])->name('payment.status');
Route::get('/payment/cancel', [PaymentController::class, 'cancelPayment'])->name('payment.cancel');

Route::prefix('admin')->middleware(['auth', 'checkRoles:super_admin,admin'])->group(function () {
    Route::get('/dashboard-000009', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/profile-000009', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile-000009', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('activities-000009', [ActivityLogController::class, 'index'])->name('activities.index');
    Route::get('activities-000009/{activity}', [ActivityLogController::class, 'show'])->name('activities.show');

    Route::get('logs-000009', [LogViewerController::class, 'index'])->name('logs.viewer');

    Route::get('backups-000009', [BackupController::class, 'index'])->name('backups.index');
    Route::post('backups-000009/run', [BackupController::class, 'run'])->name('backups.run');
    Route::get('backups-000009/{filename}/download', [BackupController::class, 'download'])->name('backups.download')->where('filename', '[\w\-\.]+');
    Route::delete('backups-000009/bulk', [BackupController::class, 'destroyMany'])->name('backups.bulk-destroy');
    Route::delete('backups-000009/{filename}', [BackupController::class, 'destroy'])->name('backups.destroy')->where('filename', '[\w\-\.]+');

    Route::post('logout-000009', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::put('password-000009', [PasswordController::class, 'update'])->name('password.update');
});

Route::middleware('guest')->group(function () {
    Route::get('login-000009', [AuthenticatedSessionController::class, 'create'])->name('login');

    Route::post('login-000009', [AuthenticatedSessionController::class, 'store']);
});

/**
 * Test OneSignal push for a specific user (must have an active onesignal_subscriptions row).
 * GET /test-onesignal-000009?user_id=1&booking_id=1
 */
// Route::get('test-onesignal-000009', function (OneSignalService $oneSignal) {
//     $userId    = (int) request('user_id', 37128);
//     $bookingId = (int) request('booking_id', 7416);

//     $user = User::find($userId);
//     if (! $user) {
//         return response()->json([
//             'success' => false,
//             'message' => "User #{$userId} not found.",
//         ], 404);
//     }

//     $subscriptions = OnesignalSubscription::where('user_id', $userId)->active()->get();

//     $title   = 'Test notification';
//     $message = "OneSignal test for {$user->name} (user #{$userId})";
//     $data    = [
//         'type'       => 'booking_updated',
//         'booking_id' => $bookingId,
//     ];

//     if (method_exists($oneSignal, 'sendToUsersWithDetails')) {
//         $result = $oneSignal->sendToUsersWithDetails([$userId], $title, $message, $data);
//     } else {
//         $success = $oneSignal->sendToUsers([$userId], $title, $message, $data);
//         $result  = [
//             'success' => $success,
//             'message' => $success
//                 ? 'Notification sent (legacy sendToUsers). Deploy latest OneSignalService for detailed API response.'
//                 : 'sendToUsers returned false. Check logs and OneSignal config.',
//         ];
//     }

//     return response()->json([
//         'success'              => $result['success'],
//         'user_id'              => $userId,
//         'user_name'            => $user->name,
//         'active_subscriptions' => $subscriptions->pluck('onesignal_id'),
//         'targeting'            => $result['targeting'] ?? null,
//         'onesignal_status'     => $result['status'] ?? null,
//         'onesignal_response'   => $result['body'] ?? null,
//         'message'              => $result['message'],
//         'hint'                 => $result['success']
//             ? null
//             : 'Ensure the app called OneSignal.login(userId) after login, notification permission is granted, and ONESIGNAL_APP_ID matches the mobile app.',
//     ]);
// });

/**
 * Test Pusher realtime refresh for a specific user (private channel: private-refresh.{userId}).
 * GET /test-pusher-000009?user_id=1
 */
// Route::get('test-pusher-000009', function () {
//     $userId = (int) request('user_id', 37128);

//     $user = User::find($userId);
//     if (! $user) {
//         return response()->json([
//             'success' => false,
//             'message' => "User #{$userId} not found.",
//         ], 404);
//     }

//     $payload = [
//         'event'   => 'test_refresh',
//         'user_id' => $userId,
//         'message' => "Pusher test for {$user->name}",
//         'booking_id' => (int) request('booking_id', 7416),
//         'sent_at'    => now()->toIso8601String(),
//     ];

//     broadcast(new AdminAppRefresh($userId, $payload));

//     return response()->json([
//         'success' => true,
//         'user_id' => $userId,
//         'channel' => "private-refresh.{$userId}",
//         'event'   => 'refresh',
//         'payload' => $payload,
//         'message' => 'AdminAppRefresh broadcast dispatched.',
//     ]);
// });

// temporary route for checking mail configuration
// Route::get('test-mail-000009', function () {
//     $to = request('to', 'alamgir000009@gmail.com');
//     try {
//         \Illuminate\Support\Facades\Mail::to($to)->queue(new \App\Mail\GenericTestMail());
//         return "Mail sent to {$to}";
//     } catch (\Exception $e) {
//         return "Mail failed: " . $e->getMessage();
//     }
// });

// Route::get('storage-link-000009', function () {
//     Artisan::call("storage:link");
//     echo("Link created successfully");
// });

// Route::get('backup-000009', function (\Illuminate\Http\Request $request) {
//     try {
//         $email = $request->input('email');

//         Artisan::call('backup:run', ['--only-db' => true]);

//         $backupDisk = config('backup.backup.destination.disks.0', 'local');
//         $backupName = config('backup.backup.name', config('app.name'));

//         $disk       = \Illuminate\Support\Facades\Storage::disk($backupDisk);
//         $backupPath = $backupName;

//         $backupFiles = $disk->files($backupPath);

//         if (empty($backupFiles)) {
//             \Illuminate\Support\Facades\Log::error('No backup files found');

//             return response()->json([
//                 'success' => false,
//                 'message' => 'Backup created but file not found. Please check backup destination.',
//             ], 500);
//         }

//         usort($backupFiles, function ($a, $b) use ($disk) {
//             return $disk->lastModified($b) - $disk->lastModified($a);
//         });

//         $latestBackupFile = $backupFiles[0];
//         $backupFileName   = basename($latestBackupFile);
//         $fullBackupPath   = $disk->path($latestBackupFile);

//         if (! file_exists($fullBackupPath)) {
//             \Illuminate\Support\Facades\Log::error('Backup file path does not exist', [
//                 'path' => $fullBackupPath,
//             ]);

//             return response()->json([
//                 'success' => false,
//                 'message' => 'Backup file not found at expected path.',
//             ], 500);
//         }

//         if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
//             \Illuminate\Support\Facades\Mail::to($email)->send(
//                 new \App\Mail\DatabaseBackupEmail($fullBackupPath, $backupFileName)
//             );

//             return response()->json([
//                 'success'     => true,
//                 'message'     => 'Backup created and sent to ' . $email,
//                 'backup_file' => $backupFileName,
//                 'file_size'   => filesize($fullBackupPath),
//             ]);
//         }

//         return response()->json([
//             'success'     => true,
//             'message'     => 'Backup created successfully',
//             'backup_file' => $backupFileName,
//             'backup_path' => $fullBackupPath,
//             'file_size'   => filesize($fullBackupPath),
//         ]);

//     } catch (\Exception $e) {
//         \Illuminate\Support\Facades\Log::error('Backup creation error: ' . $e->getMessage(), [
//             'exception' => $e,
//             'trace'     => $e->getTraceAsString(),
//         ]);

//         return response()->json([
//             'success' => false,
//             'message' => 'Failed to create backup: ' . $e->getMessage(),
//         ], 500);
//     }
// });

// Route::get('import-users-000009', function () {
//     $filename = request()->get('filename');
//     if ($filename) {
//         $userService = app(\App\Services\UserService::class);
//         return $userService->importUsersFromExcel($filename);
//     } else {
//         return 'Filename is required';
//     }
// });

// Route::get('import-bookings-000009', function () {
//     $filename = request()->get('filename');
//     if ($filename) {
//         $bookingService = app(\App\Services\BookingService::class);
//         return $bookingService->importBookingsFromExcel($filename);
//     } else {
//         return 'Filename is required';
//     }
// });

