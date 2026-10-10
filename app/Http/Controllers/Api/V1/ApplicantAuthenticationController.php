<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\LoginApplicantRequest;
use App\Http\Requests\RegisterApplicantRequest;
use App\Http\Resources\ApplicantResource;
use App\Services\Auth\ApplicantAuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ApplicantAuthenticationController extends ApiController
{
    public function __construct(private ApplicantAuthenticationService $authentication) {}

    public function csrfCookie(): Response
    {
        return response()->noContent();
    }

    public function register(RegisterApplicantRequest $request): JsonResponse
    {
        return (new ApplicantResource($this->authentication->register($request, $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function login(LoginApplicantRequest $request): ApplicantResource
    {
        return new ApplicantResource($this->authentication->login($request, $request->validated()));
    }

    public function me(Request $request): ApplicantResource
    {
        return new ApplicantResource($request->user('applicant'));
    }

    public function logout(Request $request): Response
    {
        $this->authentication->logout($request);

        return response()->noContent();
    }
}
