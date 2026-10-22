<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\User as GoogleUser;

/**
 * Finds or creates the account for a Google login. Accounts are matched by email, so the rules below
 * decide when Google's word about an address is enough to hand over an account.
 */
class GoogleAccountResolver
{
    public function __construct(protected EmailVerificationService $verification) {}

    /**
     * Returns null when the address belongs to an existing account and Google does not vouch for it:
     * logging in would let anyone who adds that address to a Google account take the account over.
     */
    public function resolve(GoogleUser $googleUser): ?User
    {
        $verified = filter_var($googleUser->getRaw()['email_verified'] ?? false, FILTER_VALIDATE_BOOL);
        $email = User::normalizeEmail($googleUser->getEmail());

        $user = User::where('email', $email)->first();

        if (! $user) {
            return $this->create($googleUser, $email, $verified);
        }

        if (! $verified) {
            return null;
        }

        if (! $user->hasVerifiedEmail()) {
            $this->evictPreviousOwner($user);
            $this->verification->markVerified($user);
        }

        return $user;
    }

    /**
     * A new account: verified at once when Google vouches for the address, otherwise it confirms the
     * address with a code like any registration (the Registered event sends it).
     */
    protected function create(GoogleUser $googleUser, string $email, bool $verified): User
    {
        $raw = $googleUser->getRaw();

        $user = User::create([
            'first_name' => $this->limit($raw['given_name'] ?? $googleUser->getName() ?? Str::before($email, '@')),
            'last_name' => isset($raw['family_name']) ? $this->limit($raw['family_name']) : null,
            'username' => User::generateUsername(Str::before($email, '@')),
            'email' => $email,
            'avatar' => strlen((string) $googleUser->getAvatar()) <= 255 ? $googleUser->getAvatar() : null,
            // Nobody knows it: the account signs in with Google, or sets a password through "Forgot password?".
            'password' => Str::password(32),
        ]);

        if ($verified) {
            $this->verification->markVerified($user);
        }

        event(new Registered($user));

        return $user;
    }

    /**
     * An unverified account with this address may have been registered by someone else in advance,
     * waiting for the real owner to arrive. Google proves the owner, so whoever set the password loses
     * it: the password and remember token change, and PasswordReset ends their sessions and API tokens.
     */
    protected function evictPreviousOwner(User $user): void
    {
        $user->forceFill([
            'password' => Str::password(32),
            'remember_token' => Str::random(60),
        ])->save();

        event(new PasswordReset($user));
    }

    protected function limit(string $value): string
    {
        return Str::limit(trim($value), 255, '');
    }
}
