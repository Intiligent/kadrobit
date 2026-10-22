<?php

use App\Http\Controllers\Auth\ApiTokenController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Dev\EmailPreviewController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// Touching any web route renews the XSRF-TOKEN cookie; the API client calls this after a 419.
Route::get('csrf-token', fn () => response()->noContent())->name('csrf.refresh');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store']);

    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->middleware('throttle:auth-forms');

    Route::get('forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [ForgotPasswordController::class, 'store'])
        ->middleware('throttle:auth-forms')
        ->name('password.email');
});

// Open to logged-in users too: the link from the email must work in a browser that is still logged in.
Route::get('reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
Route::post('reset-password', [ResetPasswordController::class, 'store'])
    ->middleware('throttle:auth-forms')
    ->name('password.update');

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::post('auth/token', [ApiTokenController::class, 'store'])
        ->middleware('throttle:api-token')
        ->name('auth.token');
});

// Development only: preview transactional emails in the browser (404 outside the local environment).
Route::middleware('local.only')->prefix('dev/emails')->name('dev.emails.')->group(function () {
    Route::get('/', [EmailPreviewController::class, 'index'])->name('index');
    Route::get('reset-password', [EmailPreviewController::class, 'resetPassword'])->name('reset-password');
});
