<?php

use App\Models\Meeting;
use App\Models\MeetingResolution;
use App\Services\MeetingSignatureContent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_resolutions', function (Blueprint $table): void {
            $table->json('execution_plan')->nullable();
            $table->timestamp('materialized_at')->nullable();
        });
        Schema::table('meetings', fn (Blueprint $table) => $table->string('signature_content_version', 64)->nullable());
        // Derived version only: do not change legacy content, dates or signatures.
        Meeting::query()->with(['attendees', 'agendaItems', 'resolutions.report'])->chunkById(100, function ($meetings): void {
            $content = app(MeetingSignatureContent::class);
            foreach ($meetings as $meeting) {
                DB::table('meetings')->where('id', $meeting->id)->update(['signature_content_version' => $content->version($content->snapshot($meeting))]);
            }
        });
    }

    public function down(): void
    {
        if (MeetingResolution::query()->whereNotNull('execution_plan')->exists()) {
            throw new RuntimeException('Cannot roll back while resolution execution plans exist.');
        }

        Schema::table('meetings', fn (Blueprint $table) => $table->dropColumn('signature_content_version'));
        Schema::table('meeting_resolutions', fn (Blueprint $table) => $table->dropColumn(['execution_plan', 'materialized_at']));
    }
};
