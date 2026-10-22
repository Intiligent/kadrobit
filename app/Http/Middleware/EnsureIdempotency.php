<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Makes mutating requests safe to retry. A request carrying an Idempotency-Key header
 * runs once; repeating it with the same key replays the stored response.
 *
 * - Keys are scoped to the user (or session, or IP for guests), method and path.
 * - Only successful (2xx/3xx) responses are stored, so a failed request can be retried.
 * - Reusing a key with a different payload is rejected with 422, a concurrent duplicate with 409.
 */
class EnsureIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    /** How long a stored response is replayed, in seconds. */
    public const TTL = 86400;

    /** Upper bound for holding the lock while the request runs, in seconds. */
    protected const LOCK_SECONDS = 60;

    protected const METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getMethod(), self::METHODS, true) || ! $request->hasHeader(self::HEADER)) {
            return $next($request);
        }

        $key = (string) $request->header(self::HEADER);

        if (! Str::isUuid($key)) {
            throw new BadRequestHttpException(__('errors.idempotency.invalid_key'));
        }

        $cacheKey = 'idempotency:'.hash('sha256', implode('|', [
            $this->scope($request), $request->getMethod(), $request->path(), $key,
        ]));
        $fingerprint = $this->fingerprint($request);

        if ($replay = $this->replay($cacheKey, $fingerprint)) {
            return $replay;
        }

        $lock = Cache::lock($cacheKey.':lock', self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new ConflictHttpException(__('errors.idempotency.in_progress'));
        }

        try {
            // The first request may have finished between the check above and taking the lock.
            if ($replay = $this->replay($cacheKey, $fingerprint)) {
                return $replay;
            }

            $response = $next($request);

            if ($this->shouldStore($response)) {
                Cache::put($cacheKey, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'headers' => $response->headers->allPreserveCaseWithoutCookies(),
                    'content' => $response->getContent(),
                ], self::TTL);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    protected function replay(string $cacheKey, string $fingerprint): ?Response
    {
        $stored = Cache::get($cacheKey);

        if ($stored === null) {
            return null;
        }

        if (! hash_equals($stored['fingerprint'], $fingerprint)) {
            throw new UnprocessableEntityHttpException(__('errors.idempotency.key_reused'));
        }

        $response = new Response($stored['content'], $stored['status'], $stored['headers']);
        $response->headers->set(self::REPLAYED_HEADER, 'true');

        return $response;
    }

    protected function shouldStore(Response $response): bool
    {
        return $response->getStatusCode() < 400
            && ! $response instanceof StreamedResponse
            && ! $response instanceof BinaryFileResponse;
    }

    protected function scope(Request $request): string
    {
        if ($user = $request->user()) {
            return 'user:'.$user->getAuthIdentifier();
        }

        if ($request->hasSession() && $request->session()->isStarted()) {
            return 'session:'.$request->session()->getId();
        }

        return 'ip:'.$request->ip();
    }

    /**
     * Hash of the payload. Files are represented by name, size and content hash,
     * so a retried upload of the same file matches.
     */
    protected function fingerprint(Request $request): string
    {
        $files = collect($request->allFiles())
            ->flatten()
            ->map(fn (UploadedFile $file) => [$file->getClientOriginalName(), $file->getSize(), hash_file('sha256', $file->getRealPath())])
            ->all();

        return hash('sha256', json_encode([
            $request->query(),
            $request->except(array_keys($request->allFiles())),
            $files,
        ]));
    }
}
