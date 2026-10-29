<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Meeting;
use App\Models\Permission;
use App\Models\User;
use App\Services\MeetingService;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeetingOfficerAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $chairman;

    private User $secretary;

    private User $creator;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('access:sync');
        $this->chairman = User::factory()->create();
        $this->secretary = User::factory()->create();
        $this->creator = User::factory()->create();
        foreach ([$this->chairman, $this->secretary, $this->creator] as $user) {
            $user->accessRoles()->sync(AccessRole::where('slug', 'user')->pluck('id'));
            $this->assertFalse($user->hasPermission(Permissions::MEETINGS_MANAGE_RESOLUTIONS));
            $this->assertFalse($user->hasPermission(Permissions::MEETINGS_COMPLETE));
        }
        $this->meeting = $this->newMeeting();
    }

    private function newMeeting(): Meeting
    {
        $meeting = Meeting::create([
            'title' => 'جلسه هماهنگی', 'status' => 'scheduled', 'started_at' => now(),
            'created_by' => $this->creator->id, 'chairman_user_id' => $this->chairman->id,
            'secretary_user_id' => $this->secretary->id,
        ]);
        $meeting->attendees()->attach([$this->chairman->id, $this->secretary->id]);

        return $meeting;
    }

    private function resolution(Meeting $meeting)
    {
        return $meeting->resolutions()->create(['title' => 'مصوبه', 'created_by' => $this->creator->id, 'resolution_type' => 'task']);
    }

    public function test_each_active_officer_can_manage_own_resolutions_and_complete_only_with_a_resolution(): void
    {
        foreach ([$this->chairman, $this->secretary] as $officer) {
            $meeting = $this->newMeeting();
            Sanctum::actingAs($officer);
            $url = "/api/v1/meetings/{$meeting->id}";
            $this->getJson($url)->assertOk()
                ->assertJsonPath('data.meeting.allowed_actions.manage_resolutions', true)
                ->assertJsonPath('data.meeting.allowed_actions.complete', false);
            $this->postJson($url.'/complete')->assertUnprocessable()->assertJsonValidationErrors('resolutions');
            $id = $this->postJson($url.'/resolutions', ['title' => 'مصوبه جدید'])->assertCreated()->json('data.resolution.id');
            $this->patchJson($url.'/resolutions/'.$id, ['title' => 'مصوبه اصلاح‌شده'])->assertOk();
            $this->getJson($url)->assertOk()->assertJsonPath('data.meeting.allowed_actions.complete', true);
            $this->deleteJson($url.'/resolutions/'.$id)->assertOk();
            $this->postJson($url.'/complete')->assertUnprocessable();
            $this->postJson($url.'/resolutions', ['title' => 'مصوبه نهایی'])->assertCreated();
            $this->postJson($url.'/complete')->assertOk()
                ->assertJsonPath('data.meeting.status', 'completed')
                ->assertJsonPath('data.meeting.allowed_actions.manage_resolutions', false)
                ->assertJsonPath('data.meeting.allowed_actions.complete', false);
            $this->assertNotNull($meeting->fresh()->completed_at);
        }
    }

    public function test_attendee_creator_and_officer_of_another_meeting_cannot_use_this_exception(): void
    {
        $outsider = User::factory()->create();
        $this->meeting->attendees()->attach($outsider);
        Meeting::create(['title' => 'جلسه دیگر', 'status' => 'scheduled', 'created_by' => $outsider->id, 'chairman_user_id' => $outsider->id]);
        $resolution = $this->resolution($this->meeting);
        foreach ([$outsider, $this->creator] as $user) {
            Sanctum::actingAs($user);
            $url = "/api/v1/meetings/{$this->meeting->id}";
            $this->getJson($url)->assertOk()
                ->assertJsonPath('data.meeting.allowed_actions.manage_resolutions', false)
                ->assertJsonPath('data.meeting.allowed_actions.complete', false);
            $this->postJson($url.'/resolutions', ['title' => 'غیرمجاز'])->assertForbidden();
            $this->patchJson($url.'/resolutions/'.$resolution->id, ['title' => 'غیرمجاز'])->assertForbidden();
            $this->deleteJson($url.'/resolutions/'.$resolution->id)->assertForbidden();
            $this->postJson($url.'/complete')->assertForbidden();
        }
        $this->assertSame('مصوبه', $resolution->fresh()->title);
        $this->assertSame('scheduled', $this->meeting->fresh()->status->value);
    }

    public function test_inactive_officer_and_guest_are_rejected(): void
    {
        $url = "/api/v1/meetings/{$this->meeting->id}";
        $this->postJson($url.'/resolutions', ['title' => 'مصوبه'])->assertUnauthorized();
        $this->chairman->update(['is_active' => false]);
        Sanctum::actingAs($this->chairman);
        $this->postJson($url.'/resolutions', ['title' => 'مصوبه'])->assertForbidden();
        $this->postJson($url.'/complete')->assertForbidden();
    }

    public function test_officers_cannot_modify_finalized_cancelled_or_draft_meetings(): void
    {
        Sanctum::actingAs($this->secretary);
        $resolution = $this->resolution($this->meeting);
        foreach (['completed', 'cancelled', 'draft'] as $status) {
            $this->meeting->update(['status' => $status]);
            $url = "/api/v1/meetings/{$this->meeting->id}";
            $this->getJson($url)->assertOk()
                ->assertJsonPath('data.meeting.allowed_actions.manage_resolutions', false)
                ->assertJsonPath('data.meeting.allowed_actions.complete', false);
            $this->postJson($url.'/resolutions', ['title' => 'غیرمجاز'])->assertConflict();
            $this->patchJson($url.'/resolutions/'.$resolution->id, ['title' => 'غیرمجاز'])->assertConflict();
            $this->deleteJson($url.'/resolutions/'.$resolution->id)->assertConflict();
            $this->postJson($url.'/complete')->assertConflict();
        }
    }

    public function test_wrong_parent_and_removed_officer_never_gain_access(): void
    {
        $other = $this->newMeeting();
        $resolution = $this->resolution($other);
        Sanctum::actingAs($this->secretary);
        $url = "/api/v1/meetings/{$this->meeting->id}";
        $this->patchJson($url.'/resolutions/'.$resolution->id, ['title' => 'غیرمجاز'])->assertNotFound();
        $this->deleteJson($url.'/resolutions/'.$resolution->id)->assertNotFound();
        $this->meeting->update(['secretary_user_id' => $this->creator->id]);
        $this->postJson($url.'/resolutions', ['title' => 'غیرمجاز'])->assertForbidden();
        $this->postJson($url.'/complete')->assertForbidden();
    }

    public function test_officer_exception_does_not_grant_task_or_report_module_permissions(): void
    {
        $role = AccessRole::create(['name' => 'فقط مشاهده جلسات', 'slug' => 'meeting-viewer']);
        $role->permissions()->sync(Permission::where('name', Permissions::MEETINGS_VIEW)->pluck('id'));
        $this->chairman->accessRoles()->sync([$role->id]);
        $this->chairman->unsetRelation('accessRoles');
        Sanctum::actingAs($this->chairman);
        $url = "/api/v1/meetings/{$this->meeting->id}/resolutions";
        $id = $this->postJson($url, ['title' => 'مصوبه'])->assertCreated()->json('data.resolution.id');
        $this->postJson($url.'/'.$id.'/create-task', [])->assertForbidden();
        $this->postJson($url.'/'.$id.'/link-task', ['task_id' => 1])->assertForbidden();
        $this->postJson($url.'/'.$id.'/report', [])->assertForbidden();
    }

    public function test_global_permissions_do_not_authorize_an_unrelated_non_admin(): void
    {
        $outsider = User::factory()->create();
        $role = AccessRole::create(['name' => 'مجوزهای عمومی', 'slug' => 'global-meeting-permissions']);
        $role->permissions()->sync(Permission::whereIn('name', [Permissions::MEETINGS_VIEW, Permissions::MEETINGS_COMPLETE, Permissions::MEETINGS_MANAGE_RESOLUTIONS])->pluck('id'));
        $outsider->accessRoles()->sync([$role->id]);
        Sanctum::actingAs($outsider);
        $this->resolution($this->meeting);
        $url = "/api/v1/meetings/{$this->meeting->id}";
        $this->postJson($url.'/resolutions', ['title' => 'غیرمجاز'])->assertForbidden();
        $this->postJson($url.'/complete')->assertForbidden();
    }

    public function test_existing_admin_access_is_preserved(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $url = "/api/v1/meetings/{$this->meeting->id}";
        $this->postJson($url.'/resolutions', ['title' => 'مصوبه مدیر'])->assertCreated();
        $this->postJson($url.'/complete')->assertOk();
    }

    public function test_service_rechecks_the_officer_under_lock_instead_of_trusting_stale_meeting_data(): void
    {
        $stale = $this->meeting->fresh();
        $this->meeting->update(['secretary_user_id' => $this->creator->id]);
        try {
            app(MeetingService::class)->createResolution($stale, ['title' => 'غیرمجاز'], $this->secretary);
            $this->fail('A removed secretary must not retain access through a stale model.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('meeting_resolutions', 0);
        }
    }
}
