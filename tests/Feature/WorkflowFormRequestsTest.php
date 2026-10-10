<?php

namespace Tests\Feature;

use App\Models\Announcement;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class WorkflowFormRequestsTest extends FormRequestTestCase
{
    #[DataProvider('workflows')]
    public function test_supervisor_can_approve_and_reject_and_employee_can_cancel(string $module, string $model, string $key, string $prefix): void
    {
        [$supervisor, $owner, $request] = $this->fixture($model, $prefix);
        $bindings = [$key => $request];
        $approval = in_array($module, ['Cto', 'Overtime'], true) ? ['approved_minutes' => 30] : [];

        $this->assertSame($approval, $this->validateRequest(self::requestClass('Approve', $module), $approval, $supervisor, $bindings));

        foreach ([[], ['remarks' => 'Needs revision.']] as $payload) {
            $this->assertSame($payload, $this->validateRequest(self::requestClass('Reject', $module), $payload, $supervisor, $bindings));
        }

        foreach ([[], ['reason' => 'Plans changed.']] as $payload) {
            $this->assertSame($payload, $this->validateRequest(self::requestClass('Cancel', $module), $payload, $owner, $bindings));
        }
    }

    #[DataProvider('workflows')]
    public function test_employee_and_unrelated_reviewer_cannot_review_the_request(string $module, string $model, string $key, string $prefix): void
    {
        [, $owner, $request] = $this->fixture($model, $prefix);
        $unrelated = $this->user($prefix.'.approve');
        $this->employee($unrelated);
        $approval = in_array($module, ['Cto', 'Overtime'], true) ? ['approved_minutes' => 30] : [];

        foreach ([$owner, $unrelated] as $actor) {
            $this->assertUnauthorizedRequest(self::requestClass('Approve', $module), $approval, $actor, [$key => $request]);
            $this->assertUnauthorizedRequest(self::requestClass('Reject', $module), [], $actor, [$key => $request]);
        }
    }

    #[DataProvider('workflows')]
    public function test_workflow_actions_require_the_exact_typed_route_parameter(string $module, string $model, string $key, string $prefix): void
    {
        [$supervisor, $owner, $request] = $this->fixture($model, $prefix);
        $approval = in_array($module, ['Cto', 'Overtime'], true) ? ['approved_minutes' => 30] : [];

        foreach ([[], [$key => $request->id], ['record' => $request], [$key => new Announcement]] as $bindings) {
            $this->assertUnauthorizedRequest(self::requestClass('Approve', $module), $approval, $supervisor, $bindings);
            $this->assertUnauthorizedRequest(self::requestClass('Reject', $module), [], $supervisor, $bindings);
            $this->assertUnauthorizedRequest(self::requestClass('Cancel', $module), [], $owner, $bindings);
        }
    }

    #[DataProvider('workflows')]
    public function test_invalid_remarks_and_reasons_fail_validation(string $module, string $model, string $key, string $prefix): void
    {
        [$supervisor, $owner, $request] = $this->fixture($model, $prefix);

        $this->assertInvalidRequest(self::requestClass('Reject', $module), ['remarks' => ['bad']], $supervisor, [$key => $request], ['remarks']);
        $this->assertInvalidRequest(self::requestClass('Cancel', $module), ['reason' => ['bad']], $owner, [$key => $request], ['reason']);
    }

    #[DataProvider('invalidBusinessIntervals')]
    public function test_malformed_reference_dates_return_validation_errors(string $module, string $prefix, mixed $start): void
    {
        $caller = $this->user($prefix.'.create');

        $this->assertInvalidRequest(self::requestClass('Store', $module), [
            'start_at' => $start,
            'end_at' => '2026-10-03 17:00:00',
            'destination' => 'Head office',
            'purpose' => 'Official meeting.',
        ], $caller, [], ['start_at']);
    }

    #[DataProvider('announcementSchedules')]
    public function test_announcement_schedule_dates_are_validated_before_comparison(string $operation): void
    {
        $caller = $this->user('announcements.manage');
        $announcement = Announcement::create(['title' => 'Schedule', 'content' => 'Office advisory.']);
        $class = 'App\\Http\\Requests\\'.$operation.'AnnouncementRequest';

        $this->assertInvalidRequest($class, [
            'scheduled_at' => ['bad'],
            'expires_at' => '2099-12-31 17:00:00',
        ], $caller, ['announcement' => $announcement], ['scheduled_at']);
    }

    public function test_cto_final_authorization_cases_rollback_without_leaving_records(): void
    {
        $tables = ['users', 'employees', 'permissions', 'permission_user', 'cto_requests', 'approval_actions'];
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        $initialLevel = DB::transactionLevel();
        DB::beginTransaction();

        try {
            [$supervisor, $employeeUser, $request] = $this->fixture('CtoRequest', 'cto');
            $bindings = ['ctoRequest' => $request];
            $ids = [$supervisor->id, $employeeUser->id, $request->employee_id, $request->id];

            $this->assertSame(['approved_minutes' => 30], $this->validateRequest('App\\Http\\Requests\\ApproveCtoRequest', ['approved_minutes' => 30], $supervisor, $bindings));
            $this->assertSame([], $this->validateRequest('App\\Http\\Requests\\RejectCtoRequest', [], $supervisor, $bindings));
            $this->assertSame([], $this->validateRequest('App\\Http\\Requests\\CancelCtoRequest', [], $employeeUser, $bindings));
            $this->assertUnauthorizedRequest('App\\Http\\Requests\\ApproveCtoRequest', ['approved_minutes' => 30], $employeeUser, $bindings);
        } finally {
            DB::rollBack();
        }

        $this->assertSame($initialLevel, DB::transactionLevel());

        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' retained temporary records.');
        }

        $this->assertFalse(DB::table('users')->whereIn('id', array_slice($ids, 0, 2))->exists());
        $this->assertFalse(DB::table('employees')->where('id', $ids[2])->exists());
        $this->assertFalse(DB::table('cto_requests')->where('id', $ids[3])->exists());
    }

    public static function workflows(): array
    {
        return [
            'Cto workflow' => ['Cto', 'CtoRequest', 'ctoRequest', 'cto'],
            'Overtime workflow' => ['Overtime', 'OvertimeRequest', 'overtimeRequest', 'overtime'],
            'OfficialBusiness workflow' => ['OfficialBusiness', 'OfficialBusinessRequest', 'officialBusinessRequest', 'official-business'],
            'TravelOrder workflow' => ['TravelOrder', 'TravelOrderRequest', 'travelOrderRequest', 'travel-orders'],
        ];
    }

    public static function invalidBusinessIntervals(): array
    {
        $cases = [];

        foreach (['OfficialBusiness' => 'official-business', 'TravelOrder' => 'travel-orders'] as $module => $prefix) {
            foreach (['array' => ['bad'], 'boolean' => true, 'invalid date' => 'bad'] as $label => $value) {
                $cases[$module.' '.$label.' reference date'] = [$module, $prefix, $value];
            }
        }

        return $cases;
    }

    public static function announcementSchedules(): array
    {
        return ['Announcements schedule' => ['Schedule'], 'Announcements reschedule' => ['Reschedule']];
    }

    private static function requestClass(string $operation, string $module): string
    {
        return 'App\\Http\\Requests\\'.$operation.$module.'Request';
    }

    private function fixture(string $model, string $prefix): array
    {
        $supervisor = $this->user($prefix.'.approve');
        $supervisorEmployee = $this->employee($supervisor);
        $owner = $this->user($prefix.'.create', $prefix.'.approve');
        $employee = $this->employee($owner, ['supervisor_employee_id' => $supervisorEmployee->id]);
        $class = 'App\\Models\\'.$model;
        $attributes = [
            'employee_id' => $employee->id,
            'start_at' => '2026-10-03 08:00:00',
            'end_at' => '2026-10-03 09:00:00',
            'created_by_user_id' => $owner->id,
            'status' => 'pending',
        ];

        if (in_array($model, ['CtoRequest', 'OvertimeRequest'], true)) {
            $attributes += ['requested_minutes' => 60, 'reason' => 'Appointment.'];
        } else {
            $attributes += ['destination' => 'Head office', 'purpose' => 'Official meeting.'];
        }

        return [$supervisor, $owner, $class::create($attributes)];
    }
}
