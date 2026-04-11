<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifiedKyc
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user->isActive()) {
            return response()->json([
                'message' => 'Your account has been suspended.',
                'code'    => 'ACCOUNT_SUSPENDED',
            ], 403);
        }

        // Allow access to auth routes even without KYC
        // so users can check their status after registering
        if (
            $request->is('api/v1/auth/*') ||
            $request->is('api/v1/kyc/*')
        ) {
            return $next($request);
        }

        if (! $user->isKycVerified()) {
            return response()->json([
                'message' => 'KYC verification required before you can transact.',
                'code'    => 'KYC_REQUIRED',
            ], 403);
        }

        return $next($request);
    }
}
