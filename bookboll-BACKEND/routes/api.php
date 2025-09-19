<?php

use App\Http\Controllers\PostCategoryController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SportsCategoryController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VenueController;
use Illuminate\Support\Facades\Route;

Route::post('login', [UserController::class, 'login']);
Route::post('logout', [UserController::class, 'logout']);

Route::middleware(['auth:sanctum'])->group(function () {

    Route::get('/me', [UserController::class, 'me']);

    Route::prefix('admin')->group(function () {

        Route::apiResource('post-category', PostCategoryController::class);

        Route::apiResource('post', PostController::class);
        Route::post('post/change-status', [PostController::class, 'changeStatus']);

        Route::apiResource('venue', VenueController::class);

        Route::apiResource('users', UserController::class);
        Route::get('users/required-data', [UserController::class, 'requiredDataForUser']);
        Route::post('users/update-profile', [UserController::class, 'updateProfile']);

        Route::get('settings', [SettingController::class, 'index']);
        Route::post('settings/{key}', [SettingController::class, 'updateSingle']);
        Route::post('settings', [SettingController::class, 'update']);

        Route::apiResource('sports-categories', SportsCategoryController::class);
        Route::get('sports-categories/popular-emojis', [SportsCategoryController::class, 'popularEmojis']);

    });

});
