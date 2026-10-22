<?php

namespace Tests\Feature;

use App\Models\CompanyProfile;
use App\Models\Department;
use App\Models\Division;
use App\Models\Employee;
use App\Models\EmployeeEmploymentHistory;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\CompanyProfileSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\FormRequestTestCase;

class SeederConfigurationTest extends FormRequestTestCase
{
    public function test_admin_seeding_uses_configuration_and_preserves_identity_and_other_roles_when_repeated(): void
    {
        config(['seeding' => $this->seedingConfiguration()]);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(AdminUserSeeder::class);
        $administrator = User::query()->where('email', 'seed-admin@example.test')->firstOrFail();
        $id = $administrator->id;
        $uuid = $administrator->uuid;
        $employee = $administrator->employee()->firstOrFail();
        $employeeId = $employee->id;
        $this->assertDatabaseHas('employees', [
            'user_id' => $id,
            'employee_number' => 'SEED-0001',
            'first_name' => 'Config',
            'middle_name' => 'Seed',
            'last_name' => 'Administrator',
            'hire_date' => '2026-01-01 00:00:00',
            'employment_type' => 'regular',
            'employment_status' => 'active',
            'is_active' => true,
        ]);
        $employee->update(['mobile_number' => 'fixture-mobile', 'employment_status' => 'probationary', 'city' => 'Existing City']);
        $history = EmployeeEmploymentHistory::query()->create([
            'employee_id' => $employeeId,
            'employment_type' => 'regular',
            'employment_status' => 'active',
            'effective_from' => '2026-01-01',
            'change_type' => 'initial',
        ]);
        $otherRole = Role::query()->where('code', 'employee')->firstOrFail();
        $administrator->roles()->syncWithoutDetaching([$otherRole->id]);
        config([
            'seeding.admin.name' => 'Updated Config Administrator',
            'seeding.admin.password' => 'updated-seed-password',
            'seeding.admin.employee_number' => 'SEED-0002',
            'seeding.admin.first_name' => 'Updated',
            'seeding.admin.middle_name' => null,
            'seeding.admin.last_name' => 'Configured',
            'seeding.admin.hire_date' => '2026-02-01',
            'seeding.admin.employment_type' => 'contractual',
        ]);

        $this->seed(AdminUserSeeder::class);

        $administrator->refresh();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($id, $administrator->id);
        $this->assertSame($uuid, $administrator->uuid);
        $this->assertSame('Updated Config Administrator', $administrator->name);
        $this->assertTrue($administrator->is_active);
        $this->assertTrue(Hash::check('updated-seed-password', $administrator->password));
        $this->assertSame(['employee', 'super_admin'], $administrator->roles()->orderBy('code')->pluck('code')->all());
        $this->assertTrue($administrator->hasPermission('settings.manage'));
        $employee->refresh();
        $this->assertDatabaseCount('employees', 1);
        $this->assertSame($employeeId, $employee->id);
        $this->assertSame($id, $employee->user_id);
        $this->assertSame('SEED-0002', $employee->employee_number);
        $this->assertSame('Updated', $employee->first_name);
        $this->assertNull($employee->middle_name);
        $this->assertSame('Configured', $employee->last_name);
        $this->assertSame('2026-02-01', $employee->hire_date->toDateString());
        $this->assertSame('contractual', $employee->employment_type);
        $this->assertSame('probationary', $employee->employment_status);
        $this->assertSame('fixture-mobile', $employee->mobile_number);
        $this->assertSame('Existing City', $employee->city);
        $this->assertTrue($employee->is_active);
        $this->assertSame([$history->id], $employee->employmentHistories()->pluck('id')->all());
    }

    public function test_company_seeding_uses_configured_profile_fields_and_updates_the_existing_singleton(): void
    {
        $configuration = $this->seedingConfiguration();
        config(['seeding' => $configuration]);

        $this->seed(CompanyProfileSeeder::class);

        $this->assertDatabaseHas('company_profiles', ['id' => 1] + $configuration['company']);
        config(['seeding.company.name' => 'Updated Config Company', 'seeding.company.email' => null]);

        $this->seed(CompanyProfileSeeder::class);

        $this->assertDatabaseCount('company_profiles', 1);
        $this->assertDatabaseHas('company_profiles', ['id' => 1, 'name' => 'Updated Config Company', 'email' => null, 'tax_identification_number' => 'TEST-TIN-001']);
    }

