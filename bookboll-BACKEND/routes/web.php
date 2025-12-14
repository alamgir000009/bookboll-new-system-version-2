<?php

use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Rap2hpoutre\LaravelLogViewer\LogViewerController;

Route::get('newsletter/unsubscribe/{email}', [NewsletterController::class, 'unsubscribe'])->name('newsletter.unsubscribe');

Route::get('/', function () {
    $subscribers = App\Models\Subscriber::where('status', 'subscribed')->get();
    dispatch(new App\Jobs\SendNewsletter('Test Subject', '<p>Hello!</p>', $subscribers));
    return view('welcome');
});

Route::get('storage-link-000009', function () {
    Artisan::call("storage:link");
    echo("Link created successfully");
});

Route::get('backup-000009', function (\Illuminate\Http\Request $request) {
    try {
        $email = $request->input('email');

        // Use Spatie Backup package - run backup via artisan command
        Artisan::call('backup:run', ['--only-db' => true]);

        // Get backup destination from config (default is local disk)
        $backupDisk = config('backup.backup.destination.disks.0', 'local');
        $backupName = config('backup.backup.name', config('app.name'));

        $disk = \Illuminate\Support\Facades\Storage::disk($backupDisk);
        $backupPath = $backupName;

        // Get all backup files and find the latest one
        $backupFiles = $disk->files($backupPath);

        if (empty($backupFiles)) {
            \Illuminate\Support\Facades\Log::error('No backup files found');

            return response()->json([
                'success' => false,
                'message' => 'Backup created but file not found. Please check backup destination.'
            ], 500);
        }

        // Sort by modification time and get the latest
        usort($backupFiles, function($a, $b) use ($disk) {
            return $disk->lastModified($b) - $disk->lastModified($a);
        });

        $latestBackupFile = $backupFiles[0];
        $backupFileName = basename($latestBackupFile);
        $fullBackupPath = $disk->path($latestBackupFile);

        if (!file_exists($fullBackupPath)) {
            \Illuminate\Support\Facades\Log::error('Backup file path does not exist', [
                'path' => $fullBackupPath
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Backup file not found at expected path.'
            ], 500);
        }

        // If email is provided, send backup via email
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            \Illuminate\Support\Facades\Mail::to($email)->send(
                new \App\Mail\DatabaseBackupEmail($fullBackupPath, $backupFileName)
            );

            return response()->json([
                'success' => true,
                'message' => 'Backup created and sent to ' . $email,
                'backup_file' => $backupFileName,
                'file_size' => filesize($fullBackupPath)
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Backup created successfully',
            'backup_file' => $backupFileName,
            'backup_path' => $fullBackupPath,
            'file_size' => filesize($fullBackupPath)
        ]);

    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('Backup creation error: ' . $e->getMessage(), [
            'exception' => $e,
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to create backup: ' . $e->getMessage()
        ], 500);
    }
});

Route::get('logs-000009', [LogViewerController::class, 'index']);

Route::get('/payment/success/{session_id}', [PaymentController::class, 'paymentSuccess'])->name('payment.success');
Route::get('/payment/cancel', [PaymentController::class, 'cancelPayment'])->name('payment.cancel');

Route::get('test', function() {
    $booking = \App\Models\Booking::find(220);
    $invoiceNumber = "TEsttttttt";
    // return view('pdfs.booking-invoice', compact('booking', 'invoiceNumber'));

    return view('pdfs.booking-invoice', compact('booking', 'invoiceNumber'));
});
