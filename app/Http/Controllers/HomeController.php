<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * Logged-in users land on their library, so every redirect to "home"
     * (after login, OAuth, email verification) ends there.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('library');
        }

        return Inertia::render('home/index');
    }
}
