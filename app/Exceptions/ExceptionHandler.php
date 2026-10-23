<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every error of a JSON request as {message, status, code, errors?, retry_after?}
 * so the frontend API client can handle it by code.
 *
 * Laravel converts several exceptions before render callbacks run: AuthorizationException
 * becomes a 403 AccessDeniedHttpException, TokenMismatchException a 419 HttpException,
 * ModelNotFoundException a 404. They are handled here by HTTP status.
 */
class ExceptionHandler
{
    public function __invoke(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $this->wantsJson($request),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $this->wantsJson($request) || $e instanceof HttpResponseException) {
                return null;
            }

            return $this->renderJson($e);
        });

        // An Inertia visit with an expired CSRF token would otherwise show the raw 419 page in a modal.
        // Inertia's global EnsureGetOnRedirect turns this 302 into a 303 for PUT/PATCH/DELETE visits.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($response->getStatusCode() === 419 && $request->hasHeader('X-Inertia')) {
                return Inertia::flash('error', __('errors.page_expired'))->back();
            }

            // A throttled Inertia form submit would show a bare 429 page in a modal. GET visits are left
            // alone: going back could land on the same throttled page and loop.
            if ($response->getStatusCode() === Response::HTTP_TOO_MANY_REQUESTS
                && $request->hasHeader('X-Inertia')
                && ! $request->isMethodSafe()
            ) {
                $retryAfter = $response->headers->get('Retry-After');

                return Inertia::flash('error', $retryAfter
                    ? __('errors.too_many_requests', ['seconds' => (int) $retryAfter])
                    : __('errors.too_many_requests_later'))->back();
            }

            return $response;
        });
    }

    protected function wantsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    protected function renderJson(Throwable $e): JsonResponse
    {
        $status = $this->status($e);
        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        $payload = [
            'message' => $this->message($e, $status, $headers),
            'status' => $status,
            'code' => $this->code($e, $status)->value,
        ];

        if ($e instanceof ValidationException) {
            $payload['errors'] = $e->errors();
        }

        if ($status === Response::HTTP_TOO_MANY_REQUESTS && isset($headers['Retry-After'])) {
            $payload['retry_after'] = (int) $headers['Retry-After'];
        }

        if (config('app.debug')) {
            $payload['exception'] = get_class($e);
            $payload['file'] = $e->getFile();
            $payload['line'] = $e->getLine();
            $payload['trace'] = collect($e->getTrace())->map(fn (array $frame) => Arr::except($frame, ['args']))->all();
        }

        return new JsonResponse($payload, $status, $headers);
    }

    protected function status(Throwable $e): int
    {
        return match (true) {
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            $e instanceof ValidationException => $e->status,
            $e instanceof AuthenticationException => Response::HTTP_UNAUTHORIZED,
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    protected function code(Throwable $e, int $status): ErrorCode
    {
        if ($e instanceof ValidationException) {
            return ErrorCode::Validation;
        }

        if ($e instanceof ExceptionWithMessage) {
            return ErrorCode::WithMessage;
        }

        return match (true) {
            $status === Response::HTTP_UNAUTHORIZED => ErrorCode::Unauthenticated,
            $status === Response::HTTP_FORBIDDEN => ErrorCode::AccessDenied,
            $status === Response::HTTP_NOT_FOUND => ErrorCode::NotFound,
            $status === Response::HTTP_CONFLICT => ErrorCode::Conflict,
            $status === 419 => ErrorCode::Csrf,
            $status === Response::HTTP_UNPROCESSABLE_ENTITY => ErrorCode::Validation,
            $status === Response::HTTP_TOO_MANY_REQUESTS => ErrorCode::TooManyRequests,
            $status >= 500 => ErrorCode::Internal,
            default => ErrorCode::HttpError,
        };
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    protected function message(Throwable $e, int $status, array $headers): string
    {
        if ($e instanceof ValidationException || $e instanceof ExceptionWithMessage) {
            return $e->getMessage();
        }

        // Exception messages of server errors may contain SQL, paths or other internals.
        if ($status >= 500 && ! config('app.debug')) {
            return __('errors.server');
        }

        return match ($status) {
            Response::HTTP_UNAUTHORIZED => __('errors.unauthenticated'),
            419 => __('errors.session_expired'),
            Response::HTTP_TOO_MANY_REQUESTS => isset($headers['Retry-After'])
                ? __('errors.too_many_requests', ['seconds' => (int) $headers['Retry-After']])
                : __('errors.too_many_requests_later'),
            Response::HTTP_NOT_FOUND => __('errors.not_found'),
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE => __('errors.payload_too_large'),
            default => $e->getMessage() ?: (Response::$statusTexts[$status] ?? __('errors.generic')),
        };
    }
}
