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
use Throwable;

/**
 * Makes mutating requests safe to retry. A request carrying an Idempotency-Key header
 * runs once; repeating it with the same key replays the stored response.
 *
 * One cache entry per key serves as both the "in progress" marker and the stored response:
 * an atomic add() claims the key, then put() stores the response or forget() releases it.
 *
 * - Keys are scoped to the user (or session, or IP for guests), method and path.
 * - Only successful (2xx/3xx) responses up to `idempotency.max_body_size` are stored,
 *   so a failed request can be retried.
 * - Reusing a key with a different payload is rejected with 422, a concurrent duplicate with 409.
 *
 * Settings: config/idempotency.php.
 */
class EnsureIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    protected const METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    protected const PROCESSING = 'processing';

    protected const COMPLETED = 'completed';

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getMethod(), self::METHODS, true) || ! $request->hasHeader(self::HEADER)) {
            return $next($request);
        }

        $key = (string) $request->header(self::HEADER);

        if (! Str::isUuid($key)) {
            throw new BadRequestHttpException(__('errors.idempotency.invalid_key'));
        }

        $cache = Cache::store(config('idempotency.store'));
        $cacheKey = static::cacheKey($this->scope($request), $request->getMethod(), $request->path(), $key);
        $fingerprint = $this->fingerprint($request);

        $claimed = $cache->add(
            $cacheKey,
            ['state' => self::PROCESSING, 'fingerprint' => $fingerprint],
            config('idempotency.processing_timeout'),
        );

        if (! $claimed) {
            return $this->replay($cache->get($cacheKey), $fingerprint);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $cache->forget($cacheKey);

            throw $e;
        }

        if ($this->shouldStore($response)) {
            $cache->put($cacheKey, [
                'state' => self::COMPLETED,
                'fingerprint' => $fingerprint,
                'status' => $response->getStatusCode(),
                'headers' => $response->headers->allPreserveCaseWithoutCookies(),
                'content' => $response->getContent(),
            ], config('idempotency.ttl'));
        } else {
            $cache->forget($cacheKey);
        }

        return $response;
    }

    public static function cacheKey(string $scope, string $method, string $path, string $key): string
    {
        return 'idempotency:'.hash('sha256', implode('|', [$scope, $method, $path, $key]));
    }

    /**
     * @param  array<string, mixed>|null  $stored
     */
    protected function replay(?array $stored, string $fingerprint): Response
    {
        if ($stored !== null && ! hash_equals($stored['fingerprint'], $fingerprint)) {
            throw new UnprocessableEntityHttpException(__('errors.idempotency.key_reused'));
        }

        // Null: the entry expired between add() and get(); treat the key as still in use.
        if ($stored === null || $stored['state'] === self::PROCESSING) {
            throw new ConflictHttpException(__('errors.idempotency.in_progress'));
        }

        $response = new Response($stored['content'], $stored['status'], $stored['headers']);
        $response->headers->set(self::REPLAYED_HEADER, 'true');

        return $response;
    }

    protected function shouldStore(Response $response): bool
    {
        return $response->getStatusCode() < 400
            && ! $response instanceof StreamedResponse
            && ! $response instanceof BinaryFileResponse
            && strlen((string) $response->getContent()) <= config('idempotency.max_body_size');
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
     * so a retried upload of the same file matches. Failed uploads have no file to hash:
     * they are represented by their error code and left to validation.
     */
    protected function fingerprint(Request $request): string
    {
        $files = collect($request->allFiles())
            ->flatten()
            ->map(fn (UploadedFile $file) => $file->isValid()
                ? [$file->getClientOriginalName(), $file->getSize(), hash_file('sha256', $file->getRealPath())]
                : [$file->getClientOriginalName(), 'error' => $file->getError()])
            ->all();

        return hash('sha256', json_encode([
            $request->query(),
            $request->except(array_keys($request->allFiles())),
            $files,
        ]));
    }
}
