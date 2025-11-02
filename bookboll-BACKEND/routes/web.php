<?php

use App\Http\Controllers\NewsletterController;
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

Route::get('logs-000009', [LogViewerController::class, 'index']);
