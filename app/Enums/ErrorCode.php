<?php

namespace App\Enums;

/**
 * Error codes returned in the `code` field of JSON error responses.
 * Mirrored in resources/js/config/constant.js (ERROR_CODE); keep both in sync.
 */
enum ErrorCode: int
{
    /** Any other 4xx without a dedicated code. */
    case HttpError = 140001;

    /** CSRF token mismatch (419): the frontend refreshes the token and retries once. */
    case Csrf = 140101;

    /** Not authenticated (401). */
    case Unauthenticated = 140302;

    /** Authenticated but not allowed (403). */
    case AccessDenied = 140303;

    /** Business error whose message is meant for the user. */
    case WithMessage = 140305;

    case NotFound = 140401;

    /** Conflicting state, e.g. an idempotent request still in progress (409). */
    case Conflict = 140901;

    case Validation = 142201;

    case TooManyRequests = 142901;

    /** Server error (5xx). */
    case Internal = 150001;
}
