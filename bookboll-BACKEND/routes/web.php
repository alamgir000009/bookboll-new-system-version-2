<?php

use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Rap2hpoutre\LaravelLogViewer\LogViewerController;

// temporary route for checking mail configuration
Route::get('test-mail-000009', function () {
    $to = request('to', 'alamgir000009@gmail.com');
    try {
        \Illuminate\Support\Facades\Mail::to($to)->send(new \App\Mail\GenericTestMail());
        return "Mail sent to {$to}";
    } catch (\Exception $e) {
        return "Mail failed: " . $e->getMessage();
    }
});

Route::get('newsletter/unsubscribe/{email}', [NewsletterController::class, 'unsubscribe'])->name('newsletter.unsubscribe');

Route::get('/', function () {
    return "NANANANANANANANANAN";
});

Route::get('storage-link-000009', function () {
    Artisan::call("storage:link");
    echo("Link created successfully");
});

Route::get('backup-000009', function (\Illuminate\Http\Request $request) {
    try {
        $email = $request->input('email');

        Artisan::call('backup:run', ['--only-db' => true]);

        $backupDisk = config('backup.backup.destination.disks.0', 'local');
        $backupName = config('backup.backup.name', config('app.name'));

        $disk       = \Illuminate\Support\Facades\Storage::disk($backupDisk);
        $backupPath = $backupName;

        $backupFiles = $disk->files($backupPath);

        if (empty($backupFiles)) {
            \Illuminate\Support\Facades\Log::error('No backup files found');

            return response()->json([
                'success' => false,
                'message' => 'Backup created but file not found. Please check backup destination.',
            ], 500);
        }

        usort($backupFiles, function ($a, $b) use ($disk) {
            return $disk->lastModified($b) - $disk->lastModified($a);
        });

        $latestBackupFile = $backupFiles[0];
        $backupFileName   = basename($latestBackupFile);
        $fullBackupPath   = $disk->path($latestBackupFile);

        if (! file_exists($fullBackupPath)) {
            \Illuminate\Support\Facades\Log::error('Backup file path does not exist', [
                'path' => $fullBackupPath,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Backup file not found at expected path.',
            ], 500);
        }

        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            \Illuminate\Support\Facades\Mail::to($email)->send(
                new \App\Mail\DatabaseBackupEmail($fullBackupPath, $backupFileName)
            );

            return response()->json([
                'success'     => true,
                'message'     => 'Backup created and sent to ' . $email,
                'backup_file' => $backupFileName,
                'file_size'   => filesize($fullBackupPath),
            ]);
        }

        return response()->json([
            'success'     => true,
            'message'     => 'Backup created successfully',
            'backup_file' => $backupFileName,
            'backup_path' => $fullBackupPath,
            'file_size'   => filesize($fullBackupPath),
        ]);

    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('Backup creation error: ' . $e->getMessage(), [
            'exception' => $e,
            'trace'     => $e->getTraceAsString(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to create backup: ' . $e->getMessage(),
        ], 500);
    }
});

Route::get('logs-000009', [LogViewerController::class, 'index']);

Route::get('/payment/success/{session_id}', [PaymentController::class, 'paymentSuccess'])->name('payment.success');
Route::get('/payment/cancel', [PaymentController::class, 'cancelPayment'])->name('payment.cancel');

Route::get('import-users-000009', function () {
    $filename = request()->get('filename');
    if ($filename) {
        $userService = app(\App\Services\UserService::class);
        return $userService->importUsersFromExcel($filename);
    } else {
        return 'Filename is required';
    }
});

Route::get('import-bookings-000009', function () {
    $filename = request()->get('filename');
    if ($filename) {
        $bookingService = app(\App\Services\BookingService::class);
        return $bookingService->importBookingsFromExcel($filename);
    } else {
        return 'Filename is required';
    }
});
