<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/storage-link', function () {
    Artisan::call("storage:link");
    dd("Link created successfully");
});

Route::get('logs-000009', [\Rap2hpoutre\LaravelLogViewer\LogViewerController::class, 'index']);
