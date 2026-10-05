<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->string('report_number')->nullable()->unique();
            $table->string('status', 20)->default('sent')->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
        });
        DB::table('reports')->orderBy('id')->chunkById(500, function ($reports): void {
            foreach ($reports as $report) {
                DB::table('reports')->where('id', $report->id)->update([
                    'report_number' => 'RPT-'.str_pad((string) $report->id, 6, '0', STR_PAD_LEFT),
                    'sent_at' => $report->created_at,
                ]);
            }
        });
        Schema::create('media_file_report', function (Blueprint $table): void {
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_file_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['report_id', 'media_file_id']);
        });
        // Reuse the existing threaded comment and soft-deletion infrastructure.
        Schema::table('task_comments', function (Blueprint $table): void {
            $table->unsignedBigInteger('task_id')->nullable()->change();
            $table->foreignId('report_id')->nullable()->constrained()->cascadeOnDelete();
            $table->index(['report_id', 'created_at']);
        });
    }

    public function down(): void
    {
        DB::table('task_comments')->whereNotNull('report_id')->update(['parent_id' => null]);
        DB::table('task_comments')->whereNotNull('report_id')->delete();
        Schema::table('task_comments', function (Blueprint $table): void {
            $table->dropIndex(['report_id', 'created_at']);
            $table->dropConstrainedForeignId('report_id');
            $table->unsignedBigInteger('task_id')->nullable(false)->change();
        });
        Schema::dropIfExists('media_file_report');
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropUnique(['report_number']);
            $table->dropIndex(['status']);
            $table->dropColumn(['report_number', 'status', 'sent_at', 'viewed_at']);
        });
    }
};
