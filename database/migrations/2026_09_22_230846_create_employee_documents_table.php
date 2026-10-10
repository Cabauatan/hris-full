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
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('document_type', 100);
            $table->string('title', 200);

            $table->string('file_path');
            $table->string('original_filename');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();

            $table->text('remarks')->nullable();

            $table->foreignId('uploaded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(
                ['employee_id', 'document_type'],
                'idx_employee_documents_employee_type'
            );

            $table->index(
                ['expires_at'],
                'idx_employee_documents_expires'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
