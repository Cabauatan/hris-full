<?php

namespace App\Http\Middleware;

use App\Services\Auth\SingleSessionService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function __construct(private readonly SingleSessionService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_active === true, 403, 'This account is inactive.');

        if (! $this->sessions->validate($request, $request->user(), 'web')) {
            $this->sessions->discard($request, 'web');

            throw new AuthenticationException('Unauthenticated.', ['web']);
        }

        return $next($request);
    }
}
