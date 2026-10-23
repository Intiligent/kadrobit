<?php

use App\Http\Controllers\AssetUploadController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::as('api.')->middleware('auth:api')->group(function () {
    Route::get('user', fn (Request $request) => new UserResource($request->user()))->name('user');

    // Uploads need a confirmed email; browsing the library does not.
    Route::middleware('verified')->group(function () {
        Route::controller(AssetUploadController::class)->prefix('assets/uploads')->name('assets.uploads.')->group(function () {
            Route::post('/', 'storeBatch')->name('batches.store');
            Route::get('{batch}', 'showBatch')->whereUuid('batch')->name('batches.show');
            Route::post('{batch}/files', 'store')->whereUuid('batch')->name('store');
        });
    });
});
