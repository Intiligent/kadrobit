<?php

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// Touching any web route renews the XSRF-TOKEN cookie; the API client calls this after a 419.
Route::get('csrf-token', fn () => response()->noContent())->name('csrf.refresh');
