<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\TelegramConnection;
use Illuminate\Support\Facades\Gate;
use Tests\FormRequestTestCase;

class TelegramConnectionPolicyTest extends FormRequestTestCase
{
    public function test_active_accounts_can_manage_only_their_own_account_in_their_realm(): void
    {
        $user = $this->user();
        $otherUser = $this->user();
        $applicant = Applicant::factory()->create(['id' => $user->id]);
        $otherApplicant = Applicant::factory()->create();

        $this->assertTrue(Gate::forUser($user)->allows('manage', [TelegramConnection::class, $user]));
        $this->assertTrue(Gate::forUser($applicant)->allows('manage', [TelegramConnection::class, $applicant]));
        $this->assertFalse(Gate::forUser($user)->allows('manage', [TelegramConnection::class, $otherUser]));
        $this->assertFalse(Gate::forUser($applicant)->allows('manage', [TelegramConnection::class, $otherApplicant]));
        $this->assertFalse(Gate::forUser($user)->allows('manage', [TelegramConnection::class, $applicant]));
        $this->assertFalse(Gate::forUser($applicant)->allows('manage', [TelegramConnection::class, $user]));
    }

    public function test_inactive_and_unsaved_accounts_cannot_manage_connections(): void
    {
        $user = $this->user();
        $user->update(['is_active' => false]);
        $applicant = Applicant::factory()->inactive()->create();
        $unsaved = Applicant::factory()->make();

        foreach ([$user, $applicant, $unsaved] as $actor) {
            $this->assertFalse(Gate::forUser($actor)->allows('manage', [TelegramConnection::class, $actor]));
        }
    }
}
