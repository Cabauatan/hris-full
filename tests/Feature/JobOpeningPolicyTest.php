<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\JobOpening;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class JobOpeningPolicyTest extends FormRequestTestCase
{
    #[DataProvider('staffPermissions')]
    public function test_staff_posting_permissions_are_independent_of_applicant_identity(array $permissions, bool $view, bool $manage): void
    {
        $staff = $this->user(...$permissions);
        $opening = JobOpening::factory()->create();
        $gate = Gate::forUser($staff);

        $this->assertSame($view, $gate->allows('viewAny', JobOpening::class));
        $this->assertSame($view, $gate->allows('view', $opening));
        $this->assertSame($manage, $gate->allows('create', JobOpening::class));
        $this->assertSame($manage, $gate->allows('update', $opening));
        $this->assertFalse($gate->allows('delete', $opening));
    }

    public function test_an_applicant_cannot_use_staff_abilities_even_with_a_matching_user_id(): void
    {
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $applicant = Applicant::factory()->create(['id' => $staff->id]);
        $opening = JobOpening::factory()->create();
        $gate = Gate::forUser($applicant);

        $this->assertFalse($gate->allows('viewAny', JobOpening::class));
        $this->assertFalse($gate->allows('view', $opening));
        $this->assertFalse($gate->allows('create', JobOpening::class));
        $this->assertFalse($gate->allows('update', $opening));
    }

    public function test_disabled_staff_cannot_read_or_manage_postings(): void
    {
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $staff->update(['is_active' => false]);
        $opening = JobOpening::factory()->create();
        $gate = Gate::forUser($staff);

        $this->assertFalse($gate->allows('viewAny', JobOpening::class));
        $this->assertFalse($gate->allows('view', $opening));
        $this->assertFalse($gate->allows('create', JobOpening::class));
        $this->assertFalse($gate->allows('update', $opening));
    }

    public static function staffPermissions(): array
    {
        return [
            'none' => [[], false, false],
            'view only' => [['recruitment.view'], true, false],
            'manage only' => [['recruitment.manage'], false, true],
            'grade only' => [['recruitment.grade'], false, false],
            'view and manage' => [['recruitment.view', 'recruitment.manage'], true, true],
        ];
    }
}
