<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('source_report_id')->nullable()->constrained('reports')->restrictOnDelete();
            $table->uuid('report_submission_key')->nullable();
            $table->string('report_request_hash', 64)->nullable();
            $table->unique(['source_report_id', 'created_by', 'report_submission_key'], 'tasks_report_creation_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropForeign(['source_report_id']);
            $table->dropUnique('tasks_report_creation_unique');
            $table->dropColumn(['source_report_id', 'report_submission_key', 'report_request_hash']);
        });
    }
};
