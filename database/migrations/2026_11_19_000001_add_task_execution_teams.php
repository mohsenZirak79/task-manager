<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_team_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'user_id']);
        });
        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('transferred_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('transferred_at')->nullable();
            $table->timestamp('assignee_viewed_at')->nullable();
        });
        Schema::table('task_planning_items', fn (Blueprint $table) => $table->foreignId('assignee_user_id')->nullable()->constrained('users')->restrictOnDelete());
    }

    public function down(): void
    {
        Schema::table('task_planning_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('assignee_user_id'));
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('transferred_by_user_id');
            $table->dropColumn(['transferred_at', 'assignee_viewed_at']);
        });
        Schema::dropIfExists('task_team_members');
    }
};
