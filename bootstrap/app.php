<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Http\Middleware\VerifiedKyc;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\SecurityHeaders;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        $middleware->replace(\Illuminate\Auth\Middleware\Authenticate::class, \App\Http\Middleware\Authenticate::class);
        \Illuminate\Auth\Middleware\Authenticate::redirectUsing(fn() => null);

        $middleware->alias([
            'verified'     => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            'verified.kyc' => VerifiedKyc::class,
            'admin'        => AdminMiddleware::class,
        ]);

        $middleware->throttleApi();
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request) {
            return $request->is('api/*');
        });

        // Return 401 JSON instead of redirecting to route('login')
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        // Return RuntimeException messages as friendly JSON 422 responses
        $exceptions->render(function (\RuntimeException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });

        // Catch-all: never expose raw 500 errors to API clients
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                \Illuminate\Support\Facades\Log::error('Unhandled exception', [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ]);
                return response()->json([
                    'message' => 'Something went wrong. Please try again or contact support.'
                ], 500);
            }
        });
    })->create();
