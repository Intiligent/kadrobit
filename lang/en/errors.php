<?php

return [
    'generic' => 'Something went wrong. Please try again.',
    'server' => 'The server failed to process the request. Please try again.',
    'network' => 'Unable to connect to the server. Check your internet connection.',
    'timeout' => 'The server took too long to respond. Please try again.',
    'unauthenticated' => 'Your session has expired or you are not logged in. Please log in to continue.',
    'forbidden' => 'You do not have permission to perform this action.',
    'not_found' => 'The requested resource was not found.',
    'session_expired' => 'Your session has expired. Please refresh the page.',
    'page_expired' => 'The page has expired. Please try again.',
    'too_many_requests' => 'Too many requests. Please try again in :seconds seconds.',
    'too_many_requests_later' => 'Too many requests. Please try again later.',
    'payload_too_large' => 'The request is too large for the server.',

    'idempotency' => [
        'invalid_key' => 'The Idempotency-Key header must be a UUID.',
        'key_reused' => 'This Idempotency-Key was already used for a different request.',
        'in_progress' => 'A request with this Idempotency-Key is still being processed.',
    ],

    'titles' => [
        'error' => 'Error',
        'server' => 'Server error',
        'network' => 'Network error',
        'timeout' => 'Request timeout',
        'unauthenticated' => 'Not logged in',
        'forbidden' => 'Access denied',
        'not_found' => 'Not found',
        'conflict' => 'Conflict',
        'session_expired' => 'Session expired',
        'too_many_requests' => 'Too many requests',
        'validation' => 'Validation error',
    ],
];
