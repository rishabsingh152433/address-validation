<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AddressController;
use App\Http\Controllers\ValidationController;
use App\Http\Controllers\GpsEventController;
use App\Http\Controllers\AdminController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


// ------------------------------
// Address Routes
// ------------------------------
Route::prefix('address')->group(function () {
    // Normalize address
    Route::post('normalize', [AddressController::class, 'normalize']);
    // Fetch address by ID
    Route::get('{id}', [AddressController::class, 'show']);
    // Validate address
    Route::post('validate', [ValidationController::class, 'validateAddress']);
});


// ------------------------------
// GPS Routes
// ------------------------------
Route::prefix('gps')->group(function () {
    Route::post('event', [GpsEventController::class, 'store']);
});

// ------------------------------
// Admin Address Routes
// ------------------------------
Route::prefix('admin/addresses')->group(function () {
    // List addresses that need review
    Route::get('needs-review', [AdminController::class, 'needsReview']);
    // Override a specific address
    Route::post('{id}/override', [AdminController::class, 'overrideCoordinate']);
});


