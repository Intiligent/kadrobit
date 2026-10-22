<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateUnverifiedEmailRequest;
use App\Http\Requests\Auth\VerifyEmailCodeRequest;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The soft email gate: the site stays open to an unverified user, only routes behind the `verified`
 * middleware send them here to enter the 6-digit code from the email (or follow its link).
 */
class VerifyEmailController extends Controller
{
    public function __construct(protected EmailVerificationService $verification) {}

    public function notice(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        return Inertia::render('auth/verify-email/index', [
            'email' => $user->email,
            'cooldown' => $this->verification->cooldownSecondsLeft($user),
            'codeLength' => EmailVerificationService::CODE_LENGTH,
        ]);
    }

    public function confirm(VerifyEmailCodeRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        $result = $this->verification->verify($user, $request->validated('code'));

        if ($result !== EmailVerificationService::RESULT_OK) {
            throw ValidationException::withMessages(['code' => __('verification.errors.'.$result)]);
        }

        Inertia::flash('success', __('verification.flash.verified'));

        return redirect()->intended(route('home'));
    }

    /**
     * The button in the email. The signature and the email hash prove ownership of the address,
     * so the link works without a login too, e.g. when opened on a phone.
     */
    public function verifyLink(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403);
        }

        $this->verification->markVerified($user);

        Inertia::flash('success', __('verification.flash.verified'));

        if ($request->user()?->is($user)) {
            return redirect()->intended(route('home'));
        }

        return redirect()->route($request->user() ? 'home' : 'login');
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        $this->ensureCanSend($user, 'code');

        $this->verification->issue($user);

        return Inertia::flash('success', __('verification.flash.resent'))->back();
    }

    /**
     * Fixes a mistyped address without leaving the screen, only while the account is unverified.
     */
    public function updateEmail(UpdateUnverifiedEmailRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        $this->ensureCanSend($user, 'email', ignoreCooldown: $request->validated('email') !== $user->email);

        $user->forceFill(['email' => $request->validated('email')])->save();

        $this->verification->issue($user);

        return Inertia::flash('success', __('verification.flash.email_updated'))->back();
    }

    /**
     * @throws ValidationException
     */
    protected function ensureCanSend(User $user, string $field, bool $ignoreCooldown = false): void
    {
        if ($this->verification->dailyLimitReached($user)) {
            throw ValidationException::withMessages([$field => __('verification.errors.daily_limit')]);
        }

        $cooldown = $this->verification->cooldownSecondsLeft($user);

        if (! $ignoreCooldown && $cooldown > 0) {
            throw ValidationException::withMessages([$field => __('verification.errors.cooldown', ['seconds' => $cooldown])]);
        }
    }
}
