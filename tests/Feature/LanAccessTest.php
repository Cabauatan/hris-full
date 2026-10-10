<?php

namespace Tests\Feature;

use Tests\FormRequestTestCase;

class LanAccessTest extends FormRequestTestCase
{
    public function test_configured_frontend_can_preflight_a_session_authenticated_api_request(): void
    {
        $origin = config('cors.allowed_origins')[0];

        $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Content-Type, X-XSRF-TOKEN',
        ])->options('/api/v1/auth/login')->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_untrusted_site_cannot_preflight_a_credentialed_api_request(): void
    {
        $this->withHeaders([
            'Origin' => 'https://untrusted.example',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/v1/auth/login')->assertNoContent()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_allowing_the_frontend_origin_does_not_grant_access_to_a_guest(): void
    {
        $origin = config('cors.allowed_origins')[0];

        $this->withHeader('Origin', $origin)->getJson('/api/v1/auth/me')
            ->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.')
            ->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }
}
