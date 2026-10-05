<?php

namespace Tests\Feature;

use App\Enums\MeetingResolutionType;
use App\Enums\MeetingStatus;
use App\Models\AccessRole;
use App\Models\MediaFile;
use App\Models\Meeting;
use App\Models\Permission;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $recipient;

    private User $cc;

    private Meeting $meeting;

    private string $createUrl;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('access:sync');
        $this->admin = User::factory()->admin()->create();
        $this->recipient = User::factory()->create();
        $this->cc = User::factory()->create();
        $this->meeting = Meeting::query()->create([
            'title' => 'جلسه', 'status' => MeetingStatus::Scheduled, 'created_by' => $this->admin->id,
        ]);
        $resolution = $this->meeting->resolutions()->create([
            'title' => 'مصوبه', 'resolution_type' => MeetingResolutionType::Report, 'created_by' => $this->admin->id,
        ]);
        $this->createUrl = "/api/v1/meetings/{$this->meeting->id}/resolutions/{$resolution->id}/report";
        Sanctum::actingAs($this->admin);
    }

    private function createReport(array $extra = []): array
    {
        return $this->postJson($this->createUrl, array_replace([
            'title' => 'گزارش', 'short_description' => 'خلاصه', 'description' => 'شرح',
            'recipient_user_id' => $this->recipient->id, 'cc_user_ids' => [$this->cc->id],
        ], $extra))->assertCreated()->json('data.report');
    }

    private function file(User $owner): MediaFile
    {
        return MediaFile::query()->create([
            'disk' => 'public', 'path' => 'attachments/test.pdf', 'original_name' => 'test.pdf',
            'mime_type' => 'application/pdf', 'category' => 'attachment', 'size' => 123,
            'uploaded_by' => $owner->id,
        ]);
    }

    public function test_standalone_report_workflow_and_access(): void
    {
        $creator = User::factory()->create();
        Sanctum::actingAs($creator);
        $report = $this->postJson('/api/v1/reports', [
            'title' => 'گزارش مستقل', 'short_description' => 'خلاصه', 'description' => 'شرح',
            'recipient_user_id' => $this->recipient->id, 'cc_user_ids' => [$this->cc->id],
            'status' => 'draft', 'tags' => ['مستقل'],
        ])->assertCreated()->assertJsonPath('data.report.meeting', null)
            ->assertJsonPath('data.report.resolution', null)
            ->assertJsonPath('data.report.meeting_resolution_id', null)->json('data.report');
        $id = $report['id'];
        $this->assertDatabaseHas('reports', ['id' => $id, 'meeting_resolution_id' => null, 'created_by' => $creator->id]);
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->patchJson("/api/v1/reports/$id", ['title' => 'ویرایش مستقل'])->assertOk();
        $this->postJson("/api/v1/reports/$id/send")->assertOk()->assertJsonPath('data.report.status', 'sent');
        Sanctum::actingAs($this->recipient);
        $this->postJson("/api/v1/reports/$id/view")->assertOk()->assertJsonPath('data.report.status', 'viewed');
        $this->patchJson("/api/v1/reports/$id", ['title' => 'غیرمجاز'])->assertForbidden();
        $this->deleteJson("/api/v1/reports/$id")->assertForbidden();
        Sanctum::actingAs($this->cc);
        $this->getJson("/api/v1/reports/$id")->assertOk();
        $this->postJson("/api/v1/reports/$id/comments", ['body' => 'دیدگاه'])->assertCreated();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->getJson("/api/v1/reports/$id")->assertForbidden();
        $this->patchJson("/api/v1/reports/$id", ['title' => 'غیرمجاز'])->assertForbidden();
        Sanctum::actingAs($creator);
        $this->deleteJson("/api/v1/reports/$id")->assertOk();
        $this->assertDatabaseMissing('reports', ['id' => $id]);
    }

    public function test_standalone_report_requires_management_permission_and_active_recipient(): void
    {
        $this->recipient->update(['is_active' => false]);
        $payload = ['title' => 'گزارش', 'short_description' => 'خلاصه', 'description' => 'شرح', 'recipient_user_id' => $this->recipient->id];
        $this->postJson('/api/v1/reports', $payload)->assertUnprocessable()->assertJsonValidationErrors('recipient_user_id');
        $role = AccessRole::create(['slug' => 'reports-view-only-test', 'name' => 'Viewer']);
        $role->permissions()->attach(Permission::where('name', 'reports:view')->firstOrFail());
        $viewer = User::factory()->create();
        $viewer->accessRoles()->sync([$role->id]);
        Sanctum::actingAs($viewer);
        $this->postJson('/api/v1/reports', $payload)->assertForbidden();
    }

    public function test_multiple_standalone_reports_preserve_attachments_and_resolution_uniqueness(): void
    {
        $file = $this->file($this->admin);
        $payload = [
            'title' => 'گزارش مستقل', 'short_description' => 'خلاصه', 'description' => 'شرح',
            'recipient_user_id' => $this->recipient->id, 'attachment_file_ids' => [$file->id],
        ];
        foreach ([1, 2] as $number) {
            $this->postJson('/api/v1/reports', $payload)->assertCreated()
                ->assertJsonPath('data.report.status', 'sent')
                ->assertJsonPath('data.report.attachments.0.id', $file->id);
        }
        $this->createReport();
        $this->postJson($this->createUrl, $payload)->assertConflict();
        $migration = require database_path('migrations/2026_10_05_000000_allow_standalone_reports.php');
        try {
            $migration->down();
            $this->fail('Rollback must preserve standalone reports.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Cannot roll back while standalone reports exist.', $exception->getMessage());
        }
        $this->assertDatabaseCount('reports', 3);
    }

    public function test_workflow_and_read_only_viewing_after_meeting_ends(): void
    {
        $report = $this->createReport(['status' => 'draft']);
        $id = $report['id'];
        $this->assertSame('draft', $report['status']);
        $this->assertNull($report['sent_at']);
        $this->assertSame('RPT-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT), $report['report_number']);
        $this->postJson("/api/v1/reports/$id/view")->assertOk()->assertJsonPath('data.report.status', 'draft');
        $this->meeting->update(['status' => MeetingStatus::Completed]);
        $this->patchJson("/api/v1/reports/$id", ['title' => 'تغییر'])->assertConflict();
        $this->deleteJson("/api/v1/reports/$id")->assertConflict();
        $sent = $this->postJson("/api/v1/reports/$id/send")->assertOk()->assertJsonPath('data.report.status', 'sent')->json('data.report.sent_at');
        $this->assertNotNull($sent);
        $this->postJson("/api/v1/reports/$id/send")->assertConflict();
        Sanctum::actingAs($this->cc);
        $this->postJson("/api/v1/reports/$id/view")->assertOk()->assertJsonPath('data.report.status', 'sent')->assertJsonPath('data.report.viewed_at', null);
        Sanctum::actingAs($this->recipient);
        $this->getJson("/api/v1/reports/$id")->assertOk()->assertJsonPath('data.report.status', 'sent');
        $viewed = $this->postJson("/api/v1/reports/$id/view")->assertOk()->assertJsonPath('data.report.status', 'viewed')->json('data.report.viewed_at');
        $this->travel(5)->minutes();
        $this->postJson("/api/v1/reports/$id/view")->assertOk()->assertJsonPath('data.report.viewed_at', $viewed)->assertJsonPath('data.report.sent_at', $sent);
        $this->postJson("/api/v1/reports/$id/send")->assertForbidden();
    }

    public function test_filters_and_server_owned_fields_and_recipient_cc_overlap(): void
    {
        $report = $this->createReport();
        $id = $report['id'];
        $this->assertSame('sent', $report['status']);
        $this->getJson('/api/v1/reports?status=sent&creator_user_id='.$this->admin->id.'&search='.$report['report_number'])
            ->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.items.0.report_number', $report['report_number']);
        $this->getJson('/api/v1/reports?status=draft')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->getJson('/api/v1/reports?status=inactive')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->getJson('/api/v1/reports?status=bad')->assertUnprocessable();
        foreach (['status' => 'draft', 'report_number' => 'RPT-999999', 'sent_at' => now()->toISOString(), 'viewed_at' => now()->toISOString()] as $field => $value) {
            $this->patchJson("/api/v1/reports/$id", [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->patchJson("/api/v1/reports/$id", ['recipient_user_id' => $this->cc->id])->assertUnprocessable();
        $this->assertDatabaseHas('reports', ['id' => $id, 'recipient_user_id' => $this->recipient->id]);
        $this->patchJson("/api/v1/reports/$id", ['recipient_user_id' => $this->cc->id, 'cc_user_ids' => []])->assertOk();
    }

    public function test_attachments_sync_validation_and_shared_file_lifecycle(): void
    {
        $file = $this->file($this->admin);
        $second = $this->file($this->admin);
        $id = $this->createReport(['attachment_file_ids' => [$file->id]])['id'];
        $this->patchJson("/api/v1/reports/$id", ['title' => 'ویرایش'])->assertOk()->assertJsonPath('data.report.attachments.0.original_name', 'test.pdf');
        $this->deleteJson("/api/v1/uploads/files/{$file->id}")->assertConflict();
        $this->patchJson("/api/v1/reports/$id", ['attachment_file_ids' => [$second->id]])->assertOk()->assertJsonPath('data.report.attachments.0.id', $second->id)->assertJsonCount(1, 'data.report.attachments');
        $this->patchJson("/api/v1/reports/$id", ['attachment_file_ids' => [$file->id, $file->id]])->assertUnprocessable();
        $this->patchJson("/api/v1/reports/$id", ['attachment_file_ids' => [99999]])->assertUnprocessable();
        $this->patchJson("/api/v1/reports/$id", ['attachment_file_ids' => []])->assertOk()->assertJsonCount(0, 'data.report.attachments');
        $this->patchJson("/api/v1/reports/$id", ['attachment_file_ids' => [$file->id]])->assertOk();
        $comment = $this->postJson("/api/v1/reports/$id/comments", ['body' => 'والد'])->assertCreated()->json('data.comment.id');
        $this->postJson("/api/v1/reports/$id/comments", ['body' => 'پاسخ', 'parent_id' => $comment])->assertCreated();
        $this->deleteJson("/api/v1/reports/$id")->assertOk();
        $this->assertDatabaseMissing('task_comments', ['report_id' => $id]);
        $this->assertDatabaseMissing('media_file_report', ['report_id' => $id]);
        $this->assertDatabaseHas('media_files', ['id' => $file->id]);
    }

    public function test_file_ownership_and_report_access_are_enforced(): void
    {
        $own = $this->file($this->admin);
        $foreign = $this->file($this->recipient);
        // A regular meeting manager with reports:manage is not a super admin.
        $manager = User::factory()->create();
        $permission = Permission::where('name', 'reports:manage')->firstOrFail();
        $role = AccessRole::create(['slug' => 'report-manager-test', 'name' => 'Report manager']);
        $role->permissions()->attach($permission);
        $manager->accessRoles()->attach($role);
        $this->meeting->update(['secretary_user_id' => $manager->id]);
        $id = $this->createReport(['attachment_file_ids' => [$own->id]])['id'];
        Sanctum::actingAs($manager);
        $this->patchJson("/api/v1/reports/$id", ['attachment_file_ids' => [$own->id]])->assertOk();
        $this->patchJson("/api/v1/reports/$id", ['attachment_file_ids' => [$foreign->id]])->assertForbidden();
        Sanctum::actingAs(User::factory()->create());
        foreach (['view', 'send', 'comments'] as $path) {
            $this->postJson("/api/v1/reports/$id/$path", ['body' => 'دیدگاه'])->assertForbidden();
        }
        $this->getJson("/api/v1/reports/$id/comments")->assertForbidden();
    }

    public function test_comments_replies_ownership_scope_and_soft_deletion(): void
    {
        $id = $this->createReport()['id'];
        $this->meeting->update(['status' => MeetingStatus::Completed]);
        Sanctum::actingAs($this->recipient);
        $url = "/api/v1/reports/$id/comments";
        $this->postJson($url, ['body' => ' '])->assertUnprocessable();
        $parent = $this->postJson($url, ['body' => 'دیدگاه'])->assertCreated()->assertJsonPath('data.comment.user.id', $this->recipient->id)->json('data.comment.id');
        $reply = $this->postJson($url, ['body' => 'پاسخ', 'parent_id' => $parent])->assertCreated()->json('data.comment.id');
        $this->postJson($url, ['body' => 'پاسخ دوم', 'parent_id' => $reply])->assertCreated();
        $this->getJson($url.'?per_page=2')->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.meta.total', 3);
        $this->patchJson("$url/$parent", ['body' => 'ویرایش'])->assertOk();
        Sanctum::actingAs($this->cc);
        $this->patchJson("$url/$parent", ['body' => 'غیرمجاز'])->assertForbidden();
        $this->deleteJson("$url/$parent")->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->patchJson("$url/$parent", ['body' => 'ویرایش مدیر'])->assertOk();
        $this->deleteJson("$url/$parent")->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.items.0.body', null)->assertJsonPath('data.items.0.is_deleted', true)->assertJsonPath('data.items.1.parent_id', $parent);
        $this->postJson($url, ['body' => 'پاسخ حذف شده', 'parent_id' => $parent])->assertUnprocessable();
        $resolution = $this->meeting->resolutions()->create(['title' => 'دیگر', 'resolution_type' => MeetingResolutionType::Report, 'created_by' => $this->admin->id]);
        $other = $resolution->report()->create(['title' => 'دیگر', 'short_description' => 'خلاصه', 'description' => 'شرح', 'recipient_user_id' => $this->recipient->id, 'created_by' => $this->admin->id]);
        $this->postJson("/api/v1/reports/{$other->id}/comments", ['body' => 'اشتباه', 'parent_id' => $reply])->assertUnprocessable();
        $this->patchJson("/api/v1/reports/{$other->id}/comments/$reply", ['body' => 'اشتباه'])->assertNotFound();
        $this->deleteJson("/api/v1/reports/{$other->id}/comments/$reply")->assertNotFound();
    }

    public function test_deleting_resolution_cleans_up_report_threads(): void
    {
        $report = $this->createReport();
        $url = "/api/v1/reports/{$report['id']}/comments";
        $parent = $this->postJson($url, ['body' => 'والد'])->assertCreated()->json('data.comment.id');
        $this->postJson($url, ['body' => 'پاسخ', 'parent_id' => $parent])->assertCreated();
        $this->deleteJson($url.'/'.$parent)->assertOk();
        $this->deleteJson("/api/v1/meetings/{$this->meeting->id}/resolutions/{$report['meeting_resolution_id']}")->assertOk();
        $this->assertDatabaseMissing('reports', ['id' => $report['id']]);
        $this->assertDatabaseMissing('task_comments', ['report_id' => $report['id']]);
    }

    public function test_migration_backfills_existing_reports_and_can_roll_back_threads(): void
    {
        $report = $this->createReport();
        $url = "/api/v1/reports/{$report['id']}/comments";
        $parent = $this->postJson($url, ['body' => 'والد'])->assertCreated()->json('data.comment.id');
        $this->postJson($url, ['body' => 'پاسخ', 'parent_id' => $parent])->assertCreated();
        $migration = require database_path('migrations/2026_10_03_000000_extend_reports_workflow.php');
        $migration->down();
        $this->assertDatabaseMissing('task_comments', ['id' => $parent]);
        $migration->up();
        $this->getJson("/api/v1/reports/{$report['id']}")->assertOk()
            ->assertJsonPath('data.report.report_number', $report['report_number'])
            ->assertJsonPath('data.report.status', 'sent')
            ->assertJsonPath('data.report.sent_at', $report['created_at'])
            ->assertJsonPath('data.report.viewed_at', null);
    }

    public function test_report_number_is_immutable_at_model_level(): void
    {
        $id = $this->createReport()['id'];
        $report = Report::findOrFail($id);
        $report->report_number = 'RPT-999999';
        $this->expectException(\LogicException::class);
        $report->save();
    }
}
