<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $name = config('seeding.admin.name');
        $email = config('seeding.admin.email');
        $password = config('seeding.admin.password');

        if (! $name || ! $email || ! $password) {
            throw new RuntimeException(
                'ADMIN_NAME, ADMIN_EMAIL, and ADMIN_PASSWORD must be configured.'
            );
        }

        $profile = $this->employeeConfiguration();

        DB::transaction(function () use ($name, $email, $password, $profile): void {
            $superAdminRole = Role::query()->where('code', 'super_admin')->firstOrFail();
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            $numberOwner = Employee::query()
                ->where('employee_number', $profile['employee_number'])
                ->lockForUpdate()->first();

            if ($numberOwner !== null && ($user === null || $numberOwner->user_id !== $user->id)) {
                throw new RuntimeException('ADMIN_EMPLOYEE_NUMBER is already used by another or unlinked employee profile.');
            }

            $employee = $user?->employee()->lockForUpdate()->first();

            if ($employee !== null && ! $employee->is_active) {
                throw new RuntimeException('The existing administrator employee profile is inactive. Reactivation must be handled explicitly.');
            }

            $user ??= new User(['email' => $email, 'uuid' => (string) Str::uuid()]);
            $user->fill([
                'name' => $name,
                'password' => Hash::make($password),
                'is_active' => true,
            ])->save();

            $user->roles()->syncWithoutDetaching([$superAdminRole->id]);

            $employee ??= new Employee(['user_id' => $user->id, 'is_active' => true]);
            $employee->fill($profile)->save();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeConfiguration(): array
    {
        $profile = [];

        foreach (['employee_number', 'first_name', 'middle_name', 'last_name', 'hire_date', 'employment_type'] as $field) {
            $value = config('seeding.admin.'.$field);
            $profile[$field] = is_string($value) ? trim($value) : $value;
        }

        if ($profile['middle_name'] === '') {
            $profile['middle_name'] = null;
        }

        $validator = Validator::make($profile, [
            'employee_number' => ['required', 'string', 'max:50'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'hire_date' => ['required', 'date_format:Y-m-d'],
            'employment_type' => ['required', 'string', 'max:50'],
        ], [], [
            'employee_number' => 'ADMIN_EMPLOYEE_NUMBER',
            'first_name' => 'ADMIN_FIRST_NAME',
            'middle_name' => 'ADMIN_MIDDLE_NAME',
            'last_name' => 'ADMIN_LAST_NAME',
            'hire_date' => 'ADMIN_HIRE_DATE',
            'employment_type' => 'ADMIN_EMPLOYMENT_TYPE',
        ]);

        if ($validator->fails()) {
            throw new RuntimeException('Administrator employee profile configuration is invalid: '.$validator->errors()->first());
        }

        return $validator->validated();
    }
}
