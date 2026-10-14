<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_workflow_histories', fn (Blueprint $table) => $table->json('context')->nullable());
    }

    public function down(): void
    {
        Schema::table('task_workflow_histories', fn (Blueprint $table) => $table->dropColumn('context'));
    }
};
