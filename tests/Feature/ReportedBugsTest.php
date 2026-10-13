<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\MediaFile;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportedBugsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_b_can_edit_approved_request_planning_and_progress(): void
    {
        [$a, $b] = User::factory()->count(2)->create()->all();
        $task = Task::create([
            'title' => 'Approved request', 'short_description' => 'Summary',
            'submission_type' => 'request', 'status' => 'ready_to_start',
            'created_by' => $a->id, 'requester_id' => $a->id,
        ]);
        $task->participantRecords()->create(['user_id' => $b->id, 'role' => 'assignee']);
        Sanctum::actingAs($a);
        $this->patchJson("/api/v1/tasks/{$task->id}/planning", ['planning_items' => []])->assertForbidden();
        Sanctum::actingAs($b);
        $itemId = $this->patchJson("/api/v1/tasks/{$task->id}/planning", [
            'planning_items' => [['title' => 'Work', 'weight' => 100]],
        ])->assertOk()->assertJsonPath('data.task.allowed_actions.update_planning', true)
            ->assertJsonPath('data.task.allowed_actions.update_planning_progress', true)
            ->json('data.task.planning_items.0.id');
        $this->patchJson("/api/v1/tasks/{$task->id}/planning-items/{$itemId}", [
            'progress_percentage' => 25,
        ])->assertOk()->assertJsonPath('data.task.progress_percentage', 25);
        Sanctum::actingAs($a);
        $this->patchJson("/api/v1/tasks/{$task->id}/planning-items/{$itemId}", [
            'progress_percentage' => 50,
        ])->assertForbidden();
    }

    public function test_report_retry_returns_same_record_and_new_intent_creates_another(): void
    {
        $actor = User::factory()->admin()->create();
        $recipient = User::factory()->create();
        Sanctum::actingAs($actor);
        $payload = [
            'title' => 'Report', 'short_description' => 'Summary', 'description' => 'Details',
            'recipient_user_id' => $recipient->id,
            'submission_key' => 'c3bd77dc-f19c-4ce5-a80a-1ff1dca172d0',
        ];
        $id = $this->postJson('/api/v1/reports', $payload)->assertCreated()->json('data.report.id');
        $this->postJson('/api/v1/reports', $payload)->assertCreated()->assertJsonPath('data.report.id', $id);
        $this->assertDatabaseCount('reports', 1);
        $this->postJson('/api/v1/reports', [...$payload, 'submission_key' => 'c3bd77dc-f19c-4ce5-a80a-1ff1dca172d1'])->assertCreated();
        $this->assertDatabaseCount('reports', 2);
    }

    public function test_batch_upload_validates_every_file_and_returns_public_urls(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create());
        $this->post('/api/v1/uploads/files', ['files' => [
            UploadedFile::fake()->image('first.png'), UploadedFile::fake()->image('second.png'),
        ]], ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(2, 'data.files')
            ->assertJsonPath('data.files.0.original_name', 'first.png');
        $this->assertDatabaseCount('media_files', 2);
        $stored = MediaFile::query()->firstOrFail();
        $this->assertStringStartsWith('/storage/', $stored->url);
        Storage::disk('public')->assertExists($stored->path);
        $this->post('/api/v1/uploads/files', ['files' => [
            UploadedFile::fake()->image('ok.png'), UploadedFile::fake()->create('bad.exe'),
        ]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('media_files', 2);
    }

    public function test_members_acknowledge_before_start_and_only_creator_or_secretary_start(): void
    {
        [$creator, $secretary, $member, $outsider] = User::factory()->count(4)->create()->all();
        $meeting = Meeting::create([
            'title' => 'Meeting', 'status' => 'scheduled', 'created_by' => $creator->id,
            'secretary_user_id' => $secretary->id, 'chairman_user_id' => $member->id,
            'meeting_date' => now()->addDay()->toDateString(), 'start_time' => '10:00',
        ]);
        $meeting->attendees()->attach([$creator->id, $secretary->id, $member->id]);
        Sanctum::actingAs($outsider);
        $this->postJson("/api/v1/meetings/{$meeting->id}/view")->assertForbidden();
        Sanctum::actingAs($member);
        $this->postJson("/api/v1/meetings/{$meeting->id}/start")->assertForbidden();
        $this->postJson("/api/v1/meetings/{$meeting->id}/view")->assertOk();
        $this->postJson("/api/v1/meetings/{$meeting->id}/view")->assertOk();
        $this->assertDatabaseCount('meeting_acknowledgements', 1);
        Sanctum::actingAs($secretary);
        $this->postJson("/api/v1/meetings/{$meeting->id}/start")->assertOk()
            ->assertJsonPath('data.meeting.allowed_actions.edit', false)
            ->assertJsonPath('data.meeting.allowed_actions.start', false);
        Sanctum::actingAs($member);
        $this->postJson("/api/v1/meetings/{$meeting->id}/view")->assertForbidden();
        $nextMeeting = $meeting->replicate();
        $nextMeeting->started_at = null;
        $nextMeeting->save();
        Sanctum::actingAs($creator);
        $this->postJson("/api/v1/meetings/{$nextMeeting->id}/start")->assertOk();
    }
}
