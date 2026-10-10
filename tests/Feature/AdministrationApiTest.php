<?php

namespace Tests\Feature;

use App\Models\ApprovalDelegation;
use App\Models\CompanyProfile;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Division;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class AdministrationApiTest extends FormRequestTestCase
{
    #[DataProvider('modules')]
    public function test_persists_validated_create_and_update_and_returns_resources(string $module): void
    {
        [$class, $endpoint, $payload, $readPermission, $writePermission, $editable] = $this->specification($module);
        $actor = $this->user($readPermission, $writePermission, 'employees.view', ...($module === 'Employee' ? ['employees.update'] : []));
        $forgedActor = $this->user();
        if ($module === 'ApprovalDelegation') {
            $payload['delegator_user_id'] = $actor->id;
        }
        $this->actingAs($actor);
        $response = $this->postJson($endpoint, $payload + ['id' => 999999, 'created_by_user_id' => $forgedActor->id])->assertCreated();
        $id = $response->json('data.id');
        $this->assertIsInt($id);
        $record = $class::query()->findOrFail($id);
        $this->assertNotSame(999999, $record->id);
        $this->assertSame($payload[$editable], $record->getAttribute($editable));
        if (in_array('created_by_user_id', $record->getFillable(), true)) {
            $this->assertSame($actor->id, $record->created_by_user_id);
        }
        $this->getJson($endpoint.'/'.$id)->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson($endpoint)->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonStructure(['data', 'links', 'meta']);
        $updated = $payload;
        $updated[$editable] = $editable === 'day_of_week' ? 2 : 'Updated API value';
        $this->patchJson($endpoint.'/'.$id, $updated + ['id' => 999999, 'created_by_user_id' => $forgedActor->id])->assertOk()->assertJsonPath('data.id', $id);
        $record->refresh();
        $this->assertSame($updated[$editable], $record->getAttribute($editable));
        if (in_array('created_by_user_id', $record->getFillable(), true)) {
            $this->assertSame($actor->id, $record->created_by_user_id);
        }
    }

    #[DataProvider('modules')]
    public function test_returns_401_for_guests(string $module): void
    {
        [, $endpoint, $payload] = $this->specification($module);
        $this->getJson($endpoint)->assertUnauthorized();
        $this->postJson($endpoint, $payload)->assertUnauthorized();
    }

    #[DataProvider('modules')]
    public function test_returns_403_for_users_without_permissions(string $module): void
    {
        [$class, $endpoint, $payload] = $this->specification($module);
        $record = $class::query()->create($payload);
        $this->actingAs($this->user());
        $this->getJson($endpoint)->assertForbidden();
        $this->getJson($endpoint.'/'.$record->id)->assertForbidden();
        $this->postJson($endpoint, $payload)->assertForbidden();
        $this->patchJson($endpoint.'/'.$record->id, $payload)->assertForbidden();
    }

    #[DataProvider('modules')]
    public function test_returns_404_for_missing_route_bound_records(string $module): void
    {
        [, $endpoint, $payload, $readPermission, $writePermission] = $this->specification($module);
        $this->actingAs($this->user($readPermission, $writePermission));
        $this->getJson($endpoint.'/999999')->assertNotFound();
        $this->patchJson($endpoint.'/999999', $payload)->assertNotFound();
    }

    #[DataProvider('modules')]
    public function test_returns_422_and_does_not_persist_invalid_input(string $module): void
    {
        [$class, $endpoint, $payload, , $writePermission, , $required] = $this->specification($module);
        $this->actingAs($this->user($writePermission, 'employees.view'));
        unset($payload[$required]);
        $before = $class::query()->count();
        $this->postJson($endpoint, $payload)->assertUnprocessable()->assertJsonValidationErrors($required);
        $this->assertSame($before, $class::query()->count());
    }

    public function test_division_persists_validated_create_and_update_and_returns_resources(): void
    {
        $this->actingAs($this->user('organization.view', 'organization.manage'));
        $response = $this->postJson('/api/v1/divisions', ['code' => 'DIV-API', 'name' => 'API Division', 'id' => 999999])->assertCreated();
        $id = $response->json('data.id');
        $this->assertIsInt($id);
        $this->assertNotSame(999999, $id);
        $division = Division::query()->findOrFail($id);
        $this->assertSame('API Division', $division->name);
        $this->getJson('/api/v1/divisions/'.$id)->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson('/api/v1/divisions')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonStructure(['data', 'links', 'meta']);
        $this->patchJson('/api/v1/divisions/'.$id, ['code' => 'DIV-API', 'name' => 'Updated Division', 'id' => 999999])->assertOk()->assertJsonPath('data.name', 'Updated Division');
        $this->assertSame('Updated Division', $division->fresh()->name);
    }

    public function test_division_returns_401_for_guests_and_403_for_missing_permissions(): void
    {
        $division = Division::query()->create(['code' => 'DIV-API', 'name' => 'Existing']);
        $this->getJson('/api/v1/divisions')->assertUnauthorized();
        $this->postJson('/api/v1/divisions', ['code' => 'NEW', 'name' => 'New'])->assertUnauthorized();
        $this->actingAs($this->user());
        $this->getJson('/api/v1/divisions')->assertForbidden();
        $this->getJson('/api/v1/divisions/'.$division->id)->assertForbidden();
        $this->postJson('/api/v1/divisions', ['code' => 'NEW', 'name' => 'New'])->assertForbidden();
        $this->patchJson('/api/v1/divisions/'.$division->id, ['code' => 'NEW', 'name' => 'New'])->assertForbidden();
        $this->assertSame('Existing', $division->fresh()->name);
    }

    public function test_division_returns_404_for_missing_records_and_422_for_invalid_input(): void
    {
        $this->actingAs($this->user('organization.view', 'organization.manage'));
        $this->getJson('/api/v1/divisions/999999')->assertNotFound();
        $this->patchJson('/api/v1/divisions/999999', ['code' => 'NEW', 'name' => 'New'])->assertNotFound();
        $this->postJson('/api/v1/divisions', ['code' => 'NEW'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('divisions', 0);
    }

    public function test_division_rejects_duplicate_codes_and_uses_bound_record_for_uniqueness(): void
    {
        $division = Division::query()->create(['code' => 'DIV-API', 'name' => 'Existing']);
        $other = Division::query()->create(['code' => 'OTHER', 'name' => 'Other']);
        $this->actingAs($this->user('organization.manage'));
        $this->postJson('/api/v1/divisions', ['code' => 'DIV-API', 'name' => 'Duplicate'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->patchJson('/api/v1/divisions/'.$division->id, ['code' => 'OTHER', 'name' => 'Spoofed', 'id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame('DIV-API', $division->fresh()->code);
        $this->assertDatabaseCount('divisions', 2);
    }

    public function test_company_profile_reads_and_updates_existing_singleton(): void
    {
        $profile = CompanyProfile::query()->create(['name' => 'HRIS Company', 'country' => 'Philippines']);
        $this->actingAs($this->user('organization.view', 'organization.manage'));
        $this->getJson('/api/v1/company-profiles')->assertOk()->assertJsonPath('data.0.id', $profile->id);
        $this->getJson('/api/v1/company-profiles/'.$profile->id)->assertOk()->assertJsonPath('data.name', 'HRIS Company');
        $this->patchJson('/api/v1/company-profiles/'.$profile->id, ['name' => 'Updated Company', 'country' => 'Philippines'])->assertOk()->assertJsonPath('data.name', 'Updated Company');
        $this->assertDatabaseCount('company_profiles', 1);
        $this->assertSame('Updated Company', $profile->fresh()->name);
    }

    public function test_company_profile_enforces_authentication_permission_binding_and_validation(): void
    {
        $profile = CompanyProfile::query()->create(['name' => 'HRIS Company', 'country' => 'Philippines']);
        $this->getJson('/api/v1/company-profiles')->assertUnauthorized();
        $this->actingAs($this->user());
        $this->getJson('/api/v1/company-profiles/'.$profile->id)->assertForbidden();
        $this->patchJson('/api/v1/company-profiles/'.$profile->id, ['name' => 'Forged', 'country' => 'Philippines'])->assertForbidden();
        $this->actingAs($this->user('organization.view', 'organization.manage'));
        $this->getJson('/api/v1/company-profiles/999999')->assertNotFound();
        $this->patchJson('/api/v1/company-profiles/'.$profile->id, ['country' => 'Philippines'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame('HRIS Company', $profile->fresh()->name);
    }

    public function test_employee_team_lists_only_self_and_direct_reports_without_private_child_records(): void
    {
        $manager = $this->user('employees.view-team');
        $own = $this->employee($manager);
        $direct = $this->employee(null, ['supervisor_employee_id' => $own->id]);
        $grandchild = $this->employee(null, ['supervisor_employee_id' => $direct->id]);
        $outsider = $this->employee();
        EmployeeCompensation::query()->create(['employee_id' => $direct->id, 'basic_salary' => '12345.67', 'pay_frequency' => 'monthly', 'effective_from' => '2026-01-01']);
        $this->actingAs($manager);
        $response = $this->getJson('/api/v1/employees')->assertOk();
        $this->assertEqualsCanonicalizing([$own->id, $direct->id], array_column($response->json('data'), 'id'));
        $this->getJson('/api/v1/employees/'.$direct->id)->assertOk()->assertJsonMissingPath('data.compensations')->assertJsonMissingPath('data.documents')->assertJsonMissingPath('data.user.password');
        $this->getJson('/api/v1/employees/'.$outsider->id)->assertForbidden();
        $this->getJson('/api/v1/employees/'.$grandchild->id)->assertForbidden();
    }

    public function test_employee_owner_can_read_self_without_employee_list_permission(): void
    {
        $user = $this->user();
        $own = $this->employee($user);
        $this->actingAs($user);
        $this->getJson('/api/v1/employees/'.$own->id)->assertOk()->assertJsonPath('data.id', $own->id);
        $this->getJson('/api/v1/employees')->assertForbidden();
    }

    public function test_employee_compensation_update_cannot_transfer_owner_or_replace_creator(): void
    {
        $employee = $this->employee();
        $other = $this->employee();
        $actor = $this->user('employees.manage-compensation');
        $creator = $this->user();
        $record = EmployeeCompensation::query()->create(['employee_id' => $employee->id, 'basic_salary' => '1000.00', 'pay_frequency' => 'monthly', 'effective_from' => '2026-01-01', 'created_by_user_id' => $creator->id]);
        $this->actingAs($actor);
        $this->patchJson('/api/v1/employee-compensations/'.$record->id, ['employee_id' => $other->id, 'basic_salary' => '2000.00', 'pay_frequency' => 'monthly', 'effective_from' => '2026-01-01', 'created_by_user_id' => $actor->id])->assertOk()->assertJsonPath('data.basic_salary', '2000.00');
        $record->refresh();
        $this->assertSame($employee->id, $record->employee_id);
        $this->assertSame($creator->id, $record->created_by_user_id);
    }

    public function test_employee_employment_history_update_cannot_transfer_owner(): void
    {
        $employee = $this->employee();
        $other = $this->employee();
        $record = EmployeeEmploymentHistory::query()->create(['employee_id' => $employee->id, 'employment_type' => 'regular', 'employment_status' => 'active', 'change_type' => 'initial', 'effective_from' => '2026-01-01']);
        $this->actingAs($this->user('employees.manage-employment'));
        $this->patchJson('/api/v1/employee-employment-histories/'.$record->id, ['employee_id' => $other->id, 'employment_type' => 'regular', 'employment_status' => 'active', 'change_type' => 'promotion', 'effective_from' => '2026-01-01'])->assertOk()->assertJsonPath('data.change_type', 'promotion');
        $this->assertSame($employee->id, $record->fresh()->employee_id);
    }

    public function test_employee_schedule_assignment_list_respects_own_team_and_assignment_manager_scopes(): void
    {
        $manager = $this->user('schedules.view', 'employees.view-team');
        $own = $this->employee($manager);
        $direct = $this->employee(null, ['supervisor_employee_id' => $own->id]);
        $outsider = $this->employee();
        $schedule = $this->workSchedule();
        $records = [];
        foreach ([$own, $direct, $outsider] as $employee) {
            $records[] = EmployeeScheduleAssignment::query()->create(['employee_id' => $employee->id, 'work_schedule_id' => $schedule->id, 'effective_from' => '2026-01-01']);
        }
        $this->actingAs($manager);
        $response = $this->getJson('/api/v1/employee-schedule-assignments')->assertOk();
        $this->assertEqualsCanonicalizing([$records[0]->id, $records[1]->id], array_column($response->json('data'), 'id'));
        $this->getJson('/api/v1/employee-schedule-assignments/'.$records[2]->id)->assertForbidden();
        $this->actingAs($this->user('schedules.view', 'employees.view'));
        $this->getJson('/api/v1/employee-schedule-assignments')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->user('schedules.assign'));
        $this->getJson('/api/v1/employee-schedule-assignments')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_time_log_list_does_not_expose_other_employees_through_filters(): void
    {
        $manager = $this->user('attendance.view', 'employees.view-team');
        $own = $this->employee($manager);
        $direct = $this->employee(null, ['supervisor_employee_id' => $own->id]);
        $outsider = $this->employee();
        $records = [];
        foreach ([$own, $direct, $outsider] as $employee) {
            $records[] = TimeLog::query()->create(['employee_id' => $employee->id, 'logged_at' => '2026-10-03 08:00:00']);
        }
        $this->actingAs($manager);
        $response = $this->getJson('/api/v1/time-logs')->assertOk();
        $this->assertEqualsCanonicalizing([$records[0]->id, $records[1]->id], array_column($response->json('data'), 'id'));
        $response = $this->getJson('/api/v1/time-logs?employee_id='.$outsider->id)->assertOk();
        $this->assertNotContains($records[2]->id, array_column($response->json('data'), 'id'));
        $this->getJson('/api/v1/time-logs/'.$records[2]->id)->assertForbidden();
        $this->actingAs($this->user('attendance.view', 'employees.view'));
        $this->getJson('/api/v1/time-logs')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_approval_delegation_list_shows_only_involved_users_unless_globally_authorized(): void
    {
        $caller = $this->user('approval-delegations.view', 'approval-delegations.manage');
        $other = $this->user();
        $third = $this->user();
        $own = ApprovalDelegation::query()->create(['delegator_user_id' => $caller->id, 'delegate_user_id' => $other->id, 'starts_at' => '2026-10-03 08:00:00', 'ends_at' => '2026-10-04 17:00:00']);
        $received = ApprovalDelegation::query()->create(['delegator_user_id' => $other->id, 'delegate_user_id' => $caller->id, 'starts_at' => '2026-10-03 08:00:00', 'ends_at' => '2026-10-04 17:00:00']);
        $hidden = ApprovalDelegation::query()->create(['delegator_user_id' => $other->id, 'delegate_user_id' => $third->id, 'starts_at' => '2026-10-03 08:00:00', 'ends_at' => '2026-10-04 17:00:00']);
        $this->actingAs($caller);
        $response = $this->getJson('/api/v1/approval-delegations')->assertOk();
        $this->assertEqualsCanonicalizing([$own->id, $received->id], array_column($response->json('data'), 'id'));
        $this->getJson('/api/v1/approval-delegations/'.$hidden->id)->assertForbidden();
        $this->patchJson('/api/v1/approval-delegations/'.$received->id, ['delegator_user_id' => $other->id, 'delegate_user_id' => $caller->id, 'starts_at' => '2026-10-03 08:00:00', 'ends_at' => '2026-10-04 17:00:00'])->assertForbidden();
        $this->actingAs($this->user('approval-delegations.view', 'employees.view'));
        $this->getJson('/api/v1/approval-delegations')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_employee_document_upload_uses_private_server_metadata_and_download_permission(): void
    {
        Storage::fake('local');
        $actor = $this->user('employees.manage-documents');
        $employee = $this->employee();
        $this->actingAs($actor);
        $response = $this->post('/api/v1/employee-documents', [
            'employee_id' => $employee->id,
            'document_type' => 'contract',
            'title' => 'Employment Contract',
            'file' => UploadedFile::fake()->createWithContent('contract.pdf', 'private employee document'),
            'file_path' => '../secrets',
            'original_filename' => 'forged.txt',
            'mime_type' => 'forged/type',
            'file_size' => 999999,
            'uploaded_by_user_id' => $this->user()->id,
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonMissingPath('data.file_path');
        $document = EmployeeDocument::query()->findOrFail($response->json('data.id'));
        $this->assertSame($actor->id, $document->uploaded_by_user_id);
        $this->assertSame('contract.pdf', $document->original_filename);
        $this->assertSame(strlen('private employee document'), $document->file_size);
        $this->assertNotSame('forged/type', $document->mime_type);
        $this->assertStringStartsWith('employee-documents/', $document->file_path);
        Storage::disk('local')->assertExists($document->file_path);
        $this->getJson('/api/v1/employee-documents/'.$document->id)->assertOk()->assertJsonMissingPath('data.file_path');
        $this->getJson('/api/v1/employee-documents/'.$document->id.'/download')->assertOk()->assertDownload('contract.pdf');
        $this->actingAs($this->user());
        $this->getJson('/api/v1/employee-documents/'.$document->id.'/download')->assertForbidden();
    }

    public function test_employee_document_replacement_preserves_owner_cleans_old_file_and_metadata_update_keeps_file(): void
    {
        Storage::fake('local');
        $actor = $this->user('employees.manage-documents');
        $employee = $this->employee();
        $other = $this->employee();
        Storage::disk('local')->put('employee-documents/old.pdf', 'old content');
        $document = EmployeeDocument::query()->create(['employee_id' => $employee->id, 'document_type' => 'contract', 'title' => 'Old', 'file_path' => 'employee-documents/old.pdf', 'original_filename' => 'old.pdf', 'mime_type' => 'application/pdf', 'file_size' => 11, 'uploaded_by_user_id' => $actor->id]);
        $this->actingAs($actor);
        $this->patch('/api/v1/employee-documents/'.$document->id, [
            'employee_id' => $other->id, 'document_type' => 'contract', 'title' => 'Replacement',
            'file' => UploadedFile::fake()->createWithContent('new.pdf', 'new content'),
            'file_path' => '../forged.pdf', 'uploaded_by_user_id' => $this->user()->id,
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonMissingPath('data.file_path');
        $document->refresh();
        $this->assertSame($employee->id, $document->employee_id);
        $this->assertSame($actor->id, $document->uploaded_by_user_id);
        $this->assertSame('new.pdf', $document->original_filename);
        $this->assertNotSame('employee-documents/old.pdf', $document->file_path);
        Storage::disk('local')->assertMissing('employee-documents/old.pdf');
        Storage::disk('local')->assertExists($document->file_path);
        $path = $document->file_path;
        $this->patchJson('/api/v1/employee-documents/'.$document->id, ['document_type' => 'contract', 'title' => 'Metadata only'])->assertOk();
        $this->assertSame($path, $document->fresh()->file_path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_employee_document_invalid_upload_is_422_without_a_file_or_record(): void
    {
        Storage::fake('local');
        $this->actingAs($this->user('employees.manage-documents'));
        $employee = $this->employee();
        $this->post('/api/v1/employee-documents', ['employee_id' => $employee->id, 'document_type' => 'contract', 'title' => 'Too large', 'file' => UploadedFile::fake()->create('large.pdf', 10241)], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('employee_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_employee_document_returns_401_403_and_404_without_exposing_storage_paths(): void
    {
        Storage::fake('local');
        $document = EmployeeDocument::query()->create(['employee_id' => $this->employee()->id, 'document_type' => 'contract', 'title' => 'Private', 'file_path' => 'employee-documents/missing.pdf', 'original_filename' => 'missing.pdf']);
        $this->getJson('/api/v1/employee-documents')->assertUnauthorized();
        $this->actingAs($this->user());
        $this->getJson('/api/v1/employee-documents')->assertForbidden();
        $this->getJson('/api/v1/employee-documents/'.$document->id)->assertForbidden();
        $this->actingAs($this->user('employees.manage-documents'));
        $this->getJson('/api/v1/employee-documents/999999')->assertNotFound();
        $this->getJson('/api/v1/employee-documents/'.$document->id.'/download')->assertNotFound();
    }

    public function test_employee_document_new_issue_date_must_respect_stored_expiry_unless_expiry_is_cleared(): void
    {
        Storage::fake('local');
        $actor = $this->user('employees.manage-documents');
        Storage::disk('local')->put('employee-documents/dated.pdf', 'original document');
        $document = EmployeeDocument::query()->create([
            'employee_id' => $this->employee()->id,
            'document_type' => 'contract',
            'title' => 'Dated Contract',
            'file_path' => 'employee-documents/dated.pdf',
            'original_filename' => 'dated.pdf',
            'issued_at' => '2026-01-01',
            'expires_at' => '2026-06-30',
            'uploaded_by_user_id' => $actor->id,
        ]);
        $this->actingAs($actor);
        $this->patch('/api/v1/employee-documents/'.$document->id, [
            'document_type' => 'contract',
            'title' => 'Invalid Replacement',
            'issued_at' => '2026-07-01',
            'file' => UploadedFile::fake()->createWithContent('replacement.pdf', 'replacement document'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('expires_at');
        $document->refresh();
        $this->assertSame('Dated Contract', $document->title);
        $this->assertSame('2026-01-01', $document->issued_at->format('Y-m-d'));
        $this->assertSame('2026-06-30', $document->expires_at->format('Y-m-d'));
        $this->assertSame('employee-documents/dated.pdf', $document->file_path);
        $this->assertSame(['employee-documents/dated.pdf'], Storage::disk('local')->allFiles());
        $this->assertSame('original document', Storage::disk('local')->get($document->file_path));
        $this->patchJson('/api/v1/employee-documents/'.$document->id, [
            'document_type' => 'contract',
            'title' => 'Renewed Contract',
            'issued_at' => '2026-07-01',
            'expires_at' => null,
        ])->assertOk()->assertJsonPath('data.issued_at', '2026-07-01')->assertJsonPath('data.expires_at', null);
        $document->refresh();
        $this->assertSame('2026-07-01', $document->issued_at->format('Y-m-d'));
        $this->assertNull($document->expires_at);
        $this->assertSame('employee-documents/dated.pdf', $document->file_path);
        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_division_rejects_unbounded_pagination_and_does_not_accept_user_sort_sql(): void
    {
        Division::query()->create(['code' => 'FIRST', 'name' => 'First']);
        $second = Division::query()->create(['code' => 'SECOND', 'name' => 'Second']);
        $this->actingAs($this->user('organization.view'));
        $this->getJson('/api/v1/divisions?per_page=1000')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson('/api/v1/divisions?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->getJson('/api/v1/divisions?per_page=1&sort=name%20desc%3B%20DROP%20TABLE%20divisions')->assertOk()->assertJsonPath('data.0.id', $second->id)->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('divisions', 2);
    }

    public function test_employee_compensation_filters_by_employee_and_rejects_array_query_values(): void
    {
        $selected = $this->employee();
        $other = $this->employee();
        $wanted = EmployeeCompensation::query()->create(['employee_id' => $selected->id, 'basic_salary' => '1000.00', 'pay_frequency' => 'monthly', 'effective_from' => '2026-01-01']);
        EmployeeCompensation::query()->create(['employee_id' => $other->id, 'basic_salary' => '2000.00', 'pay_frequency' => 'monthly', 'effective_from' => '2026-01-01']);
        $this->actingAs($this->user('employees.manage-compensation'));
        $this->getJson('/api/v1/employee-compensations?employee_id='.$selected->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $wanted->id);
        $this->getJson('/api/v1/employee-compensations?employee_id%5B%5D='.$selected->id)->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    }

    public function test_time_log_employee_filter_intersects_authorized_scope_and_rejects_array_values(): void
    {
        $viewer = $this->user('attendance.view');
        $own = $this->employee($viewer);
        $other = $this->employee();
        $wanted = TimeLog::query()->create(['employee_id' => $own->id, 'logged_at' => '2026-10-03 08:00:00']);
        TimeLog::query()->create(['employee_id' => $other->id, 'logged_at' => '2026-10-03 08:00:00']);
        $this->actingAs($viewer);
        $this->getJson('/api/v1/time-logs?employee_id='.$own->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $wanted->id);
        $this->getJson('/api/v1/time-logs?employee_id='.$other->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/time-logs?employee_id%5B%5D='.$other->id)->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    }

    public function test_leave_type_creates_reads_and_updates_validated_flags_without_exposing_balances(): void
    {
        $this->actingAs($this->user('leave.view', 'leave.manage-types'));
        $payload = ['code' => 'API-VL', 'name' => 'Vacation Leave', 'description' => 'Paid vacation', 'is_paid' => true, 'requires_balance' => true, 'allows_partial_day' => false, 'requires_document' => false, 'is_active' => true];
        $response = $this->postJson('/api/v1/leave-types', $payload + ['id' => 999999])->assertCreated()->assertJsonPath('data.is_paid', true)->assertJsonPath('data.allows_partial_day', false)->assertJsonMissingPath('data.balances')->assertJsonMissingPath('data.requests');
        $id = $response->json('data.id');
        $this->assertIsInt($id);
        $this->assertNotSame(999999, $id);
        $record = LeaveType::query()->findOrFail($id);
        $this->assertSame('API-VL', $record->code);
        $this->assertTrue($record->requires_balance);
        $this->getJson('/api/v1/leave-types')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonStructure(['data', 'meta', 'links']);
        $this->getJson('/api/v1/leave-types/'.$id)->assertOk()->assertJsonPath('data.name', 'Vacation Leave');
        $this->patchJson('/api/v1/leave-types/'.$id, array_replace($payload, ['name' => 'Updated Leave', 'is_active' => false]))->assertOk()->assertJsonPath('data.name', 'Updated Leave')->assertJsonPath('data.is_active', false);
        $record->refresh();
        $this->assertSame('Updated Leave', $record->name);
        $this->assertFalse($record->is_active);
    }

    public function test_leave_type_enforces_401_403_and_missing_model_404(): void
    {
        $record = LeaveType::query()->create(['code' => 'API-VL', 'name' => 'Vacation Leave']);
        $this->getJson('/api/v1/leave-types')->assertUnauthorized();
        $this->postJson('/api/v1/leave-types', ['code' => 'NEW', 'name' => 'New Leave'])->assertUnauthorized();
        $this->actingAs($this->user());
        $this->getJson('/api/v1/leave-types')->assertForbidden();
        $this->getJson('/api/v1/leave-types/'.$record->id)->assertForbidden();
        $this->postJson('/api/v1/leave-types', ['code' => 'NEW', 'name' => 'New Leave'])->assertForbidden();
        $this->patchJson('/api/v1/leave-types/'.$record->id, ['code' => 'API-VL', 'name' => 'Forged'])->assertForbidden();
        $this->actingAs($this->user('leave.view', 'leave.manage-types'));
        $this->getJson('/api/v1/leave-types/999999')->assertNotFound();
        $this->patchJson('/api/v1/leave-types/999999', ['code' => 'NEW', 'name' => 'New Leave'])->assertNotFound();
        $this->assertSame('Vacation Leave', $record->fresh()->name);
    }

    public function test_leave_type_read_permission_does_not_allow_write_or_deletion(): void
    {
        $record = LeaveType::query()->create(['code' => 'API-VL', 'name' => 'Vacation Leave']);
        $this->actingAs($this->user('leave.view'));
        $this->getJson('/api/v1/leave-types/'.$record->id)->assertOk();
        $this->postJson('/api/v1/leave-types', ['code' => 'NEW', 'name' => 'New Leave'])->assertForbidden();
        $this->patchJson('/api/v1/leave-types/'.$record->id, ['code' => 'API-VL', 'name' => 'Forged'])->assertForbidden();
        $this->actingAs($this->user('leave.manage-types'));
        $this->getJson('/api/v1/leave-types')->assertForbidden();
        $this->deleteJson('/api/v1/leave-types/'.$record->id)->assertStatus(405);
        $this->assertDatabaseHas('leave_types', ['id' => $record->id, 'name' => 'Vacation Leave']);
    }

    public function test_leave_type_validation_422_prevents_invalid_and_duplicate_writes(): void
    {
        $record = LeaveType::query()->create(['code' => 'API-VL', 'name' => 'Vacation Leave']);
        $other = LeaveType::query()->create(['code' => 'API-SL', 'name' => 'Sick Leave']);
        $this->actingAs($this->user('leave.manage-types'));
        $this->postJson('/api/v1/leave-types', ['code' => 'NEW'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/leave-types', ['code' => 'API-VL', 'name' => 'Duplicate'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->patchJson('/api/v1/leave-types/'.$record->id, ['id' => $other->id, 'code' => 'API-SL', 'name' => 'Forged'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame('API-VL', $record->fresh()->code);
        $this->assertDatabaseCount('leave_types', 2);
    }

    public function test_daily_attendance_returns_paginated_resources_with_dates_and_calculated_values(): void
    {
        $record = DailyAttendance::query()->create(['employee_id' => $this->employee()->id, 'attendance_date' => '2026-10-03', 'worked_minutes' => 480, 'late_minutes' => 5, 'status' => 'present', 'is_holiday' => true]);
        $this->actingAs($this->user('attendance.view', 'employees.view'));
        $this->getJson('/api/v1/daily-attendances')->assertOk()->assertJsonPath('data.0.id', $record->id)->assertJsonStructure(['data', 'meta', 'links']);
        $this->getJson('/api/v1/daily-attendances/'.$record->id)->assertOk()->assertJsonPath('data.attendance_date', '2026-10-03')->assertJsonPath('data.worked_minutes', 480)->assertJsonPath('data.late_minutes', 5)->assertJsonPath('data.is_holiday', true)->assertJsonMissingPath('data.correction_requests');
    }

    public function test_daily_attendance_requires_authentication_active_permission_and_existing_record(): void
    {
        $record = DailyAttendance::query()->create(['employee_id' => $this->employee()->id, 'attendance_date' => '2026-10-03']);
        $this->getJson('/api/v1/daily-attendances')->assertUnauthorized();
        $this->getJson('/api/v1/daily-attendances/'.$record->id)->assertUnauthorized();
        $this->actingAs($this->user());
        $this->getJson('/api/v1/daily-attendances')->assertForbidden();
        $this->getJson('/api/v1/daily-attendances/'.$record->id)->assertForbidden();
        $inactive = $this->user('attendance.view', 'employees.view');
        $inactive->update(['is_active' => false]);
        $this->actingAs($inactive);
        $this->getJson('/api/v1/daily-attendances')->assertForbidden();
        $this->actingAs($this->user('attendance.view', 'employees.view'));
        $this->getJson('/api/v1/daily-attendances/999999')->assertNotFound();
    }

    public function test_daily_attendance_list_and_show_share_self_and_direct_team_scope(): void
    {
        $viewer = $this->user('attendance.view', 'employees.view-team');
        $own = $this->employee($viewer);
        $direct = $this->employee(null, ['supervisor_employee_id' => $own->id]);
        $grandchild = $this->employee(null, ['supervisor_employee_id' => $direct->id]);
        $outsider = $this->employee();
        $records = [];
        foreach ([$own, $direct, $grandchild, $outsider] as $employee) {
            $records[] = DailyAttendance::query()->create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-03']);
        }
        $this->actingAs($viewer);
        $response = $this->getJson('/api/v1/daily-attendances')->assertOk();
        $this->assertEqualsCanonicalizing([$records[0]->id, $records[1]->id], array_column($response->json('data'), 'id'));
        $this->getJson('/api/v1/daily-attendances/'.$records[0]->id)->assertOk();
        $this->getJson('/api/v1/daily-attendances/'.$records[1]->id)->assertOk();
        $this->getJson('/api/v1/daily-attendances/'.$records[2]->id)->assertForbidden();
        $this->getJson('/api/v1/daily-attendances/'.$records[3]->id)->assertForbidden();
        $this->actingAs($this->user('attendance.view'));
        $this->getJson('/api/v1/daily-attendances')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->user('attendance.view', 'employees.view'));
        $this->getJson('/api/v1/daily-attendances')->assertOk()->assertJsonCount(4, 'data');
    }

    public function test_daily_attendance_without_team_permission_reads_only_own_records(): void
    {
        $viewer = $this->user('attendance.view');
        $own = $this->employee($viewer);
        $direct = $this->employee(null, ['supervisor_employee_id' => $own->id]);
        $ownRecord = DailyAttendance::query()->create(['employee_id' => $own->id, 'attendance_date' => '2026-10-03']);
        $otherRecord = DailyAttendance::query()->create(['employee_id' => $direct->id, 'attendance_date' => '2026-10-03']);
        $this->actingAs($viewer);
        $this->getJson('/api/v1/daily-attendances')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownRecord->id);
        $this->getJson('/api/v1/daily-attendances/'.$otherRecord->id)->assertForbidden();
    }

    public function test_daily_attendance_exposes_no_client_write_routes_for_calculated_records(): void
    {
        $record = DailyAttendance::query()->create(['employee_id' => $this->employee()->id, 'attendance_date' => '2026-10-03', 'worked_minutes' => 480]);
        $this->actingAs($this->user('attendance.view', 'attendance.manage', 'employees.view'));
        $this->postJson('/api/v1/daily-attendances', ['employee_id' => $record->employee_id, 'attendance_date' => '2026-10-04'])->assertStatus(405);
        $this->patchJson('/api/v1/daily-attendances/'.$record->id, ['worked_minutes' => 999])->assertStatus(405);
        $this->deleteJson('/api/v1/daily-attendances/'.$record->id)->assertStatus(405);
        $this->assertSame(480, $record->fresh()->worked_minutes);
        $this->assertDatabaseCount('daily_attendances', 1);
    }

    public function test_work_schedule_day_list_and_detail_include_only_readable_schedule_and_shift_summaries(): void
    {
        $schedule = $this->workSchedule();
        $schedule->update(['description' => 'Excluded schedule description']);
        $shift = Shift::query()->create([
            'code' => 'DAY-SHIFT', 'name' => 'Day shift',
            'start_time' => '08:00:00', 'end_time' => '17:00:00',
            'remarks' => 'Excluded shift remarks',
        ]);
        $day = WorkScheduleDay::query()->create([
            'work_schedule_id' => $schedule->id, 'day_of_week' => 1,
            'is_working_day' => true, 'shift_id' => $shift->id,
        ]);
        $expectedSchedule = ['id' => $schedule->id, 'code' => 'SCHEDULE-API', 'name' => 'Test schedule'];
        $expectedShift = [
            'id' => $shift->id, 'code' => 'DAY-SHIFT', 'name' => 'Day shift',
            'start_time' => '08:00:00', 'end_time' => '17:00:00',
            'is_overnight' => false,
        ];
        $this->actingAs($this->user('schedules.view'));

        $this->getJson('/api/v1/work-schedule-days')->assertOk()
            ->assertJsonPath('data.0.work_schedule_id', $schedule->id)
            ->assertJsonPath('data.0.shift_id', $shift->id)
            ->assertJsonPath('data.0.day_of_week', 1)
            ->assertJsonPath('data.0.work_schedule', $expectedSchedule)
            ->assertJsonPath('data.0.shift', $expectedShift)
            ->assertJsonStructure(['data', 'links', 'meta']);
        $this->getJson('/api/v1/work-schedule-days/'.$day->id)->assertOk()
            ->assertJsonPath('data.work_schedule_id', $schedule->id)
            ->assertJsonPath('data.shift_id', $shift->id)
            ->assertJsonPath('data.work_schedule', $expectedSchedule)
            ->assertJsonPath('data.shift', $expectedShift)
            ->assertJsonMissingPath('data.work_schedule.description')
            ->assertJsonMissingPath('data.shift.remarks');
    }

    public function test_work_schedule_day_rest_days_return_a_null_shift_summary(): void
    {
        $schedule = $this->workSchedule();
        $day = WorkScheduleDay::query()->create([
            'work_schedule_id' => $schedule->id, 'day_of_week' => 7,
            'is_working_day' => false, 'shift_id' => null,
        ]);
        $this->actingAs($this->user('schedules.view'));

        $this->getJson('/api/v1/work-schedule-days')->assertOk()
            ->assertJsonPath('data.0.work_schedule.name', 'Test schedule')
            ->assertJsonPath('data.0.day_of_week', 7)
            ->assertJsonPath('data.0.is_working_day', false)
            ->assertJsonPath('data.0.shift_id', null)
            ->assertJsonPath('data.0.shift', null);
        $this->getJson('/api/v1/work-schedule-days/'.$day->id)->assertOk()
            ->assertJsonPath('data.work_schedule.name', 'Test schedule')
            ->assertJsonPath('data.is_working_day', false)
            ->assertJsonPath('data.shift', null);
    }

    public function test_work_schedule_day_create_and_update_return_current_schedule_and_shift_summaries(): void
    {
        $schedule = $this->workSchedule();
        $otherSchedule = WorkSchedule::query()->create(['code' => 'OTHER-SCHEDULE', 'name' => 'Other schedule']);
        $shift = Shift::query()->create([
            'code' => 'DAY-SHIFT', 'name' => 'Day shift',
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'is_active' => true,
        ]);
        $otherShift = Shift::query()->create([
            'code' => 'NIGHT-SHIFT', 'name' => 'Night shift',
            'start_time' => '22:00:00', 'end_time' => '07:00:00', 'is_active' => true, 'is_overnight' => true,
        ]);
        $this->actingAs($this->user('schedules.manage'));
        $response = $this->postJson('/api/v1/work-schedule-days', [
            'work_schedule_id' => $schedule->id, 'day_of_week' => 1,
            'is_working_day' => true, 'shift_id' => $shift->id,
        ])->assertCreated()
            ->assertJsonPath('data.work_schedule.name', 'Test schedule')
            ->assertJsonPath('data.shift.name', 'Day shift')
            ->assertJsonPath('data.shift.start_time', '08:00:00');
        $id = $response->json('data.id');
        $this->patchJson('/api/v1/work-schedule-days/'.$id, [
            'work_schedule_id' => $otherSchedule->id, 'day_of_week' => 2,
            'is_working_day' => true, 'shift_id' => $otherShift->id,
        ])->assertOk()
            ->assertJsonPath('data.work_schedule_id', $otherSchedule->id)
            ->assertJsonPath('data.work_schedule.name', 'Other schedule')
            ->assertJsonPath('data.shift_id', $otherShift->id)
            ->assertJsonPath('data.shift.name', 'Night shift')
            ->assertJsonPath('data.shift.start_time', '22:00:00')
            ->assertJsonPath('data.shift.end_time', '07:00:00')
            ->assertJsonPath('data.shift.is_overnight', true);
        $this->patchJson('/api/v1/work-schedule-days/'.$id, [
            'work_schedule_id' => $otherSchedule->id, 'day_of_week' => 7,
            'is_working_day' => false, 'shift_id' => null,
        ])->assertOk()
            ->assertJsonPath('data.work_schedule.name', 'Other schedule')
            ->assertJsonPath('data.shift_id', null)
            ->assertJsonPath('data.shift', null);
    }

    /** @return iterable<string, array{string}> */
    public static function modules(): iterable
    {
        foreach (['Department', 'Position', 'Shift', 'WorkSchedule', 'WorkScheduleDay', 'EmployeeScheduleAssignment', 'Holiday', 'ApprovalDelegation', 'Employee', 'EmployeeEmploymentHistory', 'EmployeeCompensation', 'TimeLog'] as $module) {
            yield $module => [$module];
        }
    }

    /** @return array{class-string<Model>, string, array<string, mixed>, string, string, string, string} */
    private function specification(string $module): array
    {
        return match ($module) {
            'Department' => [Department::class, '/api/v1/departments', ['code' => 'DEPT-API', 'name' => 'API Department', 'division_id' => Division::query()->create(['code' => 'PARENT-DIV', 'name' => 'Parent'])->id], 'organization.view', 'organization.manage', 'name', 'name'],
            'Position' => [Position::class, '/api/v1/positions', ['code' => 'POS-API', 'name' => 'API Position', 'department_id' => Department::query()->create(['code' => 'PARENT-DEPT', 'name' => 'Parent'])->id], 'organization.view', 'organization.manage', 'name', 'name'],
            'Shift' => [Shift::class, '/api/v1/shifts', ['code' => 'SHIFT-API', 'name' => 'API Shift', 'start_time' => '08:00:00', 'end_time' => '17:00:00'], 'schedules.view', 'schedules.manage', 'name', 'name'],
            'WorkSchedule' => [WorkSchedule::class, '/api/v1/work-schedules', ['code' => 'WS-API', 'name' => 'API Schedule'], 'schedules.view', 'schedules.manage', 'name', 'name'],
            'WorkScheduleDay' => [WorkScheduleDay::class, '/api/v1/work-schedule-days', ['work_schedule_id' => $this->workSchedule()->id, 'day_of_week' => 1, 'is_working_day' => false], 'schedules.view', 'schedules.manage', 'day_of_week', 'day_of_week'],
            'EmployeeScheduleAssignment' => [EmployeeScheduleAssignment::class, '/api/v1/employee-schedule-assignments', ['employee_id' => $this->employee()->id, 'work_schedule_id' => $this->workSchedule()->id, 'effective_from' => '2026-01-01', 'remarks' => 'API assignment'], 'schedules.view', 'schedules.assign', 'remarks', 'effective_from'],
            'Holiday' => [Holiday::class, '/api/v1/holidays', ['name' => 'API Holiday', 'holiday_date' => '2026-12-25', 'holiday_type' => 'regular'], 'holidays.view', 'holidays.manage', 'name', 'name'],
            'ApprovalDelegation' => [ApprovalDelegation::class, '/api/v1/approval-delegations', ['delegator_user_id' => $this->user()->id, 'delegate_user_id' => $this->user()->id, 'starts_at' => '2026-10-03 08:00:00', 'ends_at' => '2026-10-04 17:00:00', 'reason' => 'API delegation'], 'approval-delegations.view', 'approval-delegations.manage', 'reason', 'starts_at'],
            'Employee' => [Employee::class, '/api/v1/employees', ['employee_number' => 'EMP-API', 'first_name' => 'API', 'last_name' => 'Employee', 'hire_date' => '2026-01-01', 'employment_type' => 'regular'], 'employees.view', 'employees.create', 'first_name', 'first_name'],
            'EmployeeEmploymentHistory' => [EmployeeEmploymentHistory::class, '/api/v1/employee-employment-histories', ['employee_id' => $this->employee()->id, 'employment_type' => 'regular', 'employment_status' => 'active', 'change_type' => 'initial', 'effective_from' => '2026-01-01', 'remarks' => 'API history'], 'employees.manage-employment', 'employees.manage-employment', 'remarks', 'employment_type'],
            'EmployeeCompensation' => [EmployeeCompensation::class, '/api/v1/employee-compensations', ['employee_id' => $this->employee()->id, 'basic_salary' => '12345.67', 'pay_frequency' => 'monthly', 'effective_from' => '2026-01-01', 'remarks' => 'API compensation'], 'employees.manage-compensation', 'employees.manage-compensation', 'remarks', 'basic_salary'],
            'TimeLog' => [TimeLog::class, '/api/v1/time-logs', ['employee_id' => $this->employee()->id, 'logged_at' => '2026-10-03 08:00:00', 'remarks' => 'API log'], 'attendance.view', 'attendance.manage', 'remarks', 'logged_at'],
        };
    }

    private function workSchedule(): WorkSchedule
    {
        return WorkSchedule::query()->create(['code' => 'SCHEDULE-API', 'name' => 'Test schedule', 'is_active' => true]);
    }
}
