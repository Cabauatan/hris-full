<?php

namespace Tests\Feature;

use App\Models\EmployeeCompensation;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeScheduleAssignment;
use App\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class PersistedDateUpdateTest extends FormRequestTestCase
{
    #[DataProvider('datePairs')]
    public function test_returns_422_without_changing_the_record_when_a_later_start_would_invalidate_an_omitted_persisted_end(string $module, string $startField, string $endField): void
    {
        [$record, $endpoint, $payload] = $this->arrange($module, $endField);
        $before = $record->fresh()->getRawOriginal();
        $payload[$startField] = '2026-07-01';

        $this->patchJson($endpoint, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($endField);

        $this->assertSame($before, $record->fresh()->getRawOriginal());
    }

    #[DataProvider('datePairs')]
    public function test_allows_explicit_null_to_clear_the_persisted_end_when_moving_the_start_later(string $module, string $startField, string $endField): void
    {
        [$record, $endpoint, $payload] = $this->arrange($module, $endField);
        $payload[$startField] = '2026-07-01';
        $payload[$endField] = null;

        $this->patchJson($endpoint, $payload)
            ->assertOk()
            ->assertJsonPath('data.'.$startField, '2026-07-01')
            ->assertJsonPath('data.'.$endField, null);

        $record->refresh();
        $this->assertSame('2026-07-01', $record->getAttribute($startField)->toDateString());
        $this->assertNull($record->getAttribute($endField));
    }

    #[DataProvider('datePairs')]
    public function test_allows_a_valid_start_update_and_retains_the_omitted_persisted_end(string $module, string $startField, string $endField): void
    {
        [$record, $endpoint, $payload] = $this->arrange($module, $endField);
        $payload[$startField] = '2026-02-01';

        $this->patchJson($endpoint, $payload)
            ->assertOk()
            ->assertJsonPath('data.'.$startField, '2026-02-01')
            ->assertJsonPath('data.'.$endField, '2026-06-30');

        $record->refresh();
        $this->assertSame('2026-02-01', $record->getAttribute($startField)->toDateString());
        $this->assertSame('2026-06-30', $record->getAttribute($endField)->toDateString());
    }

    #[DataProvider('datePairs')]
    public function test_allows_a_start_equal_to_the_omitted_persisted_end(string $module, string $startField, string $endField): void
    {
        [$record, $endpoint, $payload] = $this->arrange($module, $endField);
        $payload[$startField] = '2026-06-30';

        $this->patchJson($endpoint, $payload)->assertOk();

        $record->refresh();
        $this->assertSame('2026-06-30', $record->getAttribute($startField)->toDateString());
        $this->assertSame('2026-06-30', $record->getAttribute($endField)->toDateString());
    }

    #[DataProvider('datePairs')]
    public function test_allows_a_later_start_when_the_omitted_persisted_end_is_already_null(string $module, string $startField, string $endField): void
    {
        [$record, $endpoint, $payload] = $this->arrange($module, $endField);
        $record->update([$endField => null]);
        $payload[$startField] = '2026-07-01';

        $this->patchJson($endpoint, $payload)->assertOk();

        $record->refresh();
        $this->assertSame('2026-07-01', $record->getAttribute($startField)->toDateString());
        $this->assertNull($record->getAttribute($endField));
    }

    public static function datePairs(): array
    {
        return [
            'employee regularization' => ['Employee', 'hire_date', 'regularization_date'],
            'employee separation' => ['Employee', 'hire_date', 'separation_date'],
            'employment history' => ['EmployeeEmploymentHistory', 'effective_from', 'effective_to'],
            'compensation' => ['EmployeeCompensation', 'effective_from', 'effective_to'],
            'schedule assignment' => ['EmployeeScheduleAssignment', 'effective_from', 'effective_to'],
        ];
    }

    /**
     * @return array{Model, string, array<string, mixed>}
     */
    private function arrange(string $module, string $endField): array
    {
        $permission = match ($module) {
            'Employee' => 'employees.update',
            'EmployeeEmploymentHistory' => 'employees.manage-employment',
            'EmployeeCompensation' => 'employees.manage-compensation',
            'EmployeeScheduleAssignment' => 'schedules.assign',
        };
        $this->actingAs($this->user($permission));
        $employee = $this->employee();

        if ($module === 'Employee') {
            $employee->update([$endField => '2026-06-30']);

            return [
                $employee,
                '/api/v1/employees/'.$employee->id,
                [
                    'employee_number' => $employee->employee_number,
                    'first_name' => $employee->first_name,
                    'last_name' => $employee->last_name,
                    'hire_date' => '2026-01-01',
                    'employment_type' => 'regular',
                ],
            ];
        }

        $attributes = match ($module) {
            'EmployeeEmploymentHistory' => ['employment_type' => 'regular', 'employment_status' => 'active', 'change_type' => 'promotion'],
            'EmployeeCompensation' => ['basic_salary' => '25000.00', 'pay_frequency' => 'monthly', 'currency' => 'PHP'],
            'EmployeeScheduleAssignment' => [
                'employee_id' => $employee->id,
                'work_schedule_id' => WorkSchedule::query()->create(['code' => 'DATES-'.$employee->id, 'name' => 'Date Validation Schedule', 'is_active' => true])->id,
            ],
        };
        $class = match ($module) {
            'EmployeeEmploymentHistory' => EmployeeEmploymentHistory::class,
            'EmployeeCompensation' => EmployeeCompensation::class,
            'EmployeeScheduleAssignment' => EmployeeScheduleAssignment::class,
        };
        $collection = match ($module) {
            'EmployeeEmploymentHistory' => 'employee-employment-histories',
            'EmployeeCompensation' => 'employee-compensations',
            'EmployeeScheduleAssignment' => 'employee-schedule-assignments',
        };
        $record = $class::query()->create($attributes + [
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-06-30',
        ]);

        return [$record, '/api/v1/'.$collection.'/'.$record->id, $attributes + ['effective_from' => '2026-01-01']];
    }
}
