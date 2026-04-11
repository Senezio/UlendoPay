<?php

/**
 * Cross-Origin Resource Sharing (CORS) Configuration
 *
 * Controls which origins, methods, and headers are permitted
 * when the frontend communicates with this API.
 *
 * Security principle: allowlist only — never use wildcards in production
 * for origins or credentials-bearing requests.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | CORS-Enabled Paths
    |--------------------------------------------------------------------------
    | Only API routes and the Sanctum CSRF endpoint need CORS headers.
    | Never apply CORS globally to all paths.
    */
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed HTTP Methods
    |--------------------------------------------------------------------------
    | Explicitly list permitted methods rather than using wildcard.
    | OPTIONS is required for preflight requests.
    */
    'allowed_methods' => [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    | Production Netlify deployment and local development environments.
    | Never add * here — it would allow any origin to call the API.
    |
    | When the custom domain is live, add it here alongside Netlify.
    */
    'allowed_origins' => [
        'https://ulendopay.netlify.app',
        'https://payulendo.netlify.app',    // Production — Netlify
        'http://localhost:5173',             // Vite dev server default
        'http://localhost:3000',             // Alternative dev port
        'http://127.0.0.1:5173',            // Vite via 127.0.0.1
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins Patterns
    |--------------------------------------------------------------------------
    | Regex patterns for dynamic origins e.g. Netlify deploy previews.
    | Each PR/branch gets a unique preview URL — this allows them all.
    */
    'allowed_origins_patterns' => [
        '#^https://[a-z0-9-]+--ulendopay\.netlify\.app$#', // Netlify previews
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Headers
    |--------------------------------------------------------------------------
    | Explicitly list headers the frontend is permitted to send.
    | Authorization is required for Sanctum bearer tokens.
    | X-Idempotency-Key is required for safe transaction retries.
    */
    'allowed_headers' => [
        'Content-Type',
        'Accept',
        'Authorization',
        'X-Requested-With',
        'X-Idempotency-Key',
        'Origin',
    ],

    /*
    |--------------------------------------------------------------------------
    | Exposed Headers
    |--------------------------------------------------------------------------
    | Headers the browser is permitted to read from the response.
    | X-RateLimit-* allows the frontend to handle throttling gracefully.
    */
    'exposed_headers' => [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
    ],

    /*
    |--------------------------------------------------------------------------
    | Preflight Max Age
    |--------------------------------------------------------------------------
    | How long (seconds) the browser may cache a preflight response.
    | 7200 = 2 hours — reduces OPTIONS requests without being reckless.
    */
    'max_age' => 7200,

    /*
    |--------------------------------------------------------------------------
    | Supports Credentials
    |--------------------------------------------------------------------------
    | Must be false when using Sanctum bearer tokens (Authorization header).
    | Only set to true if using cookie-based Sanctum sessions,
    | which requires allowed_origins to have no wildcards.
    */
    'supports_credentials' => false,

];
