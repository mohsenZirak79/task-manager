<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completion_requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn([
                'registered_at',
                'started_at',
                'completion_requested_at',
                'completed_at',
            ]);
        });
    }
};
