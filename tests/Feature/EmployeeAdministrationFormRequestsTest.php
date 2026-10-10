<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEmploymentHistory;
use App\Models\SystemSetting;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class EmployeeAdministrationFormRequestsTest extends FormRequestTestCase
{
    #[DataProvider('operations')]
    public function test_accepts_authorized_valid_input(string $module, string $operation): void
    {
        [$class, $payload, $caller, $bindings] = $this->arrange($module, $operation);

        $validated = $this->validateRequest($class, $payload, $caller, $bindings);

        $this->assertValidatedPayload($payload, $validated);
    }

    #[DataProvider('requiredFields')]
    public function test_rejects_missing_required_input(string $module, string $operation, string $field): void
    {
        [$class, $payload, $caller, $bindings] = $this->arrange($module, $operation);
        unset($payload[$field]);

        $this->assertInvalidRequest($class, $payload, $caller, $bindings, [$field]);
    }

    #[DataProvider('invalidFields')]
    public function test_rejects_invalid_input(string $module, string $operation, string $field, mixed $value): void
    {
        $this->travelTo(new DateTimeImmutable('2026-10-03 12:00:00'));
        [$class, $payload, $caller, $bindings] = $this->arrange($module, $operation);
        $payload[$field] = $value === '__oversized_file__'
            ? UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')
            : $value;

        $this->assertInvalidRequest($class, $payload, $caller, $bindings, [$field]);
    }

    #[DataProvider('invalidReferenceDates')]
    public function test_rejects_array_reference_dates_before_comparing_dependent_dates(string $module, string $operation, array $changes, string $field): void
    {
        [$class, $payload, $caller, $bindings] = $this->arrange($module, $operation);
        $payload = array_replace($payload, $changes);

        $this->assertInvalidRequest($class, $payload, $caller, $bindings, [$field]);
    }

    #[DataProvider('operations')]
    public function test_rejects_callers_without_the_operation_permission(string $module, string $operation): void
    {
        [$class, $payload, , $bindings] = $this->arrange($module, $operation);
        $caller = $this->user('employees.view');

        $this->assertUnauthorizedRequest($class, $payload, $caller, $bindings);
    }

    #[DataProvider('operations')]
    public function test_rejects_inactive_authorized_callers(string $module, string $operation): void
    {
        [$class, $payload, $caller, $bindings] = $this->arrange($module, $operation);
        $caller->update(['is_active' => false]);

        $this->assertUnauthorizedRequest($class, $payload, $caller, $bindings);
    }

    #[DataProvider('operations')]
    public function test_rejects_unauthenticated_callers(string $module, string $operation): void
    {
        [$class, $payload, , $bindings] = $this->arrange($module, $operation);

        $this->assertUnauthorizedRequest($class, $payload, null, $bindings);
    }

    #[DataProvider('invalidBindings')]
    public function test_rejects_missing_or_incorrect_bound_models(string $module, string $binding): void
    {
        [$class, $payload, $caller, $bindings] = $this->arrange($module, 'update');
        $key = self::configuration($module)['key'];
        $bindings = match ($binding) {
            'missing' => [],
            'scalar' => [$key => $bindings[$key]->getKey()],
            'wrong model' => [$key => new SystemSetting],
            'wrong key' => ['record' => $bindings[$key]],
        };

        $this->assertUnauthorizedRequest($class, $payload, $caller, $bindings);
    }

    #[DataProvider('operations')]
    public function test_excludes_client_supplied_identifiers_and_audit_metadata(string $module, string $operation): void
    {
        [$class, $payload, $caller, $bindings] = $this->arrange($module, $operation);
        $expected = $payload;
        $payload += [
            'id' => 987654321,
            'created_at' => '1990-01-01 00:00:00',
            'updated_at' => '1990-01-01 00:00:00',
            'created_by_user_id' => 987654321,
            'uploaded_by_user_id' => 987654321,
        ];

        if ($module === 'EmployeeDocument') {
            $payload += [
                'file_path' => '../../another-employees-document.pdf',
                'original_filename' => 'forged.pdf',
                'mime_type' => 'application/x-php',
                'file_size' => 1,
            ];
        }

        $validated = $this->validateRequest($class, $payload, $caller, $bindings);

        $this->assertValidatedPayload($expected, $validated);
    }

    #[DataProvider('childModules')]
    public function test_keeps_an_updated_child_record_with_its_existing_employee(string $module): void
    {
        [$class, $payload, $caller, $bindings] = $this->arrange($module, 'update');
        $expected = $payload;
        $payload['employee_id'] = $this->employee()->getKey();

        $validated = $this->validateRequest($class, $payload, $caller, $bindings);

        $this->assertValidatedPayload($expected, $validated);
    }

    #[DataProvider('employeeUniqueFields')]
    public function test_rejects_an_employee_value_already_assigned_to_another_record(string $operation, string $field): void
    {
        [$class, $payload, $caller, $bindings] = $this->arrange('Employee', $operation);
        $otherUser = $this->user();
        $otherEmployee = $this->employee($otherUser, ['company_email' => 'another-employee@example.test']);
        $payload[$field] = $otherEmployee->getAttribute($field);

        $this->assertInvalidRequest($class, $payload, $caller, $bindings, [$field]);
    }

    #[DataProvider('employeeModule')]
    public function test_employee_update_retains_its_own_unique_values(string $module): void
    {
        [$class, $payload, $caller, $bindings] = $this->arrange($module, 'update');
        $account = $this->user();
        $bindings['employee']->update(['user_id' => $account->getKey(), 'company_email' => 'own-employee@example.test']);
        $payload['user_id'] = $account->getKey();
        $payload['company_email'] = 'own-employee@example.test';

        $validated = $this->validateRequest($class, $payload, $caller, $bindings);

        $this->assertValidatedPayload($payload, $validated);
    }

    #[DataProvider('spoofedEmployeeUniqueFields')]
    public function test_employee_update_does_not_use_a_client_identifier_to_ignore_unique_constraints(string $field): void
    {
        [$class, $payload, $caller, $bindings] = $this->arrange('Employee', 'update');
        $otherEmployee = $this->employee($this->user(), ['company_email' => 'spoof-target@example.test']);
        $payload['id'] = $otherEmployee->getKey();
        $payload[$field] = $otherEmployee->getAttribute($field);

        $this->assertInvalidRequest($class, $payload, $caller, $bindings, [$field]);
    }

    #[DataProvider('selfSupervisorOperations')]
    public function test_rejects_employees_as_their_own_supervisors(string $module, string $operation): void
    {
        [$class, $payload, $caller, $bindings] = $this->arrange($module, $operation);
        $payload['supervisor_employee_id'] = $module === 'Employee'
            ? $bindings['employee']->getKey()
            : ($payload['employee_id'] ?? $bindings['employeeEmploymentHistory']->employee_id);

        $this->assertInvalidRequest($class, $payload, $caller, $bindings, ['supervisor_employee_id']);
    }

    #[DataProvider('documentModule')]
    public function test_employee_document_update_accepts_metadata_without_a_replacement_file(string $module): void
    {
        [$class, $payload, $caller, $bindings] = $this->arrange($module, 'update');
        unset($payload['file']);

        $validated = $this->validateRequest($class, $payload, $caller, $bindings);

        $this->assertValidatedPayload($payload, $validated);
    }

    #[DataProvider('documentModule')]
    public function test_employee_document_store_accepts_the_upload_size_limit(string $module): void
    {
        [$class, $payload, $caller, $bindings] = $this->arrange($module, 'store');
        $payload['file'] = UploadedFile::fake()->create('limit.pdf', 10240, 'application/pdf');

        $validated = $this->validateRequest($class, $payload, $caller, $bindings);

        $this->assertValidatedPayload($payload, $validated);
    }

    #[DataProvider('salaryBoundaries')]
    public function test_accepts_nonnegative_salary_within_schema_precision(string $operation, int|string $salary): void
    {
        [$class, $payload, $caller, $bindings] = $this->arrange('EmployeeCompensation', $operation);
        $payload['basic_salary'] = $salary;

        $validated = $this->validateRequest($class, $payload, $caller, $bindings);

        $this->assertValidatedPayload($payload, $validated);
    }

    public static function operations(): array
    {
        $cases = [];

        foreach (self::configuration() as $module => $configuration) {
            foreach (['store', 'update'] as $operation) {
                $cases[$module.' '.$operation] = [$module, $operation];
            }
        }

        return $cases;
    }

    public static function childModules(): array
    {
        return [
            'EmployeeEmploymentHistory immutable employee' => ['EmployeeEmploymentHistory'],
            'EmployeeCompensation immutable employee' => ['EmployeeCompensation'],
            'EmployeeDocument immutable employee' => ['EmployeeDocument'],
        ];
    }

    public static function employeeModule(): array
    {
        return ['Employee own unique values' => ['Employee']];
    }

    public static function documentModule(): array
    {
        return ['EmployeeDocument file boundary' => ['EmployeeDocument']];
    }

    public static function salaryBoundaries(): array
    {
        $cases = [];

        foreach (['store', 'update'] as $operation) {
            foreach ([0, '0.00', '25000', '9999999999999.99'] as $salary) {
                $cases['EmployeeCompensation '.$operation.' salary '.$salary] = [$operation, $salary];
            }
        }

        return $cases;
    }

    public static function requiredFields(): array
    {
        $fields = [
            'Employee' => ['employee_number', 'first_name', 'last_name', 'hire_date', 'employment_type'],
            'EmployeeEmploymentHistory' => ['employee_id', 'employment_type', 'employment_status', 'effective_from', 'change_type'],
            'EmployeeCompensation' => ['employee_id', 'basic_salary', 'pay_frequency', 'effective_from'],
            'EmployeeDocument' => ['employee_id', 'document_type', 'title', 'file'],
        ];
        $cases = [];

        foreach ($fields as $module => $required) {
            foreach (['store', 'update'] as $operation) {
                foreach ($required as $field) {
                    if ($operation === 'update' && in_array($field, ['employee_id', 'file'], true)) {
                        continue;
                    }

                    $cases[$module.' '.$operation.' missing '.$field] = [$module, $operation, $field];
                }
            }
        }

        return $cases;
    }

    public static function invalidFields(): array
    {
        $values = [
            'Employee' => [
                'number too long' => ['employee_number', str_repeat('E', 51)],
                'name has wrong type' => ['first_name', ['invalid']],
                'first name too long' => ['first_name', str_repeat('E', 101)],
                'last name too long' => ['last_name', str_repeat('E', 101)],
                'middle name too long' => ['middle_name', str_repeat('E', 101)],
                'suffix too long' => ['suffix', str_repeat('E', 21)],
                'invalid birthday' => ['birth_date', 'not-a-date'],
                'future birthday' => ['birth_date', '2026-10-04'],
                'gender too long' => ['gender', str_repeat('E', 31)],
                'civil status too long' => ['civil_status', str_repeat('E', 31)],
                'invalid personal email' => ['personal_email', 'not-an-email'],
                'invalid company email' => ['company_email', 'not-an-email'],
                'personal email too long' => ['personal_email', str_repeat('e', 246).'@example.test'],
                'company email too long' => ['company_email', str_repeat('e', 246).'@example.test'],
                'mobile too long' => ['mobile_number', str_repeat('1', 51)],
                'address one too long' => ['address_line_1', str_repeat('E', 256)],
                'address two too long' => ['address_line_2', str_repeat('E', 256)],
                'city too long' => ['city', str_repeat('E', 101)],
                'province too long' => ['province', str_repeat('E', 101)],
                'postal code too long' => ['postal_code', str_repeat('E', 21)],
                'country too long' => ['country', str_repeat('E', 101)],
                'country cannot be null' => ['country', null],
                'invalid hire date' => ['hire_date', 'not-a-date'],
                'invalid regularization date' => ['regularization_date', 'not-a-date'],
                'regularization before hire' => ['regularization_date', '2025-12-31'],
                'invalid separation date' => ['separation_date', 'not-a-date'],
                'separation before hire' => ['separation_date', '2025-12-31'],
                'employment type too long' => ['employment_type', str_repeat('E', 51)],
                'employment status too long' => ['employment_status', str_repeat('E', 51)],
                'employment status cannot be null' => ['employment_status', null],
                'active flag has wrong type' => ['is_active', 'active'],
                'unknown user' => ['user_id', 987654321],
                'user has wrong type' => ['user_id', 'abc'],
                'unknown position' => ['position_id', 987654321],
                'position has wrong type' => ['position_id', 'abc'],
                'unknown supervisor' => ['supervisor_employee_id', 987654321],
                'supervisor has wrong type' => ['supervisor_employee_id', 'abc'],
            ],
            'EmployeeEmploymentHistory' => [
                'unknown employee' => ['employee_id', 987654321],
                'employee has wrong type' => ['employee_id', 'abc'],
                'unknown position' => ['position_id', 987654321],
                'unknown supervisor' => ['supervisor_employee_id', 987654321],
                'type too long' => ['employment_type', str_repeat('E', 51)],
                'status too long' => ['employment_status', str_repeat('E', 51)],
                'change type too long' => ['change_type', str_repeat('E', 51)],
                'invalid effective start' => ['effective_from', 'not-a-date'],
                'invalid effective end' => ['effective_to', 'not-a-date'],
                'effective end before start' => ['effective_to', '2025-12-31'],
                'reason has wrong type' => ['reason', ['invalid']],
                'remarks have wrong type' => ['remarks', ['invalid']],
            ],
            'EmployeeCompensation' => [
                'unknown employee' => ['employee_id', 987654321],
                'employee has wrong type' => ['employee_id', 'abc'],
                'salary has wrong type' => ['basic_salary', ['invalid']],
                'salary is not numeric' => ['basic_salary', 'abc'],
                'negative salary' => ['basic_salary', '-0.01'],
                'salary exceeds schema' => ['basic_salary', '10000000000000.00'],
                'salary has excess precision' => ['basic_salary', '25000.001'],
                'frequency too long' => ['pay_frequency', str_repeat('E', 31)],
                'currency too short' => ['currency', 'PH'],
                'currency too long' => ['currency', 'PHPP'],
                'currency has wrong type' => ['currency', ['invalid']],
                'currency cannot be null' => ['currency', null],
                'invalid effective start' => ['effective_from', 'not-a-date'],
                'invalid effective end' => ['effective_to', 'not-a-date'],
                'effective end before start' => ['effective_to', '2025-12-31'],
                'remarks have wrong type' => ['remarks', ['invalid']],
            ],
            'EmployeeDocument' => [
                'unknown employee' => ['employee_id', 987654321],
                'employee has wrong type' => ['employee_id', 'abc'],
                'type too long' => ['document_type', str_repeat('E', 101)],
                'title too long' => ['title', str_repeat('E', 201)],
                'title has wrong type' => ['title', ['invalid']],
                'file has wrong type' => ['file', 'documents/client-claimed.pdf'],
                'file exceeds size limit' => ['file', '__oversized_file__'],
                'invalid issued date' => ['issued_at', 'not-a-date'],
                'invalid expiry date' => ['expires_at', 'not-a-date'],
                'expiry before issue' => ['expires_at', '2025-12-31'],
                'remarks have wrong type' => ['remarks', ['invalid']],
            ],
        ];
        $cases = [];

        foreach ($values as $module => $invalid) {
            foreach (['store', 'update'] as $operation) {
                foreach ($invalid as $label => [$field, $value]) {
                    if ($operation === 'update' && $field === 'employee_id') {
                        continue;
                    }

                    $cases[$module.' '.$operation.' '.$label] = [$module, $operation, $field, $value];
                }
            }
        }

        return $cases;
    }

    public static function invalidBindings(): array
    {
        $cases = [];

        foreach (self::configuration() as $module => $configuration) {
            foreach (['missing', 'scalar', 'wrong model', 'wrong key'] as $binding) {
                $cases[$module.' '.$binding.' binding'] = [$module, $binding];
            }
        }

        return $cases;
    }

    public static function invalidReferenceDates(): array
    {
        $changes = [
            'Employee' => [['hire_date' => ['2026-01-01'], 'regularization_date' => '2026-02-01', 'separation_date' => '2026-03-01'], 'hire_date'],
            'EmployeeEmploymentHistory' => [['effective_from' => ['2026-01-01'], 'effective_to' => '2026-02-01'], 'effective_from'],
            'EmployeeCompensation' => [['effective_from' => ['2026-01-01'], 'effective_to' => '2026-02-01'], 'effective_from'],
            'EmployeeDocument' => [['issued_at' => ['2026-01-01'], 'expires_at' => '2026-02-01'], 'issued_at'],
        ];
        $cases = [];

        foreach ($changes as $module => [$payload, $field]) {
            foreach (['store', 'update'] as $operation) {
                $cases[$module.' '.$operation.' array reference date'] = [$module, $operation, $payload, $field];
            }
        }

        return $cases;
    }

    public static function employeeUniqueFields(): array
    {
        $cases = [];

        foreach (['store', 'update'] as $operation) {
            foreach (['employee_number', 'company_email', 'user_id'] as $field) {
                $cases['Employee '.$operation.' duplicate '.$field] = [$operation, $field];
            }
        }

        return $cases;
    }

    public static function spoofedEmployeeUniqueFields(): array
    {
        return [
            'Employee spoofed employee number ignore' => ['employee_number'],
            'Employee spoofed company email ignore' => ['company_email'],
            'Employee spoofed user ignore' => ['user_id'],
        ];
    }

    public static function selfSupervisorOperations(): array
    {
        return [
            'Employee update self supervisor' => ['Employee', 'update'],
            'EmployeeEmploymentHistory store self supervisor' => ['EmployeeEmploymentHistory', 'store'],
            'EmployeeEmploymentHistory update self supervisor' => ['EmployeeEmploymentHistory', 'update'],
        ];
    }

    private static function configuration(?string $module = null): array
    {
        $configuration = [
            'Employee' => ['key' => 'employee', 'permissions' => ['employees.create', 'employees.update']],
            'EmployeeEmploymentHistory' => ['key' => 'employeeEmploymentHistory', 'permissions' => ['employees.manage-employment']],
            'EmployeeCompensation' => ['key' => 'employeeCompensation', 'permissions' => ['employees.manage-compensation']],
            'EmployeeDocument' => ['key' => 'employeeDocument', 'permissions' => ['employees.manage-documents']],
        ];

        return $module === null ? $configuration : $configuration[$module];
    }

    private function arrange(string $module, string $operation): array
    {
        $configuration = self::configuration($module);
        $caller = $this->user(...$configuration['permissions']);
        $employee = $this->employee();
        $payload = match ($module) {
            'Employee' => [
                'employee_number' => 'EMP-NEW',
                'first_name' => 'Ada',
                'last_name' => 'Reyes',
                'hire_date' => '2026-01-01',
                'employment_type' => 'regular',
            ],
            'EmployeeEmploymentHistory' => [
                'employee_id' => $employee->getKey(),
                'employment_type' => 'regular',
                'employment_status' => 'active',
                'effective_from' => '2026-01-01',
                'change_type' => 'hiring',
            ],
            'EmployeeCompensation' => [
                'employee_id' => $employee->getKey(),
                'basic_salary' => '25000.00',
                'pay_frequency' => 'monthly',
                'currency' => 'PHP',
                'effective_from' => '2026-01-01',
            ],
            'EmployeeDocument' => [
                'employee_id' => $employee->getKey(),
                'document_type' => 'employment_record',
                'title' => 'Employment record',
                'issued_at' => '2026-01-01',
                'file' => UploadedFile::fake()->create('employment-record.pdf', 10, 'application/pdf'),
            ],
        };
        $bindings = [];

        if ($operation === 'update') {
            $model = $this->boundRecord($module, $employee, $payload);
            $bindings[$configuration['key']] = $model;

            if ($module === 'Employee') {
                $payload['employee_number'] = $employee->employee_number;
            } else {
                unset($payload['employee_id']);
            }
        }

        $class = 'App\\Http\\Requests\\'.ucfirst($operation).$module.'Request';

        return [$class, $payload, $caller, $bindings];
    }

    private function boundRecord(string $module, Employee $employee, array $payload): Model
    {
        return match ($module) {
            'Employee' => $employee,
            'EmployeeEmploymentHistory' => EmployeeEmploymentHistory::create($payload),
            'EmployeeCompensation' => EmployeeCompensation::create($payload),
            'EmployeeDocument' => EmployeeDocument::create([
                'employee_id' => $employee->getKey(),
                'document_type' => $payload['document_type'],
                'title' => $payload['title'],
                'issued_at' => $payload['issued_at'],
                'file_path' => 'employee-documents/employment-record.pdf',
                'original_filename' => 'employment-record.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 10240,
            ]),
        };
    }

    private function assertValidatedPayload(array $expected, array $actual): void
    {
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual);
    }
}
