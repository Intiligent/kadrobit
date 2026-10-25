<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssetGenerationController;
use App\Http\Controllers\AssetUploadController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\Settings\MarketplaceAccountController;
use App\Http\Controllers\Settings\UserSettingController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::as('api.')->middleware('auth:api')->group(function () {
    Route::get('user', fn (Request $request) => new UserResource($request->user()))->name('user');

    // The user's own settings: no confirmed email needed, they change only the user's own experience.
    Route::controller(UserSettingController::class)->prefix('settings')->name('settings.')->group(function () {
        Route::get('/', 'show')->name('show');
        Route::patch('/', 'update')->name('update');
    });

    // Uploads, edits and folders need a confirmed email; browsing the library does not.
    Route::middleware('verified')->group(function () {
        Route::controller(AssetUploadController::class)->prefix('assets/uploads')->name('assets.uploads.')->group(function () {
            Route::post('/', 'storeBatch')->name('batches.store');
            Route::get('{batch}', 'showBatch')->whereUuid('batch')->name('batches.show');
            Route::post('{batch}/files', 'store')->whereUuid('batch')->name('store');
        });

        Route::post('assets/move', [AssetController::class, 'move'])->name('assets.move');
        Route::patch('assets/{asset}', [AssetController::class, 'update'])->whereUlid('asset')->name('assets.update');
        Route::delete('assets/{asset}', [AssetController::class, 'destroy'])->whereUlid('asset')->name('assets.destroy');

        // Metadata generation: a paid call to the model, counted against the monthly limit.
        Route::controller(AssetGenerationController::class)->name('assets.generations.')->group(function () {
            Route::post('assets/generations', 'batch')->name('batch');
            Route::get('assets/{asset}/generations', 'index')->whereUlid('asset')->name('index');
            Route::post('assets/{asset}/generations', 'store')->whereUlid('asset')->middleware('throttle:metadata-generation')->name('store');
            Route::post('assets/{asset}/generations/{generation}/apply', 'apply')->whereUlid('asset')->whereNumber('generation')->name('apply');
        });

        Route::controller(FolderController::class)->prefix('folders')->name('folders.')->group(function () {
            Route::post('/', 'store')->name('store');
            Route::patch('{folder}', 'update')->whereUlid('folder')->name('update');
            Route::delete('{folder}', 'destroy')->whereUlid('folder')->name('destroy');
        });

        // Settings → Marketplaces. Sign-ins to marketplace servers are throttled in the controller.
        Route::controller(MarketplaceAccountController::class)->prefix('marketplace-accounts')->name('marketplace-accounts.')->group(function () {
            Route::post('/', 'store')->name('store');
            Route::patch('{account}', 'update')->whereNumber('account')->name('update');
            Route::post('{account}/check', 'check')->whereNumber('account')->name('check');
            Route::delete('{account}', 'destroy')->whereNumber('account')->name('destroy');
        });
    });
});
