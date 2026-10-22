<?php

namespace App\Services\Auth;

use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

/**
 * Issues and checks the 6-digit codes confirming ownership of an email address.
 *
 * A user holds at most one live code: issuing a new one deletes the previous one, so an old code
 * stops working the moment a resend is requested. Only an HMAC of the code with the app key is
 * stored: without the key a leaked table cannot be brute-forced over the million possible codes.
 */
class EmailVerificationService
{
    public const CODE_LENGTH = 6;

    public const CODE_TTL_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN_SECONDS = 60;

    public const DAILY_EMAIL_LIMIT = 10;

    public const RESULT_OK = 'ok';

    public const RESULT_INVALID = 'invalid';

    public const RESULT_EXPIRED = 'expired';

    public const RESULT_TOO_MANY_ATTEMPTS = 'too_many_attempts';

    /** No live code: it expired, burned out, or was issued for a previous address. */
    public const RESULT_MISSING = 'missing';

    /**
     * Invalidates the previous code, creates a new one and queues the email.
     */
    public function issue(User $user): void
    {
        EmailVerificationCode::where('user_id', $user->getKey())->delete();

        $code = (string) random_int(10 ** (self::CODE_LENGTH - 1), 10 ** self::CODE_LENGTH - 1);
        $email = $user->getEmailForVerification();

        $record = EmailVerificationCode::create([
            'user_id' => $user->getKey(),
            'email' => $email,
            'code_hash' => $this->hash($code),
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ]);

        RateLimiter::hit($this->dailyKey($user), 86400);

        $user->notify(new VerifyEmailNotification($code, $this->signedUrl($user, $record), $record->getKey()));
    }

    /**
     * Runs a send (resend or address change) under a per-user lock, so parallel requests cannot all
     * pass the cooldown check and mail several codes of which only the last one works.
     * Returns false when another send is in progress.
     */
    public function withSendLock(User $user, callable $callback): bool
    {
        return Cache::lock('email-verification:send:'.$user->getKey(), 10)->get($callback) !== false;
    }

    /**
     * Checks a typed code. Returns a RESULT_* constant; on success the account is verified.
     */
    public function verify(User $user, string $code): string
    {
        $record = EmailVerificationCode::where('user_id', $user->getKey())->latest('id')->first();

        if (! $record || $record->email !== $user->getEmailForVerification()) {
            return self::RESULT_MISSING;
        }

        if ($record->isExpired()) {
            $record->delete();

            return self::RESULT_EXPIRED;
        }

        // The attempt is counted before the code is checked, atomically: parallel guesses
        // cannot slip past the limit while one of them is being compared.
        $counted = EmailVerificationCode::whereKey($record->getKey())
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->increment('attempts');

        if (! $counted) {
            $record->delete();

            return self::RESULT_TOO_MANY_ATTEMPTS;
        }

        if (! hash_equals($record->code_hash, $this->hash($code))) {
            if ($record->attempts + 1 >= self::MAX_ATTEMPTS) {
                $record->delete();

                return self::RESULT_TOO_MANY_ATTEMPTS;
            }

            return self::RESULT_INVALID;
        }

        $this->markVerified($user);

        return self::RESULT_OK;
    }

    /**
     * Checks the code record a signed link points to: the link lives exactly as long as its code,
     * so a resend, an address change or a burned-out code also kills the link from that email.
     */
    public function linkIsLive(User $user, int|string|null $codeId): bool
    {
        $record = EmailVerificationCode::whereKey((int) $codeId)->where('user_id', $user->getKey())->first();

        return $record
            && $record->email === $user->getEmailForVerification()
            && ! $record->isExpired()
            && $record->attempts < self::MAX_ATTEMPTS;
    }

    /**
     * Marks the account verified, once: the update only matches an unverified row with the same
     * address, so a code and a link used at the same moment fire Verified a single time.
     * Also used by the signed link; the Google login will need it too (KB-7).
     */
    public function markVerified(User $user): bool
    {
        EmailVerificationCode::where('user_id', $user->getKey())->delete();

        if ($user->hasVerifiedEmail()) {
            return false;
        }

        $verifiedAt = now();

        $updated = User::whereKey($user->getKey())
            ->where('email', $user->getEmailForVerification())
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => $verifiedAt]);

        if (! $updated) {
            return false;
        }

        $user->forceFill(['email_verified_at' => $verifiedAt])->syncOriginalAttribute('email_verified_at');
        event(new Verified($user));

        return true;
    }

    /**
     * Seconds left before another email may be sent; 0 when a resend is allowed.
     */
    public function cooldownSecondsLeft(User $user): int
    {
        $record = EmailVerificationCode::where('user_id', $user->getKey())->latest('id')->first();

        if (! $record) {
            return 0;
        }

        return (int) max(0, self::RESEND_COOLDOWN_SECONDS - $record->created_at->diffInSeconds(now()));
    }

    /**
     * A ceiling on emails per account per day on top of the cooldown.
     */
    public function dailyLimitReached(User $user): bool
    {
        return RateLimiter::tooManyAttempts($this->dailyKey($user), self::DAILY_EMAIL_LIMIT);
    }

    /**
     * The link in the email. The hash pins it to the address it was sent to, the code id to the code
     * sent with it (see linkIsLive()).
     */
    public function signedUrl(User $user, EmailVerificationCode $record): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            $record->expires_at,
            ['id' => $user->getKey(), 'hash' => sha1($record->email), 'code' => $record->getKey()],
        );
    }

    protected function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    protected function dailyKey(User $user): string
    {
        return 'email-verification:daily:'.$user->getKey();
    }
}
