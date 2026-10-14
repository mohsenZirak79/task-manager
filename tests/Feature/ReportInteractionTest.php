<?php

namespace Tests\Feature;

use App\Enums\MeetingStatus;
use App\Enums\ReportStatus;
use App\Models\AccessRole;
use App\Models\MediaFile;
use App\Models\Meeting;
use App\Models\Permission;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportInteractionTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-14T09:00:00Z'));
        Artisan::call('access:sync');
        $this->creator = User::factory()->create();
        $this->recipient = User::factory()->create();
        Sanctum::actingAs($this->creator);
    }

    private function report(string $status = 'sent'): Report
    {
        return Report::findOrFail($this->postJson('/api/v1/reports', [
            'title' => 'گزارش', 'short_description' => 'خلاصه', 'description' => 'شرح',
            'recipient_user_id' => $this->recipient->id, 'status' => $status,
        ])->assertCreated()->json('data.report.id'));
    }

    private function comment(Report $report): int
    {
        return $this->postJson("/api/v1/reports/{$report->id}/comments", ['body' => 'دیدگاه'])
            ->assertCreated()->assertJsonPath('data.comment.reactions.like', 0)
            ->assertJsonPath('data.comment.allowed_actions.react', true)->json('data.comment.id');
    }

    public function test_report_mentions_persist_validate_access_and_respect_inactive_state(): void
    {
        $report = $this->report();
        $outsider = User::factory()->create();
        $url = "/api/v1/reports/{$report->id}/comments";
        $users = $this->getJson($url.'/mention-users')->assertOk()->json('data.items');
        $this->assertContains($this->recipient->id, array_column($users, 'id'));
        $this->assertNotContains($outsider->id, array_column($users, 'id'));
        $id = $this->postJson($url, ['body' => '@گیرنده', 'mention_user_ids' => [$this->recipient->id]])
            ->assertCreated()->assertJsonPath('data.comment.mentions.0.id', $this->recipient->id)->json('data.comment.id');
        $this->getJson($url)->assertOk()->assertJsonPath('data.items.0.mentions.0.id', $this->recipient->id);
        $this->patchJson($url."/$id", ['body' => 'نباید ذخیره شود', 'mention_user_ids' => [$outsider->id]])->assertUnprocessable();
        $this->assertDatabaseHas('task_comments', ['id' => $id, 'body' => '@گیرنده']);
        $this->patchJson($url."/$id", ['body' => 'ویرایش'])->assertOk()->assertJsonCount(1, 'data.comment.mentions');
        $this->patchJson($url."/$id", ['body' => 'پاک', 'mention_user_ids' => []])->assertOk()->assertJsonCount(0, 'data.comment.mentions');
        Sanctum::actingAs($outsider);
        $this->getJson($url.'/mention-users')->assertForbidden();
        Sanctum::actingAs($this->creator);
        $this->postJson("/api/v1/reports/{$report->id}/deactivate")->assertOk();
        $this->getJson($url.'/mention-users')->assertStatus(409);
        $this->patchJson($url."/$id", ['body' => 'No', 'mention_user_ids' => [$this->recipient->id]])->assertStatus(409);
    }

    public function test_reactions_replace_remove_and_are_idempotent_and_actor_specific(): void
    {
        $report = $this->report();
        $id = $this->comment($report);
        $url = "/api/v1/reports/{$report->id}/comments/$id/reaction";
        foreach (['like', 'like', 'dislike'] as $reaction) {
            $this->putJson($url, ['reaction' => $reaction])->assertOk()
                ->assertJsonPath('data.reactions.my_reaction', $reaction)
                ->assertJsonPath('data.reactions.like', $reaction === 'like' ? 1 : 0)
                ->assertJsonPath('data.reactions.dislike', $reaction === 'dislike' ? 1 : 0);
            $this->assertDatabaseCount('task_comment_reactions', 1);
        }
        Sanctum::actingAs($this->recipient);
        $this->putJson($url, ['reaction' => 'like'])->assertOk()
            ->assertJsonPath('data.reactions.like', 1)->assertJsonPath('data.reactions.dislike', 1);
        $this->getJson("/api/v1/reports/{$report->id}/comments")->assertOk()
            ->assertJsonPath('data.items.0.reactions.my_reaction', 'like')
            ->assertJsonPath('data.items.0.allowed_actions.edit', false)
            ->assertJsonPath('data.items.0.allowed_actions.reply', true);
        Sanctum::actingAs($this->creator);
        $this->patchJson("/api/v1/reports/{$report->id}/comments/$id", ['body' => 'ویرایش'])
            ->assertOk()->assertJsonPath('data.comment.reactions.my_reaction', 'dislike')
            ->assertJsonPath('data.comment.allowed_actions.edit', true);
        $this->putJson($url, ['reaction' => 'like'])->assertOk()->assertJsonPath('data.reactions.like', 2);
        foreach ([1, 2] as $attempt) {
            $this->putJson($url, ['reaction' => null])->assertOk()->assertJsonPath('message', 'واکنش حذف شد.')
                ->assertJsonPath('data.reactions.like', 1)->assertJsonPath('data.reactions.dislike', 0)
                ->assertJsonPath('data.reactions.my_reaction', null);
        }
        $this->assertDatabaseCount('task_comment_reactions', 1);
    }

    public function test_foreign_invisible_deleted_and_invalid_reactions_are_rejected(): void
    {
        $report = $this->report();
        $other = $this->report();
        $id = $this->comment($report);
        $url = "/api/v1/reports/{$report->id}/comments/$id/reaction";
        $this->putJson("/api/v1/reports/{$other->id}/comments/$id/reaction", ['reaction' => 'like'])->assertNotFound();
        $this->putJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('reaction');
        $this->putJson($url, ['reaction' => 'invalid'])->assertUnprocessable();
        Sanctum::actingAs(User::factory()->create());
        $this->putJson($url, ['reaction' => 'like'])->assertForbidden();
        Sanctum::actingAs($this->creator);
        $this->putJson($url, ['reaction' => 'like'])->assertOk();
        $this->deleteJson("/api/v1/reports/{$report->id}/comments/$id")->assertOk();
        $this->putJson($url, ['reaction' => 'like'])->assertNotFound();
        $this->getJson("/api/v1/reports/{$report->id}/comments")->assertOk()
            ->assertJsonPath('data.items.0.body', null)->assertJsonPath('data.items.0.is_deleted', true)
            ->assertJsonPath('data.items.0.reactions.like', 1)
            ->assertJsonPath('data.items.0.allowed_actions', ['edit' => false, 'delete' => false, 'reply' => false, 'react' => false]);
    }

    public function test_reactions_require_report_view_permission_even_for_a_recipient(): void
    {
        $report = $this->report();
        $id = $this->comment($report);
        $role = AccessRole::create(['slug' => 'no-report-view', 'name' => 'No report view']);
        $role->permissions()->attach(Permission::where('name', 'reports:manage')->firstOrFail());
        $this->recipient->accessRoles()->sync([$role->id]);
        Sanctum::actingAs($this->recipient);
        $this->putJson("/api/v1/reports/{$report->id}/comments/$id/reaction", ['reaction' => 'like'])->assertForbidden();
    }

    public function test_standalone_round_trips_preserve_all_three_previous_states_and_timestamps(): void
    {
        foreach (['draft', 'sent', 'viewed'] as $state) {
            $report = $this->report($state === 'draft' ? 'draft' : 'sent');
            if ($state === 'viewed') {
                Sanctum::actingAs($this->recipient);
                $this->postJson("/api/v1/reports/{$report->id}/view")->assertOk();
                Sanctum::actingAs($this->creator);
            }
            $report->refresh();
            $sent = $report->sent_at?->toISOString();
            $viewed = $report->viewed_at?->toISOString();
            $this->postJson("/api/v1/reports/{$report->id}/deactivate")->assertOk()
                ->assertJsonPath('data.report.status', 'inactive')->assertJsonPath('data.report.sent_at', $sent)
                ->assertJsonPath('data.report.viewed_at', $viewed)->assertJsonPath('data.report.allowed_actions.activate', true);
            $this->assertDatabaseHas('reports', ['id' => $report->id, 'previous_status' => $state]);
            $this->postJson("/api/v1/reports/{$report->id}/deactivate")->assertOk();
            $this->postJson("/api/v1/reports/{$report->id}/activate")->assertOk()
                ->assertJsonPath('data.report.status', $state)->assertJsonPath('data.report.sent_at', $sent)
                ->assertJsonPath('data.report.viewed_at', $viewed);
            $this->assertDatabaseHas('reports', ['id' => $report->id, 'previous_status' => null]);
            $this->postJson("/api/v1/reports/{$report->id}/activate")->assertConflict();
        }
    }

    public function test_inactive_reports_are_read_only_preserve_history_and_remain_filterable(): void
    {
        $report = $this->report();
        $id = $this->comment($report);
        $url = "/api/v1/reports/{$report->id}";
        $this->putJson("$url/comments/$id/reaction", ['reaction' => 'like'])->assertOk();
        $file = MediaFile::create(['disk' => 'public', 'path' => 'test.pdf', 'original_name' => 'test.pdf',
            'mime_type' => 'application/pdf', 'category' => 'attachment', 'size' => 10, 'uploaded_by' => $this->creator->id]);
        $this->patchJson($url, ['attachment_file_ids' => [$file->id]])->assertOk();
        $this->postJson("$url/deactivate")->assertOk();
        $this->patchJson($url, ['title' => 'تغییر'])->assertConflict();
        $this->postJson("$url/send")->assertConflict();
        $this->postJson("$url/view")->assertConflict();
        $this->postJson("$url/comments", ['body' => 'جدید'])->assertConflict();
        $this->patchJson("$url/comments/$id", ['body' => 'تغییر'])->assertConflict();
        $this->deleteJson("$url/comments/$id")->assertConflict();
        $this->putJson("$url/comments/$id/reaction", ['reaction' => 'dislike'])->assertConflict();
        $this->putJson("$url/comments/$id/reaction", ['reaction' => null])->assertConflict();
        $this->getJson($url)->assertOk()->assertJsonPath('data.report.title', 'گزارش')
            ->assertJsonPath('data.report.attachments.0.id', $file->id)
            ->assertJsonPath('data.report.allowed_actions.edit', false)
            ->assertJsonPath('data.report.allowed_actions.delete', true)
            ->assertJsonPath('data.report.allowed_actions.comment', false);
        Sanctum::actingAs($this->recipient);
        $this->postJson("$url/view")->assertConflict();
        $this->getJson('/api/v1/reports?status=inactive&search='.$report->report_number)->assertOk()
            ->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.items.0.status', 'inactive')
            ->assertJsonPath('data.items.0.allowed_actions.activate', false);
        $this->getJson("$url/comments")->assertOk()->assertJsonPath('data.items.0.reactions.like', 1)
            ->assertJsonPath('data.items.0.allowed_actions', ['edit' => false, 'delete' => false, 'reply' => false, 'react' => false]);
        Sanctum::actingAs($this->creator);
        $this->postJson("$url/activate")->assertOk();
        $this->getJson("$url/comments")->assertOk()->assertJsonPath('data.items.0.allowed_actions.react', true);
        $this->postJson("$url/deactivate")->assertOk();
        $this->deleteJson($url)->assertOk();
        $this->assertDatabaseHas('media_files', ['id' => $file->id]);
    }

    public function test_unauthorized_users_cannot_deactivate_or_activate(): void
    {
        $report = $this->report();
        foreach ([$this->recipient, User::factory()->create()] as $user) {
            Sanctum::actingAs($user);
            $this->postJson("/api/v1/reports/{$report->id}/deactivate")->assertForbidden();
        }
        Sanctum::actingAs($this->creator);
        $this->postJson("/api/v1/reports/{$report->id}/deactivate")->assertOk();
        Sanctum::actingAs($this->recipient);
        $this->postJson("/api/v1/reports/{$report->id}/activate")->assertForbidden();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/v1/reports/{$report->id}/activate")->assertOk();
    }

    public function test_resolution_management_and_meeting_restrictions_match_allowed_actions(): void
    {
        $meeting = Meeting::create(['title' => 'جلسه', 'status' => 'scheduled', 'created_by' => $this->creator->id]);
        $resolution = $meeting->resolutions()->create(['title' => 'مصوبه', 'resolution_type' => 'report', 'created_by' => $this->creator->id]);
        foreach (['draft', 'sent', 'viewed'] as $state) {
            $report = $resolution->report()->create(['title' => 'گزارش', 'short_description' => 'خلاصه', 'description' => 'شرح',
                'status' => $state, 'recipient_user_id' => $this->recipient->id, 'created_by' => $this->recipient->id]);
            Sanctum::actingAs($this->recipient);
            $this->postJson("/api/v1/reports/{$report->id}/deactivate")->assertForbidden();
            Sanctum::actingAs($this->creator);
            $this->postJson("/api/v1/reports/{$report->id}/deactivate")->assertOk();
            $this->postJson("/api/v1/reports/{$report->id}/activate")->assertOk()->assertJsonPath('data.report.status', $state);
            $report->delete();
        }
        $report = $resolution->report()->create(['title' => 'گزارش', 'short_description' => 'خلاصه', 'description' => 'شرح',
            'status' => 'draft', 'recipient_user_id' => $this->recipient->id, 'created_by' => $this->creator->id]);
        $meeting->update(['status' => MeetingStatus::Completed]);
        $url = "/api/v1/reports/{$report->id}";
        $this->getJson($url)->assertOk()->assertJsonPath('data.report.allowed_actions.edit', false)
            ->assertJsonPath('data.report.allowed_actions.delete', false)->assertJsonPath('data.report.allowed_actions.send', true)
            ->assertJsonPath('data.report.allowed_actions.deactivate', true);
        $this->postJson("$url/deactivate")->assertOk();
        $this->deleteJson($url)->assertConflict();
        $this->postJson("$url/activate")->assertOk()->assertJsonPath('data.report.status', 'draft');
    }

    public function test_admin_comment_actions_preserve_existing_management_policy(): void
    {
        $report = $this->report();
        $id = $this->comment($report);
        $admin = User::factory()->create();
        $admin->accessRoles()->sync([AccessRole::where('slug', 'admin')->firstOrFail()->id]);
        foreach ([$admin, User::factory()->admin()->create()] as $manager) {
            Sanctum::actingAs($manager);
            $this->getJson("/api/v1/reports/{$report->id}/comments")->assertOk()
                ->assertJsonPath('data.items.0.allowed_actions.edit', true)->assertJsonPath('data.items.0.allowed_actions.delete', true);
            $this->patchJson("/api/v1/reports/{$report->id}/comments/$id", ['body' => 'ویرایش مدیر'])->assertOk();
            $this->postJson("/api/v1/reports/{$report->id}/deactivate")->assertOk();
            $this->postJson("/api/v1/reports/{$report->id}/activate")->assertOk();
        }
    }

    public function test_previous_status_is_internal_and_missing_history_cannot_be_client_restored(): void
    {
        $report = $this->report();
        $url = "/api/v1/reports/{$report->id}";
        foreach (['previous_status' => 'viewed', 'status' => 'inactive', 'sent_at' => now()->toISOString(), 'viewed_at' => now()->toISOString(), 'report_number' => 'RPT-123456'] as $field => $value) {
            $this->patchJson($url, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $report->update(['status' => ReportStatus::Inactive, 'previous_status' => null]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.report.allowed_actions.activate', false);
        $this->postJson("$url/activate", ['previous_status' => 'sent'])->assertConflict();
        $this->assertDatabaseHas('reports', ['id' => $report->id, 'status' => 'inactive']);
    }

    public function test_legacy_inactive_report_without_history_cannot_be_activated(): void
    {
        $report = $this->report();
        $report->update(['status' => ReportStatus::Inactive, 'previous_status' => null]);
        $this->getJson("/api/v1/reports/{$report->id}")->assertOk()
            ->assertJsonPath('data.report.allowed_actions.activate', false);
        $this->postJson("/api/v1/reports/{$report->id}/activate")->assertConflict();
        $this->assertDatabaseHas('reports', ['id' => $report->id, 'status' => 'inactive', 'previous_status' => null]);
    }

    public function test_cc_comment_list_has_constant_query_count_and_correct_policy_actions(): void
    {
        $report = $this->report();
        $cc = User::factory()->create();
        $report->ccUsers()->attach($cc);
        for ($i = 0; $i < 12; $i++) {
            $report->comments()->create(['body' => 'متن', 'user_id' => $this->creator->id]);
        }
        Sanctum::actingAs($cc);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $url = "/api/v1/reports/{$report->id}/comments";
        $this->getJson($url.'?per_page=1')->assertOk()
            ->assertJsonPath('data.items.0.allowed_actions', ['edit' => false, 'delete' => false, 'reply' => true, 'react' => true]);
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->getJson($url.'?per_page=12')->assertOk()->assertJsonCount(12, 'data.items');
        $this->assertLessThanOrEqual($one, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_allowed_actions_are_consistent_in_report_create_list_show_and_update(): void
    {
        $report = $this->report('draft');
        $url = "/api/v1/reports/{$report->id}";
        $actions = ['view' => true, 'edit' => true, 'delete' => true, 'send' => true,
            'mark_viewed' => false, 'comment' => true, 'deactivate' => true, 'activate' => false];
        $this->getJson($url)->assertOk()->assertJsonPath('data.report.allowed_actions', $actions);
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('data.items.0.allowed_actions', $actions);
        $this->patchJson($url, ['action_type' => 'needs_review'])->assertOk()
            ->assertJsonPath('data.report.allowed_actions', $actions)->assertJsonPath('data.report.action_type', 'needs_review');
        Sanctum::actingAs($this->recipient);
        $this->getJson($url)->assertOk()->assertJsonPath('data.report.allowed_actions.edit', false)
            ->assertJsonPath('data.report.allowed_actions.mark_viewed', false);
        $this->patchJson($url, ['title' => 'غیرمجاز'])->assertForbidden();
        $this->postJson($url.'/send')->assertForbidden();
        Sanctum::actingAs($this->creator);
        $this->postJson($url.'/send')->assertOk()->assertJsonPath('data.report.allowed_actions.send', false);
        Sanctum::actingAs($this->recipient);
        $this->getJson($url)->assertOk()->assertJsonPath('data.report.allowed_actions.mark_viewed', true);
        $this->postJson($url.'/view')->assertOk()->assertJsonPath('data.report.allowed_actions.mark_viewed', false);
    }

    public function test_migration_does_not_infer_previous_state_and_refuses_lossy_rollback(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('DDL rollback case uses isolated SQLite; MySQL DDL commits enclosing transactions.');
        }
        $migration = require database_path('migrations/2026_10_14_000002_add_previous_status_to_reports.php');
        $migration->down();
        $report = $this->report();
        $report->update(['status' => ReportStatus::Inactive]);
        $migration->up();
        $this->assertDatabaseHas('reports', ['id' => $report->id, 'previous_status' => null]);
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_new_endpoints_require_authentication(): void
    {
        $report = $this->report();
        $id = $this->comment($report);
        auth()->forgetGuards();
        $this->app['auth']->guard('sanctum')->forgetUser();
        $this->postJson("/api/v1/reports/{$report->id}/deactivate")->assertUnauthorized();
        $this->postJson("/api/v1/reports/{$report->id}/activate")->assertUnauthorized();
        $this->putJson("/api/v1/reports/{$report->id}/comments/$id/reaction", ['reaction' => 'like'])->assertUnauthorized();
    }
}
