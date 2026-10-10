<?php

namespace Tests\Feature;

use App\Http\Requests\GeneratePayslipRequest;
use App\Http\Requests\MarkPayslipViewedRequest;
use App\Http\Requests\PublishPayslipRequest;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class PayslipFormRequestsTest extends FormRequestTestCase
{
    #[DataProvider('administrativeActions')]
    public function test_authorized_payslip_actions_ignore_client_document_and_audit_metadata(
        string $class,
        string $routeKey,
        string $permission,
    ): void {
        $actor = $this->user($permission);
        $target = $this->target($routeKey);

        $validated = $this->validateRequest($class, $this->forgedMetadata(), $actor, [$routeKey => $target]);

        $this->assertSame([], $validated);
    }

    #[DataProvider('administrativeActions')]
    public function test_payslip_actions_reject_an_actor_without_the_action_permission(
        string $class,
        string $routeKey,
        string $permission,
    ): void {
        $viewer = $this->user('payslips.view');
        $target = $this->target($routeKey);

        $this->assertUnauthorizedRequest($class, [], $viewer, [$routeKey => $target]);
    }

    #[DataProvider('administrativeActions')]
    public function test_payslip_actions_reject_inactive_or_missing_actors(
        string $class,
        string $routeKey,
        string $permission,
    ): void {
        $actor = $this->user($permission);
        $actor->update(['is_active' => false]);
        $target = $this->target($routeKey);

        $this->assertUnauthorizedRequest($class, [], $actor, [$routeKey => $target]);
        $this->assertUnauthorizedRequest($class, [], null, [$routeKey => $target]);
    }

    #[DataProvider('administrativeActions')]
    public function test_payslip_actions_require_the_exact_typed_route_binding(
        string $class,
        string $routeKey,
        string $permission,
    ): void {
        $actor = $this->user($permission);
        $target = $this->target($routeKey);

        $this->assertUnauthorizedRequest($class, [], $actor);
        $this->assertUnauthorizedRequest($class, [], $actor, [$routeKey => $target->id]);
        $this->assertUnauthorizedRequest($class, [], $actor, [$routeKey => new Employee]);
        $this->assertUnauthorizedRequest($class, [], $actor, ['document' => $target]);
    }

    public static function administrativeActions(): array
    {
        return [
            'generate' => [GeneratePayslipRequest::class, 'payrollEntry', 'payslips.generate'],
            'publish' => [PublishPayslipRequest::class, 'payslip', 'payslips.publish'],
        ];
    }

    public function test_owner_can_mark_the_payslip_viewed_without_setting_view_metadata(): void
    {
        $owner = $this->user('payslips.view');
        $employee = $this->employee($owner);
        $payslip = $this->payslip($employee);

        $validated = $this->validateRequest(MarkPayslipViewedRequest::class, $this->forgedMetadata(), $owner, ['payslip' => $payslip]);

        $this->assertSame([], $validated);
    }

    public function test_employee_cannot_mark_another_employees_payslip_viewed(): void
    {
        $viewer = $this->user('payslips.view');
        $this->employee($viewer);
        $payslip = $this->payslip();

        $this->assertUnauthorizedRequest(MarkPayslipViewedRequest::class, [], $viewer, ['payslip' => $payslip]);
    }

    public function test_administrator_can_view_but_cannot_mark_another_employees_payslip_viewed(): void
    {
        $administrator = $this->user('payslips.view', 'employees.view');
        $this->employee($administrator);
        $payslip = $this->payslip();

        $this->assertTrue($administrator->can('view', $payslip));
        $this->assertUnauthorizedRequest(MarkPayslipViewedRequest::class, [], $administrator, ['payslip' => $payslip]);
    }

    public function test_owner_without_view_permission_cannot_mark_the_payslip_viewed(): void
    {
        $owner = $this->user();
        $employee = $this->employee($owner);
        $payslip = $this->payslip($employee);

        $this->assertUnauthorizedRequest(MarkPayslipViewedRequest::class, [], $owner, ['payslip' => $payslip]);
    }

    public function test_inactive_owner_cannot_mark_the_payslip_viewed(): void
    {
        $owner = $this->user('payslips.view');
        $owner->update(['is_active' => false]);
        $employee = $this->employee($owner);
        $payslip = $this->payslip($employee);

        $this->assertUnauthorizedRequest(MarkPayslipViewedRequest::class, [], $owner, ['payslip' => $payslip]);
    }

    public function test_unauthenticated_request_cannot_mark_the_payslip_viewed(): void
    {
        $payslip = $this->payslip();

        $this->assertUnauthorizedRequest(MarkPayslipViewedRequest::class, [], null, ['payslip' => $payslip]);
    }

    public function test_mark_viewed_requires_the_exact_typed_payslip_route_binding(): void
    {
        $owner = $this->user('payslips.view');
        $employee = $this->employee($owner);
        $payslip = $this->payslip($employee);

        $this->assertUnauthorizedRequest(MarkPayslipViewedRequest::class, [], $owner);
        $this->assertUnauthorizedRequest(MarkPayslipViewedRequest::class, [], $owner, ['payslip' => $payslip->id]);
        $this->assertUnauthorizedRequest(MarkPayslipViewedRequest::class, [], $owner, ['payslip' => $payslip->payrollEntry]);
        $this->assertUnauthorizedRequest(MarkPayslipViewedRequest::class, [], $owner, ['document' => $payslip]);
    }

    private function target(string $routeKey): PayrollEntry|Payslip
    {
        return $routeKey === 'payrollEntry' ? $this->entry() : $this->payslip();
    }

    private function entry(?Employee $employee = null): PayrollEntry
    {
        $period = PayrollPeriod::query()->create([
            'code' => 'TEST-'.Str::uuid(),
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'pay_date' => '2026-11-05',
            'pay_frequency' => 'monthly',
            'status' => 'open',
        ]);
        $run = PayrollRun::query()->create([
            'payroll_period_id' => $period->id,
            'run_number' => 1,
            'status' => 'finalized',
            'finalized_at' => '2026-11-01 09:00:00',
        ]);

        return PayrollEntry::query()->create([
            'payroll_run_id' => $run->id,
            'employee_id' => ($employee ?? $this->employee())->id,
            'basic_salary' => '10000.00',
            'pay_frequency' => 'monthly',
            'currency' => 'PHP',
            'gross_pay' => '10000.00',
            'total_deductions' => '1000.00',
            'net_pay' => '9000.00',
            'status' => 'draft',
            'calculated_at' => '2026-11-01 08:00:00',
        ]);
    }

    private function payslip(?Employee $employee = null): Payslip
    {
        $entry = $this->entry($employee);

        return Payslip::query()->create([
            'payroll_entry_id' => $entry->id,
            'employee_id' => $entry->employee_id,
            'file_path' => 'payslips/test-document.pdf',
            'generated_at' => '2026-11-01 09:05:00',
            'published_at' => '2026-11-01 10:00:00',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function forgedMetadata(): array
    {
        return [
            'payroll_entry_id' => 999999,
            'employee_id' => 999999,
            'file_path' => '../../forged.pdf',
            'generated_at' => '1900-01-01 00:00:00',
            'generated_by_user_id' => 999999,
            'published_at' => '1900-01-01 00:00:00',
            'published_by_user_id' => 999999,
            'viewed_at' => '1900-01-01 00:00:00',
        ];
    }
}
