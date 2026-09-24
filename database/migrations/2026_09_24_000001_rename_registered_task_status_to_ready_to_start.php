<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')
            ->where('status', 'registered')
            ->update(['status' => 'ready_to_start']);

        DB::table('task_workflow_histories')
            ->where('from_status', 'registered')
            ->update(['from_status' => 'ready_to_start']);

        DB::table('task_workflow_histories')
            ->where('to_status', 'registered')
            ->update(['to_status' => 'ready_to_start']);
    }

    public function down(): void
    {
        DB::table('tasks')
            ->where('status', 'ready_to_start')
            ->update(['status' => 'registered']);

        DB::table('task_workflow_histories')
            ->where('from_status', 'ready_to_start')
            ->update(['from_status' => 'registered']);

        DB::table('task_workflow_histories')
            ->where('to_status', 'ready_to_start')
            ->update(['to_status' => 'registered']);
    }
};
