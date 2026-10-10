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
        Schema::create('employee_profile_change_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('employee_profile_change_request_id');

            $table->foreign(
                'employee_profile_change_request_id',
                'fk_profile_change_items_request'
            )->references('id')
                ->on('employee_profile_change_requests')
                ->cascadeOnDelete();

            $table->string('field_name', 100);

            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();

            $table->timestamps();

            $table->unique(
                ['employee_profile_change_request_id', 'field_name'],
                'uq_profile_change_request_field'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profile_change_items');
    }
};
