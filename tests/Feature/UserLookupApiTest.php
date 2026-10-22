<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\TestWith;
use Tests\FormRequestTestCase;

class UserLookupApiTest extends FormRequestTestCase
{
    public function test_guest_and_inactive_account_cannot_read_lookup_names(): void
    {
        $inactive = $this->user('employees.create');
        $inactive->update(['is_active' => false]);

        $this->getJson('/api/v1/lookups/users?purpose=employee-link')->assertUnauthorized();
        $this->actingAs($inactive)->getJson('/api/v1/lookups/users?purpose=employee-link')->assertForbidden();
    }

    #[TestWith(['employees.view', 'employee-link'])]
    #[TestWith(['employees.view-team', 'employee-link'])]
    #[TestWith(['recruitment.view', 'employee-link'])]
    #[TestWith(['employees.create', 'delegate'])]
    #[TestWith(['employees.update', 'delegator'])]
    #[TestWith(['approval-delegations.view', 'delegate'])]
    #[TestWith(['approval-delegations.manage', 'employee-link'])]
    public function test_lookup_purpose_requires_its_existing_write_permission(string $permission, string $purpose): void
    {
        $actor = $this->user($permission);

        $this->actingAs($actor)->getJson('/api/v1/lookups/users?purpose='.$purpose)->assertForbidden();
    }

    #[TestWith(['employees.create'])]
    #[TestWith(['employees.update'])]
    public function test_employee_link_lookup_returns_only_ids_and_names_for_existing_linkable_accounts(string $permission): void
    {
        $actor = $this->user($permission);
        $actor->update(['name' => 'Zebra HR']);
        $account = $this->user();
        $account->update(['name' => 'Alice Employee', 'is_active' => false]);

        $response = $this->actingAs($actor)->getJson('/api/v1/lookups/users?purpose=employee-link&per_page=100');

        $response->assertOk()->assertExactJson([
            'data' => [['id' => $account->id, 'name' => 'Alice Employee'], ['id' => $actor->id, 'name' => 'Zebra HR']],
            'meta' => ['current_page' => 1, 'per_page' => 100, 'last_page' => 1, 'total' => 2],
        ]);
        $this->assertStringNotContainsString($account->email, $response->getContent());
    }

    public function test_delegator_lookup_remains_self_only_without_global_employee_view(): void
    {
        $actor = $this->user('approval-delegations.manage');
        $other = $this->user();

        $response = $this->actingAs($actor)->getJson('/api/v1/lookups/users?purpose=delegator&user_id='.$other->id.'&employee_id=999999');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0', ['id' => $actor->id, 'name' => $actor->name]);
    }

    public function test_global_delegator_and_delegate_lookups_preserve_existing_selection_scope(): void
    {
        $actor = $this->user('approval-delegations.manage', 'employees.view');
        $other = $this->user();

        $this->actingAs($actor)->getJson('/api/v1/lookups/users?purpose=delegator')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/lookups/users?purpose=delegate')->assertOk()->assertJsonCount(2, 'data');
        $this->assertModelExists($other);
    }

    #[TestWith(['purpose=unknown', 'purpose'])]
    #[TestWith(['purpose[]=employee-link', 'purpose'])]
    #[TestWith(['purpose=employee-link&per_page=101', 'per_page'])]
    #[TestWith(['purpose=employee-link&page=0', 'page'])]
    #[TestWith(['per_page=10', 'purpose'])]
    public function test_lookup_input_is_validated_and_pagination_is_bounded(string $query, string $field): void
    {
        $actor = $this->user('employees.create');

        $this->actingAs($actor)->getJson('/api/v1/lookups/users?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_lookup_pagination_exposes_names_on_all_pages(): void
    {
        $actor = $this->user('employees.create');
        $actor->update(['name' => 'Zebra HR']);
        $account = $this->user();
        $account->update(['name' => 'Alice Employee']);

        $this->actingAs($actor)->getJson('/api/v1/lookups/users?purpose=employee-link&per_page=1&page=2')
            ->assertOk()->assertJsonPath('data.0.name', 'Zebra HR')->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.last_page', 2);
    }
}
