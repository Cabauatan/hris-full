<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationPrimaryKeyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_every_migrated_table_has_an_explicit_primary_key_required_by_hosted_mysql(): void
    {
        $tables = Schema::getTables();
        $withoutPrimaryKeys = [];

        foreach ($tables as $table) {
            if (! collect(Schema::getIndexes($table['name']))->contains('primary', true)) {
                $withoutPrimaryKeys[] = $table['name'];
            }
        }

        $this->assertNotEmpty($tables);
        $this->assertSame([], $withoutPrimaryKeys, 'Hosted MySQL requires an explicit primary key on every table.');
    }

    public function test_interview_panel_membership_uses_its_unique_participant_pair_as_the_primary_key(): void
    {
        $primaryKey = collect(Schema::getIndexes('recruitment_interview_user'))->firstWhere('primary', true);

        $this->assertSame(['recruitment_interview_id', 'user_id'], $primaryKey['columns'] ?? null);
    }
}
