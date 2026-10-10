<?php

namespace App\Http\Middleware;

use App\Services\Auth\SingleSessionService;
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
        foreach (['web', 'applicant'] as $guard) {
            $this->sessions->release($request, $guard);

            if ($guard !== $this->auth->getDefaultDriver()) {
                $this->sessions->discard($request, $guard);
            }
        }

        parent::logout($request);
    }
}
