<?php

namespace Tests\Feature;

use App\Enums\MeetingStatus;
use App\Models\AccessRole;
use App\Models\Meeting;
use App\Models\MeetingSignature;
use App\Models\Task;
use App\Models\User;
use App\Services\MeetingSignatureContent;
use App\Services\MeetingSignatureService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeetingSignatureTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private User $member;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-27T10:00:00Z'));
        Artisan::call('access:sync');
        $this->creator = User::factory()->admin()->create();
        $this->member = User::factory()->create();
        $this->other = User::factory()->create();
        Sanctum::actingAs($this->member);
    }

    private function meeting(string $status = 'completed'): Meeting
    {
        $meeting = Meeting::create([
            'title' => 'صورت جلسه', 'short_description' => 'خلاصه', 'location' => 'اتاق جلسات',
            'meeting_date' => '2026-10-27', 'start_time' => '09:00', 'status' => $status,
            'created_by' => $this->creator->id, 'chairman_user_id' => $this->member->id,
            'secretary_user_id' => $this->other->id,
            'submitted_at' => $status === 'draft' ? null : now()->subHours(2),
            'completed_at' => $status === 'completed' ? now()->subHour() : null,
        ]);
        $meeting->attendees()->attach([$this->member->id, $this->other->id]);
        $agenda = $meeting->agendaItems()->create(['title' => 'دستور جلسه', 'sort_order' => 0]);
        $meeting->resolutions()->create([
            'title' => 'مصوبه اولیه', 'description' => 'متن مورد تأیید', 'resolution_type' => 'report',
            'agenda_item_id' => $agenda->id, 'created_by' => $this->creator->id, 'sort_order' => 0,
        ]);

        return $meeting;
    }

    private function version(Meeting $meeting): string
    {
        return $this->getJson("/api/v1/meetings/{$meeting->id}")->assertOk()->json('data.meeting.signature_content_version');
    }

    private function sign(Meeting $meeting, string $version)
    {
        return $this->postJson("/api/v1/meetings/{$meeting->id}/sign", ['content_version' => $version]);
    }

    public function test_details_include_identity_signature_state_and_only_own_permission(): void
    {
        $meeting = $this->meeting();
        $r = $this->getJson("/api/v1/meetings/{$meeting->id}")->assertOk()
            ->assertJsonPath('data.meeting.allowed_actions.sign', true)
            ->assertJsonPath('data.meeting.attendees.0.id', $this->member->id)
            ->assertJsonPath('data.meeting.attendees.0.full_name', trim($this->member->first_name.' '.$this->member->last_name))
            ->assertJsonPath('data.meeting.attendees.0.signature_status', 'unsigned')
            ->assertJsonPath('data.meeting.attendees.0.signed_at', null)
            ->assertJsonPath('data.meeting.attendees.0.can_sign', true)
            ->assertJsonPath('data.meeting.attendees.1.can_sign', false);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $r->json('data.meeting.signature_content_version'));
        $this->assertDatabaseCount('meeting_signatures', 0);
    }

    public function test_signing_is_idempotent_server_timed_and_each_member_signs_independently(): void
    {
        $meeting = $this->meeting();
        $version = $this->version($meeting);
        $this->sign($meeting, $version)->assertOk()
            ->assertJsonPath('data.meeting.attendees.0.signature_status', 'signed')
            ->assertJsonPath('data.meeting.attendees.0.signed_at', '2026-10-27T10:00:00.000000Z')
            ->assertJsonPath('data.meeting.attendees.0.signed_content_version', $version)
            ->assertJsonPath('data.meeting.attendees.0.can_sign', false)
            ->assertJsonPath('data.meeting.allowed_actions.sign', false)
            ->assertJsonPath('data.meeting.attendees.1.signature_status', 'unsigned');
        $this->travel(1)->hours();
        $this->sign($meeting, $version)->assertOk()
            ->assertJsonPath('data.meeting.attendees.0.signed_at', '2026-10-27T10:00:00.000000Z');
        $this->assertDatabaseCount('meeting_signatures', 1);
        Sanctum::actingAs($this->other);
        $this->getJson("/api/v1/meetings/{$meeting->id}")->assertOk()
            ->assertJsonPath('data.meeting.attendees.1.can_sign', true)
            ->assertJsonPath('data.meeting.attendees.0.can_sign', false);
        $this->sign($meeting, $version)->assertOk()
            ->assertJsonPath('data.meeting.attendees.1.signed_at', '2026-10-27T11:00:00.000000Z');
        $this->assertDatabaseCount('meeting_signatures', 2);
        $this->assertSame(MeetingStatus::Completed, $meeting->fresh()->status);
    }

    public function test_signing_requires_authentication_permission_and_active_account(): void
    {
        $meeting = $this->meeting();
        $version = $this->version($meeting);
        auth()->forgetGuards();
        $this->app['auth']->guard('sanctum')->forgetUser();
        $this->sign($meeting, $version)->assertUnauthorized();
        $role = AccessRole::create(['slug' => 'no-meeting-permission', 'name' => 'No meeting permission']);
        $this->member->accessRoles()->sync([$role->id]);
        $this->member->unsetRelation('accessRoles');
        Sanctum::actingAs($this->member);
        $this->sign($meeting, $version)->assertForbidden();
        $this->other->update(['is_active' => false]);
        Sanctum::actingAs($this->other);
        $this->sign($meeting, $version)->assertForbidden();
        $this->assertDatabaseCount('meeting_signatures', 0);
    }

    public function test_only_completed_meetings_can_be_signed(): void
    {
        foreach (['draft', 'scheduled', 'cancelled'] as $state) {
            $meeting = $this->meeting($state);
            $this->getJson("/api/v1/meetings/{$meeting->id}")->assertOk()
                ->assertJsonPath('data.meeting.allowed_actions.sign', false)
                ->assertJsonPath('data.meeting.attendees.0.can_sign', false);
            $this->sign($meeting, $this->version($meeting))->assertForbidden();
        }
        $this->assertDatabaseCount('meeting_signatures', 0);
    }

    public function test_non_attendees_including_super_admin_and_creator_cannot_sign(): void
    {
        $meeting = $this->meeting();
        $version = $this->version($meeting);
        foreach ([User::factory()->create(), $this->creator] as $outsider) {
            Sanctum::actingAs($outsider);
            $this->sign($meeting, $version)->assertForbidden();
        }
        $this->getJson("/api/v1/meetings/{$meeting->id}")->assertOk()
            ->assertJsonPath('data.meeting.allowed_actions.sign', false);
        $this->assertDatabaseCount('meeting_signatures', 0);
        $meeting->attendees()->attach($this->creator);
        $this->sign($meeting, $this->version($meeting))->assertOk();
        $this->assertDatabaseHas('meeting_signatures', ['meeting_id' => $meeting->id, 'user_id' => $this->creator->id]);
    }

    public function test_actor_time_and_version_cannot_be_spoofed(): void
    {
        $meeting = $this->meeting();
        $url = "/api/v1/meetings/{$meeting->id}/sign";
        $version = $this->version($meeting);
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('content_version');
        $this->sign($meeting, 'invalid')->assertUnprocessable();
        foreach (['user_id' => $this->other->id, 'attendee_user_id' => $this->other->id,
            'signed_at' => '2000-01-01T00:00:00Z', 'signature_file_id' => 99,
            'content_snapshot' => ['title' => 'forged']] as $key => $value) {
            $this->postJson($url, ['content_version' => $version, $key => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->sign($meeting, str_repeat('0', 64))->assertConflict();
        $this->assertDatabaseCount('meeting_signatures', 0);
    }

    public function test_view_and_sign_are_independent_and_completion_needs_no_signatures(): void
    {
        $meeting = $this->meeting('scheduled');
        $this->postJson("/api/v1/meetings/{$meeting->id}/view")->assertOk();
        $viewedAt = DB::table('meeting_acknowledgements')->value('viewed_at');
        $this->assertDatabaseCount('meeting_signatures', 0);
        Sanctum::actingAs($this->creator);
        $this->postJson("/api/v1/meetings/{$meeting->id}/complete")->assertOk()
            ->assertJsonPath('data.meeting.status', 'completed');
        Sanctum::actingAs($this->member);
        $this->sign($meeting, $this->version($meeting))->assertOk();
        $this->assertSame($viewedAt, DB::table('meeting_acknowledgements')->value('viewed_at'));
        $this->assertDatabaseCount('meeting_acknowledgements', 1);
        $another = $this->meeting();
        $this->sign($another, $this->version($another))->assertOk();
        $this->assertDatabaseMissing('meeting_acknowledgements', ['meeting_id' => $another->id]);
    }

    public function test_changed_minutes_require_new_consent_and_preserve_old_snapshot(): void
    {
        $meeting = $this->meeting();
        $version = $this->version($meeting);
        $this->sign($meeting, $version)->assertOk();
        $old = MeetingSignature::firstOrFail();
        // Simulate a privileged data correction/future approved edit, not a new edit workflow.
        $meeting->resolutions()->firstOrFail()->update(['title' => 'مصوبه اصلاح‌شده']);
        $new = $this->getJson("/api/v1/meetings/{$meeting->id}")->assertOk()
            ->assertJsonPath('data.meeting.attendees.0.signature_status', 'outdated')
            ->assertJsonPath('data.meeting.attendees.0.signed_at', null)
            ->assertJsonPath('data.meeting.attendees.0.signed_content_version', $version)
            ->assertJsonPath('data.meeting.attendees.0.can_sign', true)->json('data.meeting.signature_content_version');
        $this->assertNotSame($version, $new);
        $this->sign($meeting, $version)->assertConflict();
        $this->travel(30)->minutes();
        $this->sign($meeting, $new)->assertOk()
            ->assertJsonPath('data.meeting.attendees.0.signature_status', 'signed');
        $this->assertDatabaseCount('meeting_signatures', 2);
        $this->assertSame('مصوبه اولیه', $old->fresh()->content_snapshot['resolutions'][0]['title']);
        $this->assertSame('2026-10-27T10:00:00.000000Z', $old->fresh()->signed_at->toISOString());
        $this->assertSame('مصوبه اصلاح‌شده', MeetingSignature::latest('id')->firstOrFail()->content_snapshot['resolutions'][0]['title']);
    }

    public function test_sign_service_rechecks_locked_state_and_membership(): void
    {
        foreach (['state', 'membership'] as $change) {
            $meeting = $this->meeting();
            $version = $this->version($meeting);
            $stale = $meeting->fresh(['attendees']);
            if ($change === 'state') {
                $meeting->update(['status' => 'cancelled']);
            } else {
                $meeting->attendees()->detach($this->member);
            }
            try {
                app(MeetingSignatureService::class)->sign($stale, $this->member, $version);
                $this->fail('Stale authorization must be rechecked after locking');
            } catch (AuthorizationException $e) {
                $this->assertDatabaseMissing('meeting_signatures', ['meeting_id' => $meeting->id]);
            }
        }
    }

    public function test_profile_and_live_task_progress_do_not_revoke_minutes_consent(): void
    {
        $meeting = $this->meeting();
        $task = Task::create(['title' => 'Task', 'short_description' => 'Summary', 'status' => 'in_progress',
            'submission_type' => 'assignment', 'created_by' => $this->creator->id, 'requester_id' => $this->creator->id]);
        $meeting->resolutions()->firstOrFail()->update(['resolution_type' => 'task', 'task_id' => $task->id]);
        $version = $this->version($meeting);
        $this->sign($meeting, $version)->assertOk();
        $this->member->update(['first_name' => 'نام جدید']);
        $task->update(['progress_percentage' => 50]);
        $this->travel(1)->days();
        $this->assertSame($version, $this->version($meeting));
        $this->getJson("/api/v1/meetings/{$meeting->id}")->assertOk()
            ->assertJsonPath('data.meeting.attendees.0.signature_status', 'signed');
    }

    public function test_completed_meeting_edit_locks_still_apply(): void
    {
        $meeting = $this->meeting();
        Sanctum::actingAs($this->creator);
        $resolution = $meeting->resolutions()->firstOrFail();
        $this->patchJson("/api/v1/meetings/{$meeting->id}", ['title' => 'changed'])->assertForbidden();
        $this->patchJson("/api/v1/meetings/{$meeting->id}/resolutions/{$resolution->id}", ['title' => 'changed'])->assertConflict();
        $this->deleteJson("/api/v1/meetings/{$meeting->id}/resolutions/{$resolution->id}")->assertConflict();
    }

    public function test_details_queries_do_not_grow_with_attendees_and_history_is_not_exposed(): void
    {
        $meeting = $this->meeting();
        $this->sign($meeting, $this->version($meeting))->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson("/api/v1/meetings/{$meeting->id}")->assertOk()->assertJsonMissingPath('data.meeting.signatures');
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        $meeting->attendees()->attach(User::factory()->count(15)->create()->modelKeys());
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson("/api/v1/meetings/{$meeting->id}")->assertOk()->assertJsonCount(17, 'data.meeting.attendees');
        $this->assertLessThanOrEqual($small, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_database_rejects_duplicate_signature_for_same_user_and_version(): void
    {
        $meeting = $this->meeting();
        $this->sign($meeting, $this->version($meeting))->assertOk();
        $this->expectException(QueryException::class);
        MeetingSignature::firstOrFail()->replicate()->save();
    }

    public function test_archived_json_reproduces_version_even_if_object_keys_are_reordered(): void
    {
        $meeting = $this->meeting();
        $version = $this->version($meeting);
        $this->sign($meeting, $version)->assertOk();
        $snapshot = MeetingSignature::firstOrFail()->content_snapshot;
        $content = app(MeetingSignatureContent::class);
        $this->assertSame($version, $content->version($snapshot));
        $snapshot = array_reverse($snapshot, true);
        $snapshot['meeting'] = array_reverse($snapshot['meeting'], true);
        $this->assertSame($version, $content->version($snapshot));
    }

    public function test_removed_attendee_keeps_history_but_cannot_sign_again(): void
    {
        $meeting = $this->meeting();
        $version = $this->version($meeting);
        $this->sign($meeting, $version)->assertOk();
        $meeting->attendees()->detach($this->member);
        $this->sign($meeting, $version)->assertForbidden();
        $this->assertDatabaseCount('meeting_signatures', 1);
    }
}
