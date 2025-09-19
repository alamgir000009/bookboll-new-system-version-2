<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('logs-000009', [\Rap2hpoutre\LaravelLogViewer\LogViewerController::class, 'index']);
