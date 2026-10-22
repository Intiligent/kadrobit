<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\ApiTokenCookie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class ApiTokenController extends Controller
{
    /**
     * Issues a fresh API token cookie for the logged-in session. The API client calls this after a 401
     * from /api/* (token revoked or expired) and retries the request.
     */
    public function store(Request $request, ApiTokenCookie $tokens): Response
    {
        Cookie::queue($tokens->issue($request->user(), $request));

        return response()->noContent();
    }
}
