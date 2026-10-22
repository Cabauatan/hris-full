<?php

namespace Tests\Feature;

use App\Jobs\DeliverHrisNotification;
use App\Models\CtoBalance;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\FormRequestTestCase;

class WorkflowLifecycleIntegrationTest extends FormRequestTestCase
{
    /** @var array<string, array<string, string>> */
    private array $browserCookies = [];

    public function test_separate_employee_and_supervisor_sessions_complete_cto_review_with_private_notifications_and_one_credit_debit(): void
    {
        $this->travelTo('2026-10-17 09:00:00');
        $supervisor = $this->user('cto.view', 'cto.approve', 'cto.manage-credits', 'employees.view-team');
        $supervisorEmployee = $this->employee($supervisor);
        $employeeUser = $this->user('cto.view', 'cto.create');
        $employee = $this->employee($employeeUser, ['supervisor_employee_id' => $supervisorEmployee->id]);
        $otherUser = $this->user('cto.view', 'cto.create', 'cto.approve');
        $otherEmployee = $this->employee($otherUser);
        Queue::fake([DeliverHrisNotification::class]);

        foreach (['supervisor' => $supervisorEmployee, 'employee' => $employee, 'other' => $otherEmployee] as $browser => $account) {
            $this->requestAs($browser, 'POST', '/api/v1/auth/login', [
                'employee_number' => $account->employee_number, 'password' => 'password',
            ])->assertOk()->assertJsonPath('data.id', $account->user_id);
        }

        $this->requestAs('supervisor', 'POST', '/api/v1/cto-balances/adjust', [
            'employee_id' => $employee->id, 'minutes' => 120, 'effective_date' => '2026-10-17',
            'remarks' => 'Opening earned credits.',
        ])->assertCreated()->assertJsonPath('data.minutes', 120)->assertJsonPath('data.transaction_type', 'manual_credit');
        $this->requestAs('employee', 'GET', '/api/v1/ess/cto-balances')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.balance_minutes', 120);
        $requestId = $this->requestAs('employee', 'POST', '/api/v1/cto-requests', [
            'start_at' => '2026-10-19 08:00:00', 'end_at' => '2026-10-19 09:00:00',
            'reason' => 'Personal appointment.',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.requested_minutes', 60)
            ->assertJsonPath('data.approved_minutes', null)->assertJsonPath('data.employee_id', $employee->id)
            ->json('data.id');
        $this->requestAs('employee', 'POST', '/api/v1/cto-requests/'.$requestId.'/approve', ['approved_minutes' => 45])
            ->assertForbidden();
        $this->requestAs('other', 'GET', '/api/v1/cto-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->requestAs('other', 'GET', '/api/v1/cto-requests/'.$requestId)->assertForbidden();
        $this->requestAs('other', 'POST', '/api/v1/cto-requests/'.$requestId.'/approve', ['approved_minutes' => 45])
            ->assertForbidden();
        $this->requestAs('other', 'POST', '/api/v1/cto-requests/'.$requestId.'/cancel', ['reason' => 'Unauthorized cancellation.'])
            ->assertForbidden();
        $this->assertDatabaseHas('cto_requests', ['id' => $requestId, 'status' => 'pending', 'reviewed_by_user_id' => null]);
        $this->assertSame(120, CtoBalance::query()->where('employee_id', $employee->id)->value('balance_minutes'));
        $this->assertDatabaseCount('cto_credit_transactions', 1);
        $this->assertDatabaseCount('approval_actions', 0);
        Queue::assertPushed(DeliverHrisNotification::class, 1);

        Queue::pushed(DeliverHrisNotification::class, fn (DeliverHrisNotification $job): bool => $job->resourceId === $requestId && $job->event === 'pending')
            ->sole()->handle(app(NotificationService::class));
        $this->requestAs('supervisor', 'GET', '/api/v1/notifications')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.event', 'pending')
            ->assertJsonPath('data.0.source.module', 'cto')->assertJsonPath('meta.unread_count', 1);
        $this->requestAs('employee', 'GET', '/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->requestAs('other', 'GET', '/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->requestAs('supervisor', 'GET', '/api/v1/cto-requests/'.$requestId)
            ->assertOk()->assertJsonPath('data.abilities.approve', true);
        $this->requestAs('supervisor', 'POST', '/api/v1/cto-requests/'.$requestId.'/approve', [
            'approved_minutes' => 45, 'remarks' => 'Approved for forty-five minutes.',
        ])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_minutes', 45)
            ->assertJsonPath('data.reviewed_by_user_id', $supervisor->id);

        Queue::pushed(DeliverHrisNotification::class, fn (DeliverHrisNotification $job): bool => $job->resourceId === $requestId && $job->event === 'approved')
            ->sole()->handle(app(NotificationService::class));
        $this->requestAs('employee', 'GET', '/api/v1/notifications')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.event', 'approved')
            ->assertJsonPath('data.0.source.module', 'cto')->assertJsonPath('meta.unread_count', 1);
        $this->requestAs('other', 'GET', '/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->requestAs('employee', 'GET', '/api/v1/cto-requests/'.$requestId)
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.requested_minutes', 60)
            ->assertJsonPath('data.approved_minutes', 45)->assertJsonPath('data.abilities.approve', false)
            ->assertJsonPath('data.approval_actions.0.acted_by_user_id', $supervisor->id);
        $this->requestAs('employee', 'GET', '/api/v1/ess/cto-balances')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.balance_minutes', 75);
        $ledger = $this->requestAs('employee', 'GET', '/api/v1/ess/cto-credit-transactions')
            ->assertOk()->assertJsonCount(2, 'data')->json('data');
        $usage = collect($ledger)->sole(fn (array $transaction): bool => $transaction['transaction_type'] === 'cto_usage');
        $this->assertSame(-45, $usage['minutes']);
        $this->assertSame($requestId, $usage['cto_request_id']);
        $this->assertSame('2026-10-19', $usage['effective_date']);
        $this->requestAs('other', 'GET', '/api/v1/ess/cto-balances')->assertOk()->assertJsonCount(0, 'data');
        $this->requestAs('other', 'GET', '/api/v1/ess/cto-credit-transactions')->assertOk()->assertJsonCount(0, 'data');
        $this->requestAs('supervisor', 'POST', '/api/v1/cto-requests/'.$requestId.'/approve', ['approved_minutes' => 45])
            ->assertConflict();
        $this->requestAs('employee', 'POST', '/api/v1/cto-requests/'.$requestId.'/cancel', ['reason' => 'Too late.'])
            ->assertConflict();

        $this->assertDatabaseHas('cto_requests', ['id' => $requestId, 'status' => 'approved', 'approved_minutes' => 45]);
        $this->assertSame(75, CtoBalance::query()->where('employee_id', $employee->id)->value('balance_minutes'));
        $this->assertDatabaseCount('cto_credit_transactions', 2);
        $this->assertDatabaseCount('approval_actions', 1);
        $this->assertDatabaseCount('notifications', 2);
        Queue::assertPushed(DeliverHrisNotification::class, 2);
    }

    /** @param array<string, mixed> $payload */
    private function requestAs(string $browser, string $method, string $url, array $payload = []): TestResponse
    {
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->withCredentials()->withUnencryptedCookies($this->browserCookies[$browser] ?? []);
        $this->app['session']->driver()->flush();
        $this->app['auth']->forgetGuards();
        $response = $this->json($method, $url, $payload);

        foreach ($response->headers->getCookies() as $cookie) {
            $this->browserCookies[$browser][$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }
}
