<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReportTaskMigrationTest extends TestCase
{
    // MySQL DDL commits transactions, so do not use RefreshDatabase here.
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        // Force the next test to migrate fresh; do not run unrelated legacy downs.
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_source_migration_can_roll_back_and_reapply_without_deleting_business_records(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28T09:00:00Z'));
        $user = User::factory()->create();
        $report = Report::create([
            'title' => 'گزارش', 'short_description' => 'خلاصه', 'description' => 'شرح',
            'created_by' => $user->id, 'recipient_user_id' => $user->id, 'status' => 'viewed',
        ]);
        $task = Task::create([
            'title' => 'تسک', 'status' => 'draft', 'submission_type' => 'assignment',
            'created_by' => $user->id, 'requester_id' => $user->id,
        ]);
        $task->forceFill(['source_report_id' => $report->id])->save();
        $migration = require database_path('migrations/2026_10_28_000000_add_report_source_to_tasks_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('tasks', 'source_report_id'));
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'title' => 'تسک']);
        $this->assertDatabaseHas('reports', ['id' => $report->id]);
        $migration->up();
        $this->assertTrue(Schema::hasColumn('tasks', 'source_report_id'));
        // Rollback removes provenance, as documented; reapplying never fabricates it.
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'source_report_id' => null]);
    }
}
