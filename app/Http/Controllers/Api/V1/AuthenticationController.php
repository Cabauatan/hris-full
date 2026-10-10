<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Resources\AuthenticatedUserResource;
use App\Services\Auth\AuthenticationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class AuthenticationController extends ApiController
{
    public function __construct(private AuthenticationService $authentication) {}

    public function csrfCookie(): Response
    {
        return response()->noContent();
    }

    public function login(LoginRequest $request): AuthenticatedUserResource
    {
        return new AuthenticatedUserResource($this->authentication->login($request, $request->validated()));
    }

    public function me(Request $request): AuthenticatedUserResource
    {
        Gate::authorize('view', $request->user());

        return new AuthenticatedUserResource($this->authentication->context($request->user()));
    }

    public function logout(Request $request): Response
    {
        $this->authentication->logout($request);

        return response()->noContent();
    }

    public function password(UpdatePasswordRequest $request): AuthenticatedUserResource
    {
        return new AuthenticatedUserResource($this->authentication->changePassword($request, $request->validated('current_password'), $request->validated('password')));
    }
}
