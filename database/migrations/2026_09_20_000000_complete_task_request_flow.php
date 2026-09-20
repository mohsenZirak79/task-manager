<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('title')->nullable()->change();
            $table->string('short_description', 100)->nullable()->change();
        });

        Schema::table('media_files', function (Blueprint $table): void {
            $table->string('category', 30)->default('image')->after('mime_type')->index();
        });

        Schema::create('task_planning_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->decimal('weight', 8, 2);
            $table->unsignedTinyInteger('progress_percentage')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['task_id', 'sort_order']);
        });

        Schema::create('media_file_task', function (Blueprint $table): void {
            $table->foreignId('media_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['media_file_id', 'task_id']);
            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_file_task');
        Schema::dropIfExists('task_planning_items');

        Schema::table('media_files', function (Blueprint $table): void {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });

        DB::table('tasks')->whereNull('title')->update(['title' => '']);
        DB::table('tasks')->whereNull('short_description')->update(['short_description' => '']);
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('title')->nullable(false)->change();
            $table->string('short_description', 100)->nullable(false)->change();
        });
    }
};
