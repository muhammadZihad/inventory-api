<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Customers\CustomerController;
use App\Http\Controllers\Api\V1\Orders\OrderController;
use App\Http\Controllers\Api\V1\Reports\OrderReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->group(function (): void {
    Route::apiResource('customers', CustomerController::class);

    // Declared before the {order} routes so "reports" is not captured as an id.
    Route::get('orders/reports/summary', OrderReportController::class);

    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::get('orders/{order}/history', [OrderController::class, 'history']);

    // Write paths carry a tighter rate limit than reads, and order creation is
    // additionally guarded by the idempotency middleware.
    Route::middleware('throttle:orders')->group(function (): void {
        Route::post('orders', [OrderController::class, 'store'])->middleware('idempotent');
        Route::patch('orders/{order}/status', [OrderController::class, 'status']);
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);
    });
});
