<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Catalog\CategoryController;
use App\Http\Controllers\Api\V1\Catalog\ProductController;
use App\Http\Controllers\Api\V1\Inventory\InventoryController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->group(function (): void {
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('products', ProductController::class);

    Route::get('inventory', [InventoryController::class, 'index']);
    Route::get('inventory/{product}/movements', [InventoryController::class, 'movements']);
    Route::post('inventory/{product}/adjust', [InventoryController::class, 'adjust'])
        ->middleware('throttle:orders');
});
