<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Report;
use App\Models\Task;
use App\Models\User;
use App\Services\RecordNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecordNumbersTest extends TestCase
{
    use RefreshDatabase;

    public function test_persian_dates_and_tehran_midnight(): void
    {
        $numbers = app(RecordNumberService::class);
        foreach (['2026-11-19 10:00:00+00:00' => '050828', '2026-08-19 10:00:00+00:00' => '050528', '2026-03-21 10:00:00+00:00' => '050101', '2026-03-20 10:00:00+00:00' => '041229', '2026-11-19 20:31:00+00:00' => '050829'] as $date => $expected) {
            $this->assertSame($expected, $numbers->dateKey($date));
        }
    }

    public function test_records_receive_stable_numbers_with_shared_task_request_counter_and_daily_reset(): void
    {
        $this->travelTo(now()->setDate(2026, 8, 19)->setTime(10, 0));
        $user = User::factory()->create();
        $task = Task::create(['title' => 'Task', 'created_by' => $user->id, 'requester_id' => $user->id, 'submission_type' => 'assignment', 'status' => 'draft']);
        $request = Task::create(['title' => 'Request', 'created_by' => $user->id, 'requester_id' => $user->id, 'submission_type' => 'request', 'status' => 'draft']);
        $report = Report::create(['title' => 'Report', 'short_description' => 'Summary', 'description' => 'Details', 'recipient_user_id' => $user->id, 'created_by' => $user->id, 'status' => 'draft']);
        $meeting = Meeting::create(['title' => 'Meeting', 'created_by' => $user->id, 'status' => 'draft']);
        $this->assertSame('rqst050528001', $task->task_number);
        $this->assertSame('rqst050528002', $request->task_number);
        $this->assertSame('rpt050528001', $report->report_number);
        $this->assertSame('mtg050528001', $meeting->meeting_number);
        $task->update(['title' => 'Changed']);
        $this->assertSame('rqst050528001', $task->fresh()->task_number);
        $this->travel(1)->days();
        $this->assertSame('rqst050529001', app(RecordNumberService::class)->next('rqst', now()));
        Sanctum::actingAs($user);
        $this->getJson("/api/v1/tasks/{$task->id}")->assertOk()->assertJsonPath('data.task.task_number', 'rqst050528001');
        $this->getJson("/api/v1/meetings/{$meeting->id}")->assertOk()->assertJsonPath('data.meeting.meeting_number', 'mtg050528001');
        $task->task_number = 'rqst050528999';
        $this->expectException(\LogicException::class);
        $task->save();
    }

    public function test_counter_rolls_back_with_failed_transaction_and_does_not_truncate_after_999(): void
    {
        $numbers = app(RecordNumberService::class);
        DB::beginTransaction();
        $this->assertSame('rpt050828001', $numbers->next('rpt', '2026-11-19 10:00:00'));
        DB::rollBack();
        $this->assertSame('rpt050828001', $numbers->next('rpt', '2026-11-19 10:00:00'));
        DB::table('record_number_counters')->where('key', 'rpt050828')->update(['sequence' => 999]);
        $this->assertSame('rpt0508281000', $numbers->next('rpt', '2026-11-19 10:00:00'));
    }

    public function test_backfill_keeps_dates_and_archived_meetings_and_continues_sequence(): void
    {
        $migration = require database_path('migrations/2026_11_19_000000_add_jalali_record_numbers.php');
        $migration->down();
        $user = User::factory()->create();
        $timestamp = '2026-08-19 10:00:00';
        $id = DB::table('meetings')->insertGetId(['title' => 'Archived', 'created_by' => $user->id, 'status' => 'draft', 'created_at' => $timestamp, 'updated_at' => $timestamp, 'deleted_at' => $timestamp]);
        $reportId = DB::table('reports')->insertGetId(['title' => 'Legacy', 'short_description' => 'Summary', 'description' => 'Details', 'recipient_user_id' => $user->id, 'created_by' => $user->id, 'status' => 'draft', 'report_number' => 'RPT-000001', 'created_at' => $timestamp, 'updated_at' => $timestamp]);
        $migration->up();
        $meeting = DB::table('meetings')->find($id);
        $this->assertSame('mtg050528001', $meeting->meeting_number);
        $this->assertSame($timestamp, $meeting->created_at);
        $this->assertSame($timestamp, $meeting->updated_at);
        $this->assertSame('rpt050528001', DB::table('reports')->find($reportId)->report_number);
        $this->assertSame('RPT-000001', DB::table('reports')->find($reportId)->legacy_report_number);
        $this->assertSame('mtg050528002', app(RecordNumberService::class)->next('mtg', $timestamp));
    }

    public function test_meeting_list_returns_all_of_current_users_roles_without_granting_unrelated_roles(): void
    {
        [$actor, $secretary] = User::factory()->count(2)->create()->all();
        $meeting = Meeting::create(['title' => 'Meeting', 'status' => 'draft', 'created_by' => $actor->id, 'chairman_user_id' => $actor->id, 'secretary_user_id' => $secretary->id]);
        $meeting->attendees()->attach([$actor->id, $secretary->id]);
        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/meetings')->assertOk()->assertJsonPath('data.items.0.current_user_roles', ['creator', 'chairman', 'attendee']);
        Sanctum::actingAs($secretary);
        $this->getJson('/api/v1/meetings')->assertOk()->assertJsonPath('data.items.0.current_user_roles', ['secretary', 'attendee']);
    }
}
