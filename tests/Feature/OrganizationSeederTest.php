<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Division;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\JobOpening;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\DivisionSeeder;
use Database\Seeders\PositionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Tests\FormRequestTestCase;

class OrganizationSeederTest extends FormRequestTestCase
{
    public function test_standalone_position_seeding_creates_common_hierarchy_without_staff_recruitment_or_payroll(): void
    {
        $this->freezeTime();

        $this->seed(PositionSeeder::class);

        $this->assertDatabaseCount('divisions', 3);
        $this->assertDatabaseCount('departments', 8);
        $this->assertDatabaseCount('positions', 24);
        $this->assertSame(3, Division::query()->where('is_active', true)->count());
        $this->assertSame(8, Department::query()->where('is_active', true)->count());
        $this->assertSame(24, Position::query()->where('is_active', true)->count());

        $representativePositions = [
            'STD-HR-OFFICER' => ['STD-HR', 'STD-CORPORATE'],
            'STD-ACC-ACCOUNTANT' => ['STD-ACCOUNTING', 'STD-CORPORATE'],
            'STD-IT-SUPPORT' => ['STD-IT', 'STD-CORPORATE'],
            'STD-LOG-DRIVER' => ['STD-LOGISTICS', 'STD-OPERATIONS'],
            'STD-CS-REPRESENTATIVE' => ['STD-CUSTOMER-SERVICE', 'STD-OPERATIONS'],
            'STD-SALES-ASSOCIATE' => ['STD-SALES', 'STD-COMMERCIAL'],
            'STD-MKT-DIGITAL' => ['STD-MARKETING', 'STD-COMMERCIAL'],
        ];
        foreach ($representativePositions as $code => [$departmentCode, $divisionCode]) {
            $position = Position::query()->with('department.division')->where('code', $code)->firstOrFail();
            $this->assertSame($departmentCode, $position->department?->code);
            $this->assertSame($divisionCode, $position->department?->division?->code);
        }
        foreach (Department::query()->withCount('positions')->get() as $department) {
            $this->assertSame(3, $department->positions_count);
        }

        foreach (['employees', 'users', 'applicants', 'job_openings', 'job_applications', 'employee_compensations', 'payroll_entries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_standalone_division_seeding_creates_only_divisions(): void
    {
        $this->freezeTime();

        $this->seed(DivisionSeeder::class);

        $this->assertDatabaseCount('divisions', 3);
        $this->assertDatabaseHas('divisions', ['code' => 'STD-CORPORATE', 'name' => 'Corporate Services']);
        $this->assertDatabaseHas('divisions', ['code' => 'STD-OPERATIONS', 'name' => 'Operations']);
        $this->assertDatabaseHas('divisions', ['code' => 'STD-COMMERCIAL', 'name' => 'Sales and Marketing']);
        $this->assertDatabaseCount('departments', 0);
        $this->assertDatabaseCount('positions', 0);
    }

    public function test_standalone_department_seeding_creates_parent_divisions_without_positions(): void
    {
        $this->freezeTime();

        $this->seed(DepartmentSeeder::class);

        $this->assertDatabaseCount('divisions', 3);
        $this->assertDatabaseCount('departments', 8);
        $this->assertDatabaseCount('positions', 0);
        $this->assertSame('STD-CORPORATE', Department::query()->where('code', 'STD-ADMIN')->firstOrFail()->division?->code);
        $this->assertSame('STD-OPERATIONS', Department::query()->where('code', 'STD-LOGISTICS')->firstOrFail()->division?->code);
        $this->assertSame('STD-COMMERCIAL', Department::query()->where('code', 'STD-SALES')->firstOrFail()->division?->code);
    }

    public function test_repeated_seeding_preserves_customizations_parent_links_unrelated_records_and_employee_positions(): void
    {
        $this->travelTo('2026-10-05 08:00:00');
        $this->seed(PositionSeeder::class);
        $customDivision = Division::query()->create([
            'code' => 'CUSTOM-DIVISION',
            'name' => 'Existing company division',
            'is_active' => false,
        ]);
        $customDepartment = Department::query()->create([
            'division_id' => $customDivision->id,
            'code' => 'CUSTOM-DEPARTMENT',
            'name' => 'Existing company department',
            'is_active' => false,
        ]);
        $customPosition = Position::query()->create([
            'department_id' => $customDepartment->id,
            'code' => 'CUSTOM-POSITION',
            'name' => 'Existing company position',
            'is_active' => false,
        ]);
        Division::query()->where('code', 'STD-CORPORATE')->firstOrFail()->update([
            'name' => 'HR renamed corporate division',
            'description' => 'Keep the existing company structure.',
            'is_active' => false,
        ]);
        Department::query()->where('code', 'STD-HR')->firstOrFail()->update([
            'division_id' => $customDivision->id,
            'name' => 'HR renamed personnel department',
            'description' => 'Keep the custom division assignment.',
            'is_active' => false,
        ]);
        $standardPosition = Position::query()->where('code', 'STD-HR-OFFICER')->firstOrFail();
        $standardPosition->update([
            'department_id' => $customDepartment->id,
            'name' => 'HR renamed personnel role',
            'description' => 'Keep the custom department assignment.',
            'is_active' => false,
        ]);
        Position::query()->where('code', 'STD-IT-SUPPORT')->firstOrFail()->update(['department_id' => null]);
        $account = $this->user();
        $employee = $this->employee($account, ['position_id' => $standardPosition->id]);
        $this->employee(attributes: ['position_id' => $customPosition->id]);
        EmployeeCompensation::query()->create([
            'employee_id' => $employee->id,
            'basic_salary' => '35000.00',
            'pay_frequency' => 'monthly',
            'effective_from' => '2026-10-01',
        ]);
        JobOpening::factory()->closed()->create();
        $before = $this->organizationRecords();
        $this->travelTo('2026-10-06 08:00:00');

        $this->seed(PositionSeeder::class);

        $this->assertSame($before, $this->organizationRecords());
    }

    public function test_existing_inactive_division_is_preserved_and_new_descendants_stay_inactive(): void
    {
        $this->travelTo('2026-10-05 08:00:00');
        $division = Division::query()->create([
            'code' => 'STD-CORPORATE',
            'name' => 'HR disabled corporate division',
            'is_active' => false,
        ]);
        $before = $division->refresh()->getAttributes();
        $this->travelTo('2026-10-06 08:00:00');

        $this->seed(PositionSeeder::class);

        $this->assertSame($before, $division->refresh()->getAttributes());
        $this->assertSame(4, $division->departments()->where('is_active', false)->count());
        $this->assertSame(12, Position::query()->whereHas('department', fn (Builder $departments): Builder => $departments->where('division_id', $division->id))->where('is_active', false)->count());
        $this->assertDatabaseHas('departments', ['code' => 'STD-SALES', 'is_active' => true]);
        $this->assertDatabaseHas('positions', ['code' => 'STD-SALES-ASSOCIATE', 'is_active' => true]);
    }

    public function test_existing_inactive_department_is_preserved_and_new_positions_stay_inactive(): void
    {
        $this->travelTo('2026-10-05 08:00:00');
        $department = Department::query()->create([
            'code' => 'STD-HR',
            'name' => 'HR disabled personnel department',
            'division_id' => null,
            'is_active' => false,
        ]);
        $before = $department->refresh()->getAttributes();
        $this->travelTo('2026-10-06 08:00:00');

        $this->seed(PositionSeeder::class);

        $this->assertSame($before, $department->refresh()->getAttributes());
        $this->assertSame(3, $department->positions()->where('is_active', false)->count());
        $this->assertDatabaseHas('positions', ['code' => 'STD-IT-SUPPORT', 'is_active' => true]);
    }

    public function test_active_reparented_department_under_actual_inactive_division_creates_inactive_positions(): void
    {
        $this->travelTo('2026-10-05 08:00:00');
        $division = Division::query()->create([
            'code' => 'CUSTOM-INACTIVE',
            'name' => 'Existing inactive division',
            'is_active' => false,
        ]);
        $department = Department::query()->create([
            'division_id' => $division->id,
            'code' => 'STD-HR',
            'name' => 'Reassigned personnel department',
            'is_active' => true,
        ]);
        $before = $department->refresh()->getAttributes();
        $this->travelTo('2026-10-06 08:00:00');

        $this->seed(PositionSeeder::class);

        $this->assertSame($before, $department->refresh()->getAttributes());
        $this->assertSame(3, $department->positions()->where('is_active', false)->count());
        $this->assertDatabaseHas('divisions', ['code' => 'STD-CORPORATE', 'is_active' => true]);
    }

    public function test_active_department_without_division_keeps_null_parent_and_creates_active_positions(): void
    {
        $this->travelTo('2026-10-05 08:00:00');
        $department = Department::query()->create([
            'division_id' => null,
            'code' => 'STD-HR',
            'name' => 'Independent personnel department',
            'is_active' => true,
        ]);
        $before = $department->refresh()->getAttributes();
        $this->travelTo('2026-10-06 08:00:00');

        $this->seed(PositionSeeder::class);

        $this->assertSame($before, $department->refresh()->getAttributes());
        $this->assertSame(3, $department->positions()->where('is_active', true)->count());
    }

    /** @return array<class-string<Model>, list<array<string, mixed>>> */
    private function organizationRecords(): array
    {
        $records = [];
        foreach ([Division::class, Department::class, Position::class, Employee::class, User::class, EmployeeCompensation::class, JobOpening::class] as $model) {
            $records[$model] = $model::query()->orderBy('id')->get()->map(
                static fn (Model $record): array => $record->getAttributes(),
            )->all();
        }

        return $records;
    }
}
