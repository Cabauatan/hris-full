<?php

namespace Tests\Feature;

use App\Http\Requests\ManageTelegramConnectionRequest;
use App\Models\Applicant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;
use Tests\FormRequestTestCase;

class TelegramConnectionRequestTest extends FormRequestTestCase
{
    public function test_active_accounts_can_validate_empty_self_service_requests(): void
    {
        foreach ([$this->user(), Applicant::factory()->create()] as $actor) {
            $this->assertSame([], $this->validateConnectionRequest($actor));
        }
    }

    public function test_account_and_chat_identifiers_are_rejected_for_both_realms(): void
    {
        $payload = ['user_id' => 1, 'applicant_id' => 1, 'recipient_id' => 1,
            'recipient_type' => 'user', 'chat_id' => '12345', 'bot_id' => 12345, 'token' => 'forged'];

        foreach ([$this->user(), Applicant::factory()->create()] as $actor) {
            try {
                $this->validateConnectionRequest($actor, $payload);
                $this->fail('Client-selected account or chat must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertEqualsCanonicalizing(array_keys($payload), array_keys($exception->errors()));
            }
        }
    }

    public function test_guests_and_inactive_accounts_are_unauthorized(): void
    {
        $user = $this->user();
        $user->update(['is_active' => false]);

        foreach ([null, $user, Applicant::factory()->inactive()->create()] as $actor) {
            try {
                $this->validateConnectionRequest($actor);
                $this->fail('Inactive or guest actor must be unauthorized.');
            } catch (AuthorizationException $exception) {
                $this->assertInstanceOf(AuthorizationException::class, $exception);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateConnectionRequest(User|Applicant|null $actor, array $payload = []): array
    {
        $request = ManageTelegramConnectionRequest::createFrom(Request::create('/telegram', 'POST', $payload));
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(Redirector::class));
        $request->setUserResolver(static fn (): User|Applicant|null => $actor);
        $request->validateResolved();

        return $request->validated();
    }
}
