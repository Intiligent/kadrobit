<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/register/index');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = User::create($request->safe()->only([
            'first_name', 'last_name', 'username', 'email', 'password',
        ]));

        event(new Registered($user));

        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        // The code was sent through the Registered event; the intended URL survives for after confirmation.
        return redirect()->route('verification.notice');
    }
}
