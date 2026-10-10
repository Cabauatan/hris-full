<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['users', 'applicants'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('active_session_token', 64)->nullable();
                $table->timestamp('active_session_last_activity_at')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['users', 'applicants'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['active_session_token', 'active_session_last_activity_at']);
            });
        }
    }
};
