<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class ForgotPasswordController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/forgot-password/index');
    }

    /**
     * The answer is the same whether or not the email belongs to an account, and whether or not
     * a link was sent a minute ago: otherwise the form tells anyone which emails are registered.
     */
    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        Password::sendResetLink($request->only('email'));

        return Inertia::flash('success', __('passwords.sent'))->back();
    }
}
