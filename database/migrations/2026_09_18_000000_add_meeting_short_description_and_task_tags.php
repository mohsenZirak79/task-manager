<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table): void {
            $table->string('short_description', 500)->nullable()->after('title');
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 100)->unique();
            $table->timestamps();
        });

        Schema::create('tag_task', function (Blueprint $table): void {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['tag_id', 'task_id']);
            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_task');
        Schema::dropIfExists('tags');

        Schema::table('meetings', function (Blueprint $table): void {
            $table->dropColumn('short_description');
        });
    }
};
