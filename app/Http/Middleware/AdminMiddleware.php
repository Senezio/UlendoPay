<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user || !$user->is_staff) {
            return response()->json([
                'message' => 'Unauthorized.',
                'code'    => 'NOT_STAFF',
            ], 403);
        }

        if (!$user->isActive()) {
            return response()->json([
                'message' => 'Your account has been suspended.',
                'code'    => 'ACCOUNT_SUSPENDED',
            ], 403);
        }

        // If specific roles required, check them
        if (!empty($roles) && !in_array($user->role, $roles)) {
            return response()->json([
                'message' => 'You do not have permission to perform this action.',
                'code'    => 'INSUFFICIENT_ROLE',
                'required_roles' => $roles,
                'your_role'      => $user->role,
            ], 403);
        }

        return $next($request);
    }
}