    public function test_full_database_seeding_is_repeatable_without_duplicate_records_or_permission_links(): void
    {
        config(['seeding' => $this->seedingConfiguration()]);
        $this->seed(DatabaseSeeder::class);
        $administrator = User::query()->where('email', 'seed-admin@example.test')->firstOrFail();
        $identity = [$administrator->id, $administrator->uuid];
        $roles = Role::query()->orderBy('code')->pluck('id', 'code')->all();
        $permissions = Permission::query()->orderBy('code')->pluck('id', 'code')->all();
        $leaveTypes = LeaveType::query()->orderBy('code')->pluck('id', 'code')->all();
        $divisions = Division::query()->orderBy('code')->pluck('id', 'code')->all();
        $departments = Department::query()->orderBy('code')->pluck('id', 'code')->all();
        $positions = Position::query()->orderBy('code')->pluck('id', 'code')->all();
        $shifts = Shift::query()->orderBy('code')->pluck('id', 'code')->all();
        $schedules = WorkSchedule::query()->orderBy('code')->pluck('id', 'code')->all();
        $scheduleDays = WorkScheduleDay::query()->orderBy('id')->pluck('id')->all();
        $holidays = Holiday::query()->orderBy('id')->pluck('id')->all();

        $this->seed(DatabaseSeeder::class);

        $administrator->refresh();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('company_profiles', 1);
        $this->assertDatabaseCount('roles', 7);
        $this->assertDatabaseCount('leave_types', 4);
        $this->assertDatabaseCount('divisions', 3);
        $this->assertDatabaseCount('departments', 8);
        $this->assertDatabaseCount('positions', 24);
        $this->assertDatabaseCount('shifts', 5);
        $this->assertDatabaseCount('work_schedules', 6);
        $this->assertDatabaseCount('work_schedule_days', 42);
        $this->assertDatabaseCount('employee_schedule_assignments', 0);
        $this->assertDatabaseCount('holidays', 40);
        $this->assertSame($identity, [$administrator->id, $administrator->uuid]);
        $this->assertSame($roles, Role::query()->orderBy('code')->pluck('id', 'code')->all());
        $this->assertSame($permissions, Permission::query()->orderBy('code')->pluck('id', 'code')->all());
        $this->assertSame($leaveTypes, LeaveType::query()->orderBy('code')->pluck('id', 'code')->all());
        $this->assertSame($divisions, Division::query()->orderBy('code')->pluck('id', 'code')->all());
        $this->assertSame($departments, Department::query()->orderBy('code')->pluck('id', 'code')->all());
        $this->assertSame($positions, Position::query()->orderBy('code')->pluck('id', 'code')->all());
        $this->assertSame($shifts, Shift::query()->orderBy('code')->pluck('id', 'code')->all());
        $this->assertSame($schedules, WorkSchedule::query()->orderBy('code')->pluck('id', 'code')->all());
        $this->assertSame($scheduleDays, WorkScheduleDay::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame($holidays, Holiday::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame(['super_admin'], $administrator->roles()->pluck('code')->all());
        $this->assertSame(array_keys($permissions), Role::query()->where('code', 'super_admin')->firstOrFail()->permissions()->orderBy('code')->pluck('code')->all());
        $this->assertTrue($administrator->hasPermission('organization.manage'));
        $this->assertTrue($administrator->hasPermission('payroll.finalize'));
        $this->assertTrue($administrator->hasPermission('profile-changes.approve'));
    }

    #[DataProvider('requiredAdminConfiguration')]
    public function test_admin_seeding_fails_without_required_configuration_and_does_not_create_a_user(string $key): void
    {
        config(['seeding' => $this->seedingConfiguration(), 'seeding.admin.'.$key => null]);
        $this->seed(RolePermissionSeeder::class);

        try {
            $this->seed(AdminUserSeeder::class);
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ADMIN_NAME, ADMIN_EMAIL, and ADMIN_PASSWORD', $exception->getMessage());
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('employees', 0);

            return;
        }

        $this->fail('Expected administrator seeding to refuse missing configuration.');
    }

    #[DataProvider('invalidAdminProfileConfiguration')]
    public function test_admin_seeding_refuses_invalid_profile_configuration_without_creating_records(string $key, mixed $value, string $environmentName): void
    {
        config(['seeding' => $this->seedingConfiguration(), 'seeding.admin.'.$key => $value]);
        $this->seed(RolePermissionSeeder::class);

        try {
            $this->seed(AdminUserSeeder::class);
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($environmentName, $exception->getMessage());
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('employees', 0);
            $this->assertDatabaseCount('role_user', 0);

            return;
        }

        $this->fail('Expected administrator seeding to refuse invalid employee profile configuration.');
    }

    #[DataProvider('occupiedAdminEmployeeNumbers')]
    public function test_admin_seeding_refuses_employee_number_collisions_without_modifying_any_records(bool $hasAdministrator, bool $hasOwner): void
    {
        config(['seeding' => $this->seedingConfiguration()]);
        $this->seed(RolePermissionSeeder::class);
        $administrator = $hasAdministrator ? $this->user() : null;
        if ($administrator !== null) {
            $administrator->update(['email' => 'seed-admin@example.test', 'name' => 'Original Administrator']);
            $this->employee($administrator, ['employee_number' => 'OLD-ADMIN-NUMBER']);
        }
        $owner = $hasOwner ? $this->user() : null;
        $this->employee($owner, ['employee_number' => 'SEED-0001', 'first_name' => 'Unrelated']);
        $usersBefore = User::query()->orderBy('id')->get()->map(fn (User $user): array => $user->getAttributes())->all();
        $employeesBefore = Employee::query()->orderBy('id')->get()->map(fn (Employee $employee): array => $employee->getAttributes())->all();

        try {
            $this->seed(AdminUserSeeder::class);
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ADMIN_EMPLOYEE_NUMBER', $exception->getMessage());
            $this->assertSame($usersBefore, User::query()->orderBy('id')->get()->map(fn (User $user): array => $user->getAttributes())->all());
            $this->assertSame($employeesBefore, Employee::query()->orderBy('id')->get()->map(fn (Employee $employee): array => $employee->getAttributes())->all());
            $this->assertDatabaseCount('role_user', 0);

            return;
        }

        $this->fail('Expected administrator seeding to refuse a number belonging to another or unlinked profile.');
    }

    public function test_admin_seeding_refuses_an_inactive_owned_profile_without_reactivating_or_changing_credentials(): void
    {
        config(['seeding' => $this->seedingConfiguration()]);
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->user();
        $administrator->update(['email' => 'seed-admin@example.test', 'name' => 'Original Administrator']);
        $employee = $this->employee($administrator, ['employee_number' => 'SEED-0001', 'is_active' => false]);
        $originalUser = $administrator->refresh()->getAttributes();
        $originalEmployee = $employee->refresh()->getAttributes();

        try {
            $this->seed(AdminUserSeeder::class);
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('inactive', $exception->getMessage());
            $this->assertSame($originalUser, $administrator->refresh()->getAttributes());
            $this->assertSame($originalEmployee, $employee->refresh()->getAttributes());
            $this->assertDatabaseCount('role_user', 0);

            return;
        }

        $this->fail('Expected administrator seeding to protect the inactive profile.');
    }

    public function test_admin_seeding_rolls_back_user_and_role_changes_when_profile_persistence_fails(): void
    {
        config(['seeding' => $this->seedingConfiguration()]);
        $this->seed(RolePermissionSeeder::class);
        $originalDispatcher = Employee::getEventDispatcher();
        Employee::setEventDispatcher(clone $originalDispatcher);
        Employee::saving(static function (Employee $employee): void {
            throw new RuntimeException('Fixture employee persistence failure.');
        });

        try {
            $this->seed(AdminUserSeeder::class);
        } catch (RuntimeException $exception) {
            $this->assertSame('Fixture employee persistence failure.', $exception->getMessage());
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('employees', 0);
            $this->assertDatabaseCount('role_user', 0);

            return;
        } finally {
            Employee::setEventDispatcher($originalDispatcher);
        }

        $this->fail('Expected employee persistence to fail.');
    }

    public function test_company_seeding_fails_without_its_configured_name_and_does_not_create_a_profile(): void
    {
        config(['seeding' => $this->seedingConfiguration(), 'seeding.company.name' => null]);

        try {
            $this->seed(CompanyProfileSeeder::class);
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('COMPANY_NAME', $exception->getMessage());
            $this->assertDatabaseCount('company_profiles', 0);

            return;
        }

        $this->fail('Expected company seeding to refuse a missing configured name.');
    }

    public function test_full_seeding_uses_values_loaded_from_an_isolated_configuration_cache(): void
    {
        $cacheRelativePath = 'storage/framework/testing/seeding-config-'.Str::uuid().'.php';
        $cachePath = base_path($cacheRelativePath);
        $previousEnvironment = $_ENV['APP_CONFIG_CACHE'] ?? null;
        $previousServer = $_SERVER['APP_CONFIG_CACHE'] ?? null;
        $fixtureConfig = ['app' => ['env' => 'testing', 'timezone' => config('app.timezone')], 'seeding' => $this->seedingConfiguration()];
        if (! is_dir(dirname($cachePath))) {
            mkdir(dirname($cachePath), 0777, true);
        }
        file_put_contents($cachePath, '<?php return '.var_export($fixtureConfig, true).';');

        try {
            $_ENV['APP_CONFIG_CACHE'] = $cacheRelativePath;
            $_SERVER['APP_CONFIG_CACHE'] = $cacheRelativePath;
            $cachedApplication = new Application(base_path());
            $this->assertSame($cachePath, $cachedApplication->getCachedConfigPath());
            (new LoadConfiguration)->bootstrap($cachedApplication);
            $this->assertTrue($cachedApplication->configurationIsCached());
            $this->assertTrue($cachedApplication->make('config_loaded_from_cache'));
            $cachedSeeding = $cachedApplication->make('config')->get('seeding');
            Container::setInstance($this->app);
            config(['seeding' => $cachedSeeding]);

            $this->seed(DatabaseSeeder::class);

            $administrator = User::query()->where('email', 'seed-admin@example.test')->firstOrFail();
            $this->assertSame('Config Administrator', $administrator->name);
            $this->assertTrue(Hash::check('fixture-seed-password', $administrator->password));
            $this->assertTrue($administrator->hasPermission('settings.manage'));
            $this->assertDatabaseHas('company_profiles', ['id' => 1, 'name' => 'Config Company', 'tax_identification_number' => 'TEST-TIN-001']);
        } finally {
            Container::setInstance($this->app);
            if ($previousEnvironment === null) {
                unset($_ENV['APP_CONFIG_CACHE']);
            } else {
                $_ENV['APP_CONFIG_CACHE'] = $previousEnvironment;
            }
            if ($previousServer === null) {
                unset($_SERVER['APP_CONFIG_CACHE']);
            } else {
                $_SERVER['APP_CONFIG_CACHE'] = $previousServer;
            }
            if (is_file($cachePath)) {
                unlink($cachePath);
            }
        }

        $this->assertFileDoesNotExist($cachePath);
        $this->assertSame(1, CompanyProfile::query()->count());
    }

    public static function requiredAdminConfiguration(): array
    {
        return ['name' => ['name'], 'email' => ['email'], 'password' => ['password']];
    }

    public static function invalidAdminProfileConfiguration(): array
    {
        return [
            'missing employee number' => ['employee_number', null, 'ADMIN_EMPLOYEE_NUMBER'],
            'blank employee number' => ['employee_number', '   ', 'ADMIN_EMPLOYEE_NUMBER'],
            'employee number too long' => ['employee_number', str_repeat('E', 51), 'ADMIN_EMPLOYEE_NUMBER'],
            'missing first name' => ['first_name', null, 'ADMIN_FIRST_NAME'],
            'first name too long' => ['first_name', str_repeat('F', 101), 'ADMIN_FIRST_NAME'],
            'middle name too long' => ['middle_name', str_repeat('M', 101), 'ADMIN_MIDDLE_NAME'],
            'missing last name' => ['last_name', null, 'ADMIN_LAST_NAME'],
            'last name too long' => ['last_name', str_repeat('L', 101), 'ADMIN_LAST_NAME'],
            'missing hire date' => ['hire_date', null, 'ADMIN_HIRE_DATE'],
            'relative hire date' => ['hire_date', 'today', 'ADMIN_HIRE_DATE'],
            'impossible hire date' => ['hire_date', '2026-02-30', 'ADMIN_HIRE_DATE'],
            'noncanonical hire date' => ['hire_date', '2026-1-1', 'ADMIN_HIRE_DATE'],
            'missing employment type' => ['employment_type', null, 'ADMIN_EMPLOYMENT_TYPE'],
            'employment type too long' => ['employment_type', str_repeat('R', 51), 'ADMIN_EMPLOYMENT_TYPE'],
        ];
    }

    public static function occupiedAdminEmployeeNumbers(): array
    {
        return [
            'new administrator and owned number' => [false, true],
            'new administrator and unlinked number' => [false, false],
            'existing administrator and owned number' => [true, true],
            'existing administrator and unlinked number' => [true, false],
        ];
    }

    private function seedingConfiguration(): array
    {
        return [
            'admin' => [
                'name' => 'Config Administrator',
                'email' => 'seed-admin@example.test',
                'password' => 'fixture-seed-password',
                'employee_number' => 'SEED-0001',
                'first_name' => 'Config',
                'middle_name' => 'Seed',
                'last_name' => 'Administrator',
                'hire_date' => '2026-01-01',
                'employment_type' => 'regular',
            ],
            'company' => [
                'name' => 'Config Company',
                'legal_name' => 'Config Company Incorporated',
                'registration_number' => 'TEST-REG-001',
                'tax_identification_number' => 'TEST-TIN-001',
                'email' => 'seed-company@example.test',
                'phone' => '+63 555 0000',
                'website' => 'https://company.example.test',
                'address_line_1' => '1 Fixture Avenue',
                'address_line_2' => 'Suite 2',
                'city' => 'Test City',
                'province' => 'Test Province',
                'postal_code' => '1000',
                'country' => 'Philippines',
            ],
        ];
    }
}
