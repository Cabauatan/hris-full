<?php

namespace Tests\Feature;

use App\Http\Requests\IndexNotificationRequest;
use App\Http\Requests\ReadAllNotificationsRequest;
use App\Http\Requests\ReadNotificationRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\HrisNotification;
use App\Services\Notification\NotificationInboxService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\FormRequestTestCase;

class NotificationApiTest extends FormRequestTestCase
{
    public function test_inbox_service_scopes_counts_pages_and_atomic_read_updates_to_owner(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $actor = $this->user();
        $other = $this->user();
        $first = $this->notification($actor, ['id' => '00000000-0000-4000-8000-000000000001']);
        $second = $this->notification($actor, ['id' => '00000000-0000-4000-8000-000000000002']);
        $read = $this->notification($actor, ['read_at' => '2026-10-04 09:00:00']);
        $foreign = $this->notification($other);
        $service = app(NotificationInboxService::class);

        $page = $service->paginate($actor, ['filter' => 'unread', 'per_page' => 1]);
        $this->assertSame(2, $page->total());
        $this->assertSame($second->id, $page->items()[0]->id);
        $this->assertSame(2, $service->unreadCount($actor));
        $this->assertSame('2026-10-05 10:00:00', $service->markRead($actor, $first)->read_at->format('Y-m-d H:i:s'));
        $this->travelTo('2026-10-05 11:00:00');
        $this->assertSame('2026-10-05 10:00:00', $service->markRead($actor, $first)->read_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $service->markAllRead($actor));
        $this->assertSame(0, $service->markAllRead($actor));
        $this->assertSame(0, $service->unreadCount($actor));
        $this->assertSame('2026-10-04 09:00:00', $read->fresh()->read_at->format('Y-m-d H:i:s'));
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_notification_policy_requires_active_exact_morph_owner(): void
    {
        $owner = $this->user();
        $other = $this->user('employees.view');
        $notification = $this->notification($owner);

        $this->assertTrue($owner->can('viewAny', DatabaseNotification::class));
        $this->assertTrue($owner->can('view', $notification));
        $this->assertTrue($owner->can('update', $notification));
        $this->assertTrue($owner->can('markAllAsRead', DatabaseNotification::class));
        $this->assertFalse($other->can('view', $notification));
        $this->assertFalse($other->can('update', $notification));
        $notification->notifiable_type = User::class;
        $this->assertFalse($owner->can('view', $notification));
        $notification->notifiable_type = $owner->getMorphClass();
        $owner->update(['is_active' => false]);
        $this->assertFalse($owner->can('viewAny', DatabaseNotification::class));
        $this->assertFalse($owner->can('view', $notification));
        $this->assertFalse($owner->can('update', $notification));
        $this->assertFalse($owner->can('markAllAsRead', DatabaseNotification::class));
    }

    public function test_notification_form_requests_validate_filters_and_resolve_owned_uuid(): void
    {
        $actor = $this->user();
        $notification = $this->notification($actor);

        $this->assertSame(['filter' => 'unread', 'page' => 2, 'per_page' => 100], $this->validateRequest(IndexNotificationRequest::class, ['filter' => 'unread', 'page' => 2, 'per_page' => 100], $actor));
        $this->assertSame([], $this->validateRequest(ReadNotificationRequest::class, [], $actor, ['notification' => $notification->id]));
        $this->assertSame([], $this->validateRequest(ReadAllNotificationsRequest::class, [], $actor));
        $this->assertInvalidRequest(IndexNotificationRequest::class, ['filter' => ['unread'], 'per_page' => 101, 'page' => 0, 'notifiable_id' => 1], $actor, fields: ['filter', 'per_page', 'page', 'notifiable_id']);
        $this->assertInvalidRequest(ReadNotificationRequest::class, ['read_at' => '2020-01-01'], $actor, ['notification' => $notification->id], ['read_at']);
        $this->assertUnauthorizedRequest(IndexNotificationRequest::class, [], null);
        $actor->update(['is_active' => false]);
        $this->assertUnauthorizedRequest(ReadAllNotificationsRequest::class, [], $actor);
    }

    #[TestWith(['GET', '/api/v1/notifications'])]
    #[TestWith(['GET', '/api/v1/notifications/unread-count'])]
    #[TestWith(['PATCH', '/api/v1/notifications/read-all'])]
    #[TestWith(['PATCH', '/api/v1/notifications/00000000-0000-4000-8000-000000000001/read'])]
    public function test_guests_receive_401(string $method, string $url): void
    {
        $this->json($method, $url)->assertUnauthorized();
    }

    #[TestWith(['GET', '/api/v1/notifications'])]
    #[TestWith(['GET', '/api/v1/notifications/unread-count'])]
    #[TestWith(['PATCH', '/api/v1/notifications/read-all'])]
    #[TestWith(['PATCH', '/api/v1/notifications/00000000-0000-4000-8000-000000000001/read'])]
    public function test_inactive_accounts_receive_403(string $method, string $url): void
    {
        $actor = $this->user();
        $actor->update(['is_active' => false]);

        $this->actingAs($actor)->json($method, $url)->assertForbidden();
    }

    public function test_active_permissionless_account_receives_empty_paginated_inbox(): void
    {
        $this->actingAs($this->user())->getJson('/api/v1/notifications')
            ->assertOk()->assertJsonPath('data', [])
            ->assertJsonPath('meta.unread_count', 0)->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.per_page', 15)->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next']]);
        $this->getJson('/api/v1/notifications/unread-count')->assertExactJson(['data' => ['unread_count' => 0]]);
    }

    public function test_paginated_inbox_has_global_unread_count_stable_order_and_only_owned_rows(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $actor = $this->user();
        $first = $this->notification($actor, ['id' => '00000000-0000-4000-8000-000000000001']);
        $second = $this->notification($actor, ['id' => '00000000-0000-4000-8000-000000000002']);
        $read = $this->notification($actor, ['created_at' => '2026-10-04 08:00:00', 'read_at' => '2026-10-04 09:00:00']);
        $this->notification($this->user());
        DatabaseNotification::query()->create(['id' => (string) Str::uuid(), 'type' => HrisNotification::class, 'notifiable_type' => 'employee', 'notifiable_id' => $actor->id, 'data' => []]);

        $this->actingAs($actor)->getJson('/api/v1/notifications?per_page=1&page=2')
            ->assertOk()->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.unread_count', 2)
            ->assertJsonPath('meta.current_page', 2)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/notifications?filter=unread&per_page=100')
            ->assertOk()->assertJsonPath('data.0.id', $second->id)->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.unread_count', 2)->assertJsonMissing(['id' => $read->id]);
        $this->getJson('/api/v1/notifications/unread-count')->assertExactJson(['data' => ['unread_count' => 2]]);
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_or_forged_queries_receive_422(array $query, string $field, string $message): void
    {
        $response = $this->actingAs($this->user())->getJson('/api/v1/notifications?'.http_build_query($query))
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertContains($message, $response->json('errors.'.$field));
    }

    public static function invalidQueries(): array
    {
        return [
            'unsupported filter' => [['filter' => 'read'], 'filter', 'The selected filter is invalid.'],
            'array filter' => [['filter' => ['unread']], 'filter', 'The filter field must be a string.'],
            'empty filter' => [['filter' => ''], 'filter', 'The filter field must be a string.'],
            'zero page' => [['page' => 0], 'page', 'The page field must be at least 1.'],
            'negative page' => [['page' => -1], 'page', 'The page field must be at least 1.'],
            'fractional page' => [['page' => 1.5], 'page', 'The page field must be an integer.'],
            'array page' => [['page' => [1]], 'page', 'The page field must be an integer.'],
            'zero page size' => [['per_page' => 0], 'per_page', 'The per page field must be between 1 and 100.'],
            'oversized page' => [['per_page' => 101], 'per_page', 'The per page field must be between 1 and 100.'],
            'fractional page size' => [['per_page' => 1.5], 'per_page', 'The per page field must be an integer.'],
            'array page size' => [['per_page' => [20]], 'per_page', 'The per page field must be an integer.'],
            'forged user' => [['user_id' => 123], 'user_id', 'The user id field is prohibited.'],
            'forged notifiable' => [['notifiable_id' => 123], 'notifiable_id', 'The notifiable id field is prohibited.'],
            'forged morph type' => [['notifiable_type' => 'employee'], 'notifiable_type', 'The notifiable type field is prohibited.'],
        ];
    }

    public function test_foreign_notification_receives_404_without_changing_read_state(): void
    {
        $actor = $this->user('employees.view');
        $foreign = $this->notification($this->user());

        $this->actingAs($actor)->patchJson('/api/v1/notifications/'.$foreign->id.'/read')->assertNotFound();

        $this->assertNull($foreign->fresh()->read_at);
    }

    #[TestWith(['00000000-0000-4000-8000-999999999999'])]
    #[TestWith(['123'])]
    #[TestWith(['not-a-uuid'])]
    public function test_missing_or_non_uuid_notifications_receive_404(string $id): void
    {
        $this->actingAs($this->user())->patchJson('/api/v1/notifications/'.$id.'/read')->assertNotFound();
    }

    public function test_read_action_persists_server_time_and_preserves_it_on_repeated_calls(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $actor = $this->user();
        $notification = $this->notification($actor);
        $this->notification($actor);

        $this->actingAs($actor)->patchJson('/api/v1/notifications/'.$notification->id.'/read')
            ->assertOk()->assertJsonPath('data.id', $notification->id)
            ->assertJsonPath('data.read_at', '2026-10-05T10:00:00+08:00')->assertJsonPath('meta.unread_count', 1);
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'read_at' => '2026-10-05 10:00:00']);
        $this->travelTo('2026-10-05 12:00:00');
        $this->patchJson('/api/v1/notifications/'.$notification->id.'/read')
            ->assertOk()->assertJsonPath('data.read_at', '2026-10-05T10:00:00+08:00')->assertJsonPath('meta.unread_count', 1);
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'read_at' => '2026-10-05 10:00:00']);
    }

    public function test_read_all_changes_only_owned_unread_records_and_is_idempotent(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $actor = $this->user();
        $unread = $this->notification($actor);
        $read = $this->notification($actor, ['read_at' => '2026-10-04 09:00:00']);
        $foreign = $this->notification($this->user());

        $this->actingAs($actor)->patchJson('/api/v1/notifications/read-all')->assertExactJson(['data' => ['updated' => 1, 'unread_count' => 0]]);
        $this->assertDatabaseHas('notifications', ['id' => $unread->id, 'read_at' => '2026-10-05 10:00:00']);
        $this->assertDatabaseHas('notifications', ['id' => $read->id, 'read_at' => '2026-10-04 09:00:00']);
        $this->assertNull($foreign->fresh()->read_at);
        $this->travelTo('2026-10-05 12:00:00');
        $this->patchJson('/api/v1/notifications/read-all')->assertExactJson(['data' => ['updated' => 0, 'unread_count' => 0]]);
        $this->assertDatabaseHas('notifications', ['id' => $unread->id, 'read_at' => '2026-10-05 10:00:00']);
    }

    #[TestWith(['read_at', '2020-01-01'])]
    #[TestWith(['data', ['private' => 'replacement']])]
    #[TestWith(['notifiable_id', 123])]
    #[TestWith(['notifiable_type', 'employee'])]
    public function test_read_actions_reject_client_controlled_state_with_422(string $field, mixed $value): void
    {
        $actor = $this->user();
        $notification = $this->notification($actor);

        $this->actingAs($actor)->patchJson('/api/v1/notifications/'.$notification->id.'/read', [$field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson('/api/v1/notifications/read-all', [$field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertNull($notification->fresh()->read_at);
        $this->assertSame($actor->id, $notification->fresh()->notifiable_id);
    }

    public function test_safe_notification_dto_resolves_current_permission_and_omits_private_payload(): void
    {
        $actor = $this->user('leave.view');
        $employee = $this->employee($actor);
        $type = LeaveType::query()->create(['code' => 'SICK', 'name' => 'Sick', 'is_active' => true]);
        $record = LeaveRequest::query()->create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id,
            'start_date' => '2026-10-05', 'end_date' => '2026-10-05',
            'day_portion' => 'full', 'requested_units' => 1, 'status' => 'approved',
            'reason' => 'Private medical details', 'document_path' => 'private/medical.pdf',
            'review_remarks' => 'Private supervisor remarks', 'created_by_user_id' => $actor->id,
        ]);
        $notification = $this->notification($actor, ['data' => [
            'resource_type' => 'leave', 'resource_id' => $record->id, 'event' => 'approved',
            'private' => 'Private payload', 'url' => 'https://attacker.test', 'title' => 'Injected title',
        ]]);

        $response = $this->actingAs($actor)->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.0.source', ['module' => 'leave', 'id' => $record->id, 'scope' => 'mine'])
            ->assertJsonPath('data.0.title', 'Leave request approved');
        $this->assertSame(['id', 'title', 'message', 'category', 'event', 'created_at', 'read_at', 'source'], array_keys($response->json('data.0')));
        $this->assertStringNotContainsString('Private', $response->getContent());
        $this->assertStringNotContainsString('attacker', $response->getContent());
        $this->assertStringNotContainsString('Injected', $response->getContent());
        $actor->permissions()->detach();
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.source', null)->assertJsonPath('data.0.title', 'HRIS update');
        $this->patchJson('/api/v1/notifications/'.$notification->id.'/read')->assertOk()->assertJsonPath('data.source', null);
    }

    public function test_unknown_notification_payload_is_presented_as_generic_update_without_links(): void
    {
        $actor = $this->user('employees.view', 'leave.view');
        $this->notification($actor, ['type' => 'UnknownNotification', 'data' => ['resource_type' => 'leave', 'resource_id' => 1, 'event' => 'approved', 'secret' => 'Sensitive payload']]);

        $response = $this->actingAs($actor)->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.0.source', null)->assertJsonPath('data.0.title', 'HRIS update');
        $this->assertStringNotContainsString('Sensitive', $response->getContent());
    }

    public function test_distinct_source_records_are_loaded_once_for_an_inbox_page(): void
    {
        $actor = $this->user('leave.view');
        $employee = $this->employee($actor);
        $type = LeaveType::query()->create(['code' => 'VACATION', 'name' => 'Vacation', 'is_active' => true]);
        foreach (range(1, 20) as $index) {
            $record = LeaveRequest::query()->create([
                'employee_id' => $employee->id, 'leave_type_id' => $type->id,
                'start_date' => '2026-10-05', 'end_date' => '2026-10-05',
                'day_portion' => 'full', 'requested_units' => 1, 'status' => 'approved',
                'reason' => 'Vacation', 'created_by_user_id' => $actor->id,
            ]);
            $this->notification($actor, ['data' => ['resource_type' => 'leave', 'resource_id' => $record->id, 'event' => 'approved']]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->actingAs($actor)->getJson('/api/v1/notifications?per_page=100')->assertOk()->assertJsonCount(20, 'data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(20, count(array_unique(array_column(array_column($response->json('data'), 'source'), 'id'))));
        $sourceQueries = array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'from "leave_requests"'));
        $this->assertCount(1, $sourceQueries);
    }

    public function test_owned_inbox_notification_cannot_expose_a_foreign_source_record(): void
    {
        $actor = $this->user('leave.view');
        $this->employee($actor);
        $foreignEmployee = $this->employee();
        $type = LeaveType::query()->create(['code' => 'PRIVATE', 'name' => 'Private', 'is_active' => true]);
        $record = LeaveRequest::query()->create([
            'employee_id' => $foreignEmployee->id, 'leave_type_id' => $type->id,
            'start_date' => '2026-10-05', 'end_date' => '2026-10-05',
            'day_portion' => 'full', 'requested_units' => 1, 'status' => 'approved',
            'reason' => 'Private reason', 'created_by_user_id' => $actor->id,
        ]);
        $this->notification($actor, ['data' => ['resource_type' => 'leave', 'resource_id' => $record->id, 'event' => 'approved']]);

        $this->actingAs($actor)->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.0.source', null)->assertJsonPath('data.0.title', 'HRIS update');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function notification(User $user, array $attributes = []): DatabaseNotification
    {
        return $user->notifications()->create(array_replace([
            'id' => (string) Str::uuid(),
            'type' => HrisNotification::class,
            'data' => ['event' => 'approved', 'resource_type' => 'leave', 'resource_id' => 1],
            'read_at' => null,
        ], $attributes));
    }
}
