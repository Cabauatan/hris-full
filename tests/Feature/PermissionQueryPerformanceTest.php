<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class PermissionQueryPerformanceTest extends FormRequestTestCase
{
    #[DataProvider('grantSources')]
    public function test_permission_resolution_uses_one_database_round_trip_without_loading_relationships(string $source, bool $allowed): void
    {
        $actor = $this->user();
        $permission = Permission::query()->create(['code' => 'performance.read', 'name' => 'Read', 'is_active' => true]);
        if ($source === 'direct') {
            $actor->permissions()->attach($permission->id);
        } elseif ($source === 'role') {
            $role = Role::query()->create(['code' => 'performance-role', 'name' => 'Role', 'is_active' => true]);
            $role->permissions()->attach($permission->id);
            $actor->roles()->attach($role->id);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->assertSame($allowed, $actor->hasPermission('performance.read'));
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(1, $queries);
        $this->assertFalse($actor->relationLoaded('permissions'));
        $this->assertFalse($actor->relationLoaded('roles'));
    }

    public function test_permission_and_role_revocations_take_effect_even_when_relationships_were_previously_loaded(): void
    {
        $actor = $this->user('performance.read');
        $permission = Permission::query()->where('code', 'performance.read')->firstOrFail();
        $role = Role::query()->create(['code' => 'performance-role', 'name' => 'Role', 'is_active' => true]);
        $role->permissions()->attach($permission->id);
        $actor->roles()->attach($role->id);
        $actor->load(['roles.permissions', 'permissions']);
        $this->assertTrue($actor->hasPermission('performance.read'));
        $actor->permissions()->detach($permission->id);
        $this->assertTrue($actor->hasPermission('performance.read'));
        $role->update(['is_active' => false]);
        $this->assertFalse($actor->hasPermission('performance.read'));
        $role->update(['is_active' => true]);
        $this->assertTrue($actor->hasPermission('performance.read'));
        $permission->update(['is_active' => false]);
        $this->assertFalse($actor->hasPermission('performance.read'));
        $this->assertFalse($actor->hasPermission("performance.read' OR 1=1 --"));
    }

    public function test_inactive_account_is_refused_without_permission_queries(): void
    {
        $actor = $this->user('performance.read');
        $actor->update(['is_active' => false]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->assertFalse($actor->hasPermission('performance.read'));
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public static function grantSources(): array
    {
        return ['direct grant' => ['direct', true], 'role grant' => ['role', true], 'missing grant' => ['none', false]];
    }
}
