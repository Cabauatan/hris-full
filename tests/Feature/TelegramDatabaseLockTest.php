<?php

namespace Tests\Feature;

use App\Services\Telegram\TelegramDatabaseLock;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Tests\FormRequestTestCase;

class TelegramDatabaseLockTest extends FormRequestTestCase
{
    public function test_zero_changed_rows_renew_own_live_lease_without_growing_its_ttl(): void
    {
        $this->freezeTime();
        DB::table('cache_locks')->count();
        $connection = $this->zeroChangedRowsConnection();
        $lock = new TelegramDatabaseLock($connection, 'cache_locks', 'telegram-test-lock', 90, 'original-owner', [0, 100]);
        $this->assertTrue($lock->get());
        $expiration = now()->timestamp + 90;

        $this->assertTrue($lock->refresh(90));
        $this->assertTrue($lock->refresh(90));
        $this->assertSame($expiration, DB::table('cache_locks')->where('key', 'telegram-test-lock')->value('expiration'));
        $this->travel(15)->seconds();
        $this->assertTrue($lock->refresh(90));

        $this->assertDatabaseHas('cache_locks', ['key' => 'telegram-test-lock', 'owner' => 'original-owner', 'expiration' => $expiration + 15]);
    }

    public function test_expired_lease_cannot_be_revived_by_its_original_owner(): void
    {
        $this->freezeTime();
        DB::table('cache_locks')->count();
        $lock = new TelegramDatabaseLock($this->zeroChangedRowsConnection(), 'cache_locks', 'telegram-test-lock', 90, 'original-owner', [0, 100]);
        $this->assertTrue($lock->get());
        $expiration = now()->timestamp + 90;
        $this->travel(90)->seconds();

        $this->assertFalse($lock->refresh(90));
        $this->assertDatabaseHas('cache_locks', ['key' => 'telegram-test-lock', 'owner' => 'original-owner', 'expiration' => $expiration]);
    }

    public function test_stolen_lease_is_not_renewed_or_released_by_the_previous_owner(): void
    {
        $this->freezeTime();
        DB::table('cache_locks')->count();
        $lock = new TelegramDatabaseLock($this->zeroChangedRowsConnection(), 'cache_locks', 'telegram-test-lock', 90, 'original-owner', [0, 100]);
        $this->assertTrue($lock->get());
        DB::table('cache_locks')->where('key', 'telegram-test-lock')->update(['owner' => 'replacement-owner']);

        $this->assertFalse($lock->refresh(90));
        $this->assertFalse($lock->release());
        $this->assertDatabaseHas('cache_locks', ['key' => 'telegram-test-lock', 'owner' => 'replacement-owner', 'expiration' => now()->timestamp + 90]);
    }

    public function test_transactional_refresh_rechecks_current_ownership_after_an_earlier_read(): void
    {
        $this->freezeTime();
        DB::table('cache_locks')->count();
        $lock = new TelegramDatabaseLock(DB::connection(), 'cache_locks', 'telegram-test-lock', 90, 'original-owner', [0, 100]);
        $this->assertTrue($lock->get());

        DB::transaction(function () use ($lock): void {
            DB::table('cache_locks')->where('key', 'telegram-test-lock')->first();
            DB::table('cache_locks')->where('key', 'telegram-test-lock')->update(['owner' => 'replacement-owner']);

            $this->assertFalse($lock->refresh(90));
        });

        $this->assertDatabaseHas('cache_locks', ['key' => 'telegram-test-lock', 'owner' => 'replacement-owner']);
    }

    private function zeroChangedRowsConnection(): SQLiteConnection
    {
        $original = DB::connection();

        return new class($original->getPdo(), $original->getDatabaseName(), $original->getTablePrefix(), $original->getConfig(), $original->transactionLevel()) extends SQLiteConnection
        {
            public function __construct($pdo, string $database, string $prefix, array $config, int $transactionLevel)
            {
                parent::__construct($pdo, $database, $prefix, $config);
                $this->transactions = $transactionLevel;
            }

            public function affectingStatement($query, $bindings = []): int
            {
                parent::affectingStatement($query, $bindings);

                return 0;
            }
        };
    }
}
