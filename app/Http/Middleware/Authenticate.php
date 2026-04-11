<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }

    public function handle($request, \Closure $next, ...$guards)
    {
        $request->headers->set('Accept', 'application/json');
        return parent::handle($request, $next, ...$guards);
    }
}
