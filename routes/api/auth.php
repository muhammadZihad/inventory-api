<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use Illuminate\Support\Facades\Route;

// Credential endpoints get their own, much tighter limiter.
Route::middleware('throttle:auth')->group(function (): void {
    Route::post('auth/register', RegisterController::class);
    Route::post('auth/login', LoginController::class);
});

Route::post('auth/logout', LogoutController::class)->middleware('auth:api');
