<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\OrgPosition;
use App\Models\User;
use App\Services\MeetingSignatureContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeetingCreationRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private User $child;

    private User $grandchild;

    private User $peer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-31T08:00:00Z'));
        [$this->creator, $this->child, $this->grandchild, $this->peer] = User::factory()->count(4)->create()->all();
        $parent = OrgPosition::create(['title' => 'شرکت']);
        $root = OrgPosition::create(['title' => 'مدیر', 'parent_id' => $parent->id]);
        $root->users()->attach($this->creator);
        OrgPosition::create(['title' => 'هم‌رده', 'parent_id' => $parent->id])->users()->attach($this->peer);
        $child = OrgPosition::create(['title' => 'کارشناس', 'parent_id' => $root->id]);
        $child->users()->attach($this->child);
        OrgPosition::create(['title' => 'زیرمجموعه', 'parent_id' => $child->id])->users()->attach($this->grandchild);
        Sanctum::actingAs($this->creator);
    }

    private function payload(array $extra = []): array
    {
        return [
            'title' => 'جلسه', 'short_description' => 'خلاصه ضروری', 'location' => 'دفتر',
            'meeting_date' => '2026-11-01', 'start_time' => '10:00', 'duration_minutes' => 300,
            'secretary_user_id' => $this->child->id, 'attendee_user_ids' => [$this->grandchild->id],
            'agenda_items' => [['title' => 'دستور جلسه', 'sort_order' => 1]], ...$extra,
        ];
    }

    public function test_chairman_is_creator_and_cannot_be_forged_or_changed(): void
    {
        $this->postJson('/api/v1/meetings', $this->payload(['chairman_user_id' => $this->child->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('chairman_user_id');
        $id = $this->postJson('/api/v1/meetings', $this->payload())->assertCreated()
            ->assertJsonPath('data.meeting.chairman.id', $this->creator->id)->json('data.meeting.id');
        $this->patchJson("/api/v1/meetings/$id", ['chairman_user_id' => $this->child->id])->assertUnprocessable();
        $this->assertDatabaseHas('meetings', ['id' => $id, 'chairman_user_id' => $this->creator->id]);
    }

    public function test_secretary_and_attendees_are_scoped_to_creator_descendants_without_peer_or_admin_bypass(): void
    {
        $outside = User::factory()->admin()->create();
        foreach ([$this->peer, $outside] as $person) {
            $this->postJson('/api/v1/meetings', $this->payload(['secretary_user_id' => $person->id]))->assertUnprocessable();
            $this->postJson('/api/v1/meetings', $this->payload(['attendee_user_ids' => [$person->id]]))->assertUnprocessable();
        }
        $this->postJson('/api/v1/meetings', $this->payload(['secretary_user_id' => $this->creator->id]))
            ->assertCreated()->assertJsonPath('data.meeting.secretary.id', $this->creator->id);
        $this->assertDatabaseCount('meetings', 1);
    }

    public function test_eligible_users_are_active_descendants_and_edit_scope_stays_relative_to_creator(): void
    {
        $data = $this->getJson('/api/v1/meetings/eligible-users')->assertOk()->json('data');
        $this->assertSame($this->creator->id, $data['chairman']['id']);
        $this->assertEqualsCanonicalizing([$this->child->id, $this->grandchild->id], array_column($data['attendees'], 'id'));
        $this->assertEqualsCanonicalizing([$this->creator->id, $this->child->id, $this->grandchild->id], array_column($data['secretaries'], 'id'));
        $id = $this->postJson('/api/v1/meetings', $this->payload())->assertCreated()->json('data.meeting.id');
        Sanctum::actingAs($this->child);
        $this->getJson("/api/v1/meetings/eligible-users?meeting_id=$id")->assertOk()->assertJsonPath('data.chairman.id', $this->creator->id);
        Sanctum::actingAs($this->peer);
        $this->getJson("/api/v1/meetings/eligible-users?meeting_id=$id")->assertForbidden();
        Sanctum::actingAs($this->creator);
        $this->child->update(['is_active' => false]);
        $this->getJson('/api/v1/meetings/eligible-users')->assertOk()->assertJsonCount(1, 'data.attendees');
    }

    public function test_short_description_is_required_even_for_draft_and_duration_required_on_submission(): void
    {
        $this->postJson('/api/v1/meetings', [])->assertUnprocessable()->assertJsonValidationErrors('short_description');
        $this->postJson('/api/v1/meetings', $this->payload(['short_description' => '  ']))->assertUnprocessable();
        foreach ([0, -1, 1.5] as $minutes) {
            $this->postJson('/api/v1/meetings', $this->payload(['duration_minutes' => $minutes]))->assertUnprocessable();
        }
        $this->postJson('/api/v1/meetings', $this->payload(['duration_minutes' => null, 'submit' => true]))->assertUnprocessable();
        $id = $this->postJson('/api/v1/meetings', $this->payload(['submit' => true]))->assertCreated()
            ->assertJsonPath('data.meeting.duration_minutes', 300)->json('data.meeting.id');
        $this->getJson('/api/v1/meetings')->assertOk()->assertJsonPath('data.items.0.duration_minutes', 300);
        $this->patchJson("/api/v1/meetings/$id", ['short_description' => null])->assertUnprocessable();
    }

    public function test_only_chairman_can_start_and_secretary_cannot_start_on_their_behalf(): void
    {
        $id = $this->postJson('/api/v1/meetings', $this->payload(['submit' => true]))->assertCreated()->json('data.meeting.id');
        Sanctum::actingAs($this->child);
        $this->postJson("/api/v1/meetings/$id/start")->assertForbidden();
        Sanctum::actingAs($this->creator);
        $this->postJson("/api/v1/meetings/$id/start")->assertOk();
        $this->postJson("/api/v1/meetings/$id/start")->assertForbidden();
    }

    public function test_draft_supports_initial_and_editable_resolutions_before_start(): void
    {
        $id = $this->postJson('/api/v1/meetings', $this->payload([
            'initial_resolutions' => [['title' => 'مصوبه اولیه', 'description' => 'شرح']],
        ]))->assertCreated()->assertJsonPath('data.meeting.allowed_actions.manage_resolutions', true)
            ->assertJsonPath('data.meeting.resolutions.0.title', 'مصوبه اولیه')->json('data.meeting.id');
        $rid = $this->postJson("/api/v1/meetings/$id/resolutions", ['title' => 'مصوبه جدید'])
            ->assertCreated()->json('data.resolution.id');
        $this->patchJson("/api/v1/meetings/$id/resolutions/$rid", ['title' => 'ویرایش'])->assertOk();
        $this->deleteJson("/api/v1/meetings/$id/resolutions/$rid")->assertOk();
        $this->assertDatabaseCount('tasks', 0);
        $this->assertNull(Meeting::findOrFail($id)->started_at);
    }

    public function test_view_state_is_independent_of_signature_and_does_not_create_fabricated_views(): void
    {
        $id = $this->postJson('/api/v1/meetings', $this->payload(['submit' => true]))->assertCreated()->json('data.meeting.id');
        Sanctum::actingAs($this->grandchild);
        $this->postJson("/api/v1/meetings/$id/view")->assertOk();
        $members = $this->getJson("/api/v1/meetings/$id")->assertOk()->json('data.meeting.attendees');
        $byId = collect($members)->keyBy('id');
        $this->assertTrue($byId[$this->grandchild->id]['is_viewed']);
        $this->assertSame('2026-10-31T08:00:00.000000Z', $byId[$this->grandchild->id]['viewed_at']);
        $this->assertFalse($byId[$this->creator->id]['is_viewed']);
        $this->assertSame('unsigned', $byId[$this->grandchild->id]['signature_status']);
        $this->assertSame(1, DB::table('meeting_acknowledgements')->count());
        $this->assertDatabaseCount('meeting_signatures', 0);
    }

    public function test_invalid_roster_update_is_atomic_and_chairman_stays_fixed_for_secretary(): void
    {
        $id = $this->postJson('/api/v1/meetings', $this->payload())->assertCreated()->json('data.meeting.id');
        Sanctum::actingAs($this->child);
        $this->patchJson("/api/v1/meetings/$id", ['title' => 'نباید ذخیره شود', 'secretary_user_id' => $this->peer->id])
            ->assertUnprocessable()->assertJsonValidationErrors('secretary_user_id');
        $this->assertDatabaseHas('meetings', ['id' => $id, 'title' => 'جلسه', 'secretary_user_id' => $this->child->id]);
        $this->patchJson("/api/v1/meetings/$id", ['chairman_user_id' => $this->child->id])->assertUnprocessable();
    }

    public function test_duration_belongs_to_signed_content_without_changing_null_legacy_snapshot_structure(): void
    {
        $id = $this->postJson('/api/v1/meetings', $this->payload())->assertCreated()->json('data.meeting.id');
        $meeting = Meeting::findOrFail($id);
        $content = app(MeetingSignatureContent::class);
        $snapshot = $content->snapshot($meeting);
        $this->assertSame(300, $snapshot['meeting']['duration_minutes']);
        $version = $content->version($snapshot);
        $meeting->update(['duration_minutes' => 180]);
        $this->assertNotSame($version, $content->version($content->snapshot($meeting->fresh())));
        $meeting->update(['duration_minutes' => null]);
        $this->assertArrayNotHasKey('duration_minutes', $content->snapshot($meeting->fresh())['meeting']);
    }
}
