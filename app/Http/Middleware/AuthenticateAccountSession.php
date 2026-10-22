<?php

namespace App\Http\Middleware;

use App\Services\Auth\SingleSessionService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

class AuthenticateAccountSession extends AuthenticateSession
{
    public function __construct(AuthFactory $auth, private readonly SingleSessionService $sessions)
    {
        parent::__construct($auth);
    }

    /** @param Request $request */
    protected function logout($request): never
    {
        $currentGuard = $this->auth->getDefaultDriver();

        if (! $this->sessions->release($request, $currentGuard)) {
            $this->sessions->discard($request, $currentGuard);

            throw new AuthenticationException('Unauthenticated.', [$currentGuard], $this->redirectTo($request));
        }

        foreach (['web', 'applicant'] as $guard) {
            if ($guard !== $currentGuard) {
                $this->sessions->release($request, $guard);
                $this->sessions->discard($request, $guard);
            }
        }

        parent::logout($request);
    }
}
