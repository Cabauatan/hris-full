<?php

namespace Tests\Feature;

use App\Http\Resources\ApprovalActionResource;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\CtoCreditTransactionResource;
use App\Http\Resources\DailyAttendanceResource;
use App\Http\Resources\DepartmentResource;
use App\Http\Resources\PayrollEntryResource;
use App\Models\ApprovalAction;
use App\Models\AuditLog;
use App\Models\CtoCreditTransaction;
use App\Models\CtoRequest;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Division;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryItem;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Shift;
use App\Models\WorkSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\FormRequestTestCase;

class ApiDisplayLabelsTest extends FormRequestTestCase
{
    public function test_self_attendance_displays_employee_schedule_and_shift_labels_without_expanding_visibility(): void
    {
        $actor = $this->user('attendance.view');
        $employee = $this->employee($actor, [
            'employee_number' => 'EMP-0001', 'first_name' => 'Marcel', 'middle_name' => 'Jay',
            'last_name' => 'Cabauatan', 'personal_email' => 'private@example.test',
        ]);
        $schedule = WorkSchedule::query()->create(['code' => 'OFFICE', 'name' => 'Office weekdays']);
        $shift = Shift::query()->create(['code' => 'DAY', 'name' => 'Day shift', 'start_time' => '08:00', 'end_time' => '17:00']);
        $attendance = DailyAttendance::query()->create([
            'employee_id' => $employee->id, 'attendance_date' => '2026-10-13',
            'work_schedule_id' => $schedule->id, 'shift_id' => $shift->id,
        ]);
        DailyAttendance::query()->create(['employee_id' => $this->employee()->id, 'attendance_date' => '2026-10-13']);

        $response = $this->actingAs($actor)->getJson('/api/v1/ess/daily-attendances');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $attendance->id)
            ->assertJsonPath('data.0.employee_id', $employee->id)
            ->assertJsonPath('data.0.display_labels.employee_id', 'EMP-0001 · Marcel Jay Cabauatan')
            ->assertJsonPath('data.0.display_labels.work_schedule_id', 'Office weekdays · OFFICE')
            ->assertJsonPath('data.0.display_labels.shift_id', 'Day shift · DAY')
            ->assertJsonMissingPath('data.0.employee')->assertJsonMissingPath('data.0.workSchedule');
        $this->assertStringNotContainsString('private@example.test', $response->getContent());
    }

    public function test_attendance_collection_loads_relation_labels_in_batches_including_paginated_results(): void
    {
        for ($index = 1; $index <= 6; $index++) {
            $employee = $this->employee(null, ['employee_number' => 'EMP-'.$index]);
            $schedule = WorkSchedule::query()->create(['code' => 'SCHEDULE-'.$index, 'name' => 'Schedule '.$index]);
            $shift = Shift::query()->create(['code' => 'SHIFT-'.$index, 'name' => 'Shift '.$index, 'start_time' => '08:00', 'end_time' => '17:00']);
            DailyAttendance::query()->create([
                'employee_id' => $employee->id, 'attendance_date' => '2026-10-13',
                'work_schedule_id' => $schedule->id, 'shift_id' => $shift->id,
            ]);
        }
        $records = DailyAttendance::query()->paginate(15);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $data = DailyAttendanceResource::collection($records)->resolve(Request::create('/'));

        $this->assertCount(6, $data);
        $this->assertCount(3, DB::getQueryLog());
        $this->assertSame('EMP-6 · Request Employee', $data[5]['display_labels']['employee_id']);
        $this->assertSame(['id', 'employee_number', 'first_name', 'middle_name', 'last_name', 'suffix'], array_keys($records[0]->employee->getAttributes()));
    }

    public function test_null_or_removed_relationships_have_no_fabricated_numeric_label(): void
    {
        $record = DailyAttendance::query()->create(['employee_id' => $this->employee()->id, 'attendance_date' => '2026-10-13']);
        $record->setRelation('employee', null);

        $data = (new DailyAttendanceResource($record))->resolve(Request::create('/'));

        $this->assertSame(['employee_id' => null, 'work_schedule_id' => null, 'shift_id' => null], $data['display_labels']);
        $this->assertNotNull($data['employee_id']);
    }

    public function test_organization_labels_use_names_and_codes_without_serializing_related_models(): void
    {
        $division = Division::query()->create(['code' => 'CORPORATE', 'name' => 'Corporate Services', 'description' => 'Private notes']);
        $department = Department::query()->create(['division_id' => $division->id, 'code' => 'HR', 'name' => 'Human Resources']);

        $data = (new DepartmentResource($department))->resolve(Request::create('/'));

        $this->assertSame('Corporate Services · CORPORATE', $data['display_labels']['division_id']);
        $this->assertArrayNotHasKey('division', $data);
        $this->assertStringNotContainsString('Private notes', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function test_approval_actor_labels_are_batched_and_polymorphic_targets_are_not_fetched(): void
    {
        $reviewer = $this->user();
        $reviewer->update(['name' => 'HR Reviewer']);
        for ($index = 1; $index <= 3; $index++) {
            ApprovalAction::query()->create([
                'approvable_type' => Employee::class, 'approvable_id' => 987654,
                'acted_by_user_id' => $reviewer->id, 'action' => 'approved', 'acted_at' => '2026-10-13 09:00:00',
            ]);
        }
        $records = ApprovalAction::all();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $data = ApprovalActionResource::collection($records)->resolve(Request::create('/'));

        $this->assertCount(1, DB::getQueryLog());
        $this->assertSame('HR Reviewer', $data[0]['display_labels']['acted_by_user_id']);
        $this->assertNull($data[0]['display_labels']['approvable_id']);
        $this->assertFalse($records[0]->relationLoaded('approvable'));
    }

    public function test_credit_transactions_reference_request_dates_and_actor_names(): void
    {
        $actor = $this->user();
        $actor->update(['name' => 'HR Officer']);
        $employee = $this->employee();
        $overtime = OvertimeRequest::query()->create([
            'employee_id' => $employee->id, 'start_at' => '2026-10-12 17:00', 'end_at' => '2026-10-12 19:00',
            'requested_minutes' => 120, 'reason' => 'Completion', 'status' => 'approved',
        ]);
        $cto = CtoRequest::query()->create([
            'employee_id' => $employee->id, 'start_at' => '2026-10-13 08:00', 'end_at' => '2026-10-13 10:00',
            'requested_minutes' => 120, 'reason' => 'Time off', 'status' => 'approved',
        ]);
        $transaction = CtoCreditTransaction::query()->create([
            'employee_id' => $employee->id, 'overtime_request_id' => $overtime->id, 'cto_request_id' => $cto->id,
            'minutes' => 120, 'transaction_type' => 'earned', 'effective_date' => '2026-10-13', 'created_by_user_id' => $actor->id,
        ]);

        $data = (new CtoCreditTransactionResource($transaction))->resolve(Request::create('/'));

        $this->assertSame('Overtime request · 2026-10-12 17:00 to 2026-10-12 19:00', $data['display_labels']['overtime_request_id']);
        $this->assertSame('CTO request · 2026-10-13 08:00 to 2026-10-13 10:00', $data['display_labels']['cto_request_id']);
        $this->assertSame('HR Officer', $data['display_labels']['created_by_user_id']);
    }

    public function test_payroll_entry_and_nested_items_use_employee_period_and_business_run_number(): void
    {
        $employee = $this->employee(null, ['employee_number' => 'EMP-0001', 'first_name' => 'Marcel', 'last_name' => 'Cabauatan']);
        $period = PayrollPeriod::query()->create([
            'code' => 'OCT-2026', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
            'pay_date' => '2026-11-05', 'pay_frequency' => 'monthly',
        ]);
        $run = PayrollRun::query()->create(['payroll_period_id' => $period->id, 'run_number' => 4]);
        $entry = PayrollEntry::query()->create([
            'payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'basic_salary' => 25000,
            'pay_frequency' => 'monthly', 'currency' => 'PHP',
        ]);
        PayrollEntryItem::query()->create([
            'payroll_entry_id' => $entry->id, 'code' => 'BASIC', 'name' => 'Basic pay', 'type' => 'earning',
            'quantity' => 1, 'rate' => 25000, 'amount' => 25000, 'source_type' => Employee::class, 'source_id' => $employee->id,
        ]);
        $records = PayrollEntry::query()->with('items')->get();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $data = PayrollEntryResource::collection($records)->resolve(Request::create('/'));
        $items = $data[0]['items']->resolve(Request::create('/'));

        $this->assertSame('OCT-2026 · 2026-10-01 to 2026-10-31 · Run 4', $data[0]['display_labels']['payroll_run_id']);
        $this->assertSame('EMP-0001 · Marcel Cabauatan · OCT-2026 · 2026-10-01 to 2026-10-31 · Run 4', $items[0]['display_labels']['payroll_entry_id']);
        $this->assertNull($items[0]['display_labels']['source_id']);
        $this->assertCount(3, DB::getQueryLog());
    }

    public function test_audit_metadata_keeps_internal_identifiers_without_fetching_arbitrary_targets(): void
    {
        $audit = AuditLog::query()->create([
            'action' => 'updated', 'auditable_type' => Employee::class, 'auditable_id' => 987654,
            'request_id' => 'opaque-request-reference',
        ]);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $data = (new AuditLogResource($audit))->resolve(Request::create('/'));

        $this->assertSame(['user_id' => null, 'auditable_id' => null, 'request_id' => null], $data['display_labels']);
        $this->assertSame(987654, $data['auditable_id']);
        $this->assertCount(0, DB::getQueryLog());
    }
}
