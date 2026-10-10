<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleApiBusinessExceptions
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('api_business_errors', true);

        return $next($request);
    }
}
