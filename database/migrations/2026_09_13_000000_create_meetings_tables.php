<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->string('location')->nullable();
            $table->date('meeting_date')->nullable();
            $table->time('start_time')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('chairman_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('secretary_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'meeting_date']);
            $table->index(['created_by', 'created_at']);
        });

        Schema::create('meeting_attendees', function (Blueprint $table): void {
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['meeting_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('meeting_agenda_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['meeting_id', 'sort_order']);
        });

        Schema::create('meeting_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('agenda_item_id')->nullable()->constrained('meeting_agenda_items')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('task_id')->nullable()->unique()->constrained('tasks')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['meeting_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_resolutions');
        Schema::dropIfExists('meeting_agenda_items');
        Schema::dropIfExists('meeting_attendees');
        Schema::dropIfExists('meetings');
    }
};
