<?php

namespace App\Http\Middleware;

use App\Services\Auth\SingleSessionService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApplicantIsActive
{
    public function __construct(private readonly SingleSessionService $sessions) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user('applicant')?->is_active === true, 403, 'This applicant account is inactive.');

        if (! $this->sessions->validate($request, $request->user('applicant'), 'applicant')) {
            $this->sessions->discard($request, 'applicant');

            throw new AuthenticationException('Unauthenticated.', ['applicant']);
        }

        return $next($request);
    }
}
