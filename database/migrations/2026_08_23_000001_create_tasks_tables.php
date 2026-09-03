<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('short_description', 100);
            $table->text('request_description')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedTinyInteger('progress_percentage')->default(0);
            $table->string('status', 30)->index();
            $table->string('submission_type', 20)->nullable()->index();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('financial_resources')->nullable();
            $table->decimal('financial_estimated_cost', 18, 2)->nullable();
            $table->foreignId('financial_provider_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('equipment_resources')->nullable();
            $table->decimal('equipment_estimated_cost', 18, 2)->nullable();
            $table->foreignId('equipment_provider_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'status']);
            $table->index(['requester_id', 'created_at']);
            $table->index(['created_by', 'created_at']);
        });

        Schema::create('task_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 20);
            $table->timestamps();

            $table->unique(['task_id', 'user_id', 'role']);
            $table->index(['user_id', 'role']);
        });

        Schema::create('task_workflow_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->unsignedTinyInteger('old_progress')->nullable();
            $table->unsignedTinyInteger('new_progress')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_workflow_histories');
        Schema::dropIfExists('task_participants');
        Schema::dropIfExists('tasks');
    }
};
