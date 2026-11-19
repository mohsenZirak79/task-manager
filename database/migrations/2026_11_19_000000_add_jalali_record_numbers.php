<?php

use App\Services\RecordNumberService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_number_counters', function (Blueprint $table): void {
            $table->string('key', 20)->primary();
            $table->unsignedInteger('sequence');
        });
        Schema::table('tasks', fn (Blueprint $table) => $table->string('task_number', 30)->nullable()->unique());
        Schema::table('meetings', fn (Blueprint $table) => $table->string('meeting_number', 30)->nullable()->unique());
        Schema::table('reports', fn (Blueprint $table) => $table->string('legacy_report_number')->nullable());
        $numbers = app(RecordNumberService::class);
        foreach (['tasks' => ['task_number', 'rqst'], 'reports' => ['report_number', 'rpt'], 'meetings' => ['meeting_number', 'mtg']] as $table => [$column, $prefix]) {
            DB::table($table)->orderBy('created_at')->orderBy('id')->each(function ($row) use ($numbers, $table, $column, $prefix): void {
                $attributes = [$column => $numbers->next($prefix, $row->created_at ?? now())];
                if ($table === 'reports') {
                    $attributes['legacy_report_number'] = $row->report_number;
                }
                DB::table($table)->where('id', $row->id)->update($attributes);
            });
        }
    }

    public function down(): void
    {
        DB::table('reports')->whereNotNull('legacy_report_number')->update(['report_number' => DB::raw('legacy_report_number')]);
        Schema::table('reports', fn (Blueprint $table) => $table->dropColumn('legacy_report_number'));
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropUnique(['task_number']);
            $table->dropColumn('task_number');
        });
        Schema::table('meetings', function (Blueprint $table): void {
            $table->dropUnique(['meeting_number']);
            $table->dropColumn('meeting_number');
        });
        Schema::dropIfExists('record_number_counters');
    }
};
