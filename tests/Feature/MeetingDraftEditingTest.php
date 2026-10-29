<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeetingDraftEditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_chairman_can_edit_and_submit_unstarted_draft_after_its_proposed_time(): void
    {
        [$chairman, $secretary, $outsider] = User::factory()->count(3)->create()->all();
        $meeting = Meeting::create([
            'title' => 'جلسه هفتگی', 'status' => 'draft', 'created_by' => $chairman->id,
            'chairman_user_id' => $chairman->id, 'secretary_user_id' => $secretary->id,
            'meeting_date' => now()->subDay()->toDateString(), 'start_time' => '00:30', 'location' => 'دفتر',
        ]);
        $meeting->attendees()->attach([$chairman->id, $secretary->id, $outsider->id]);
        $meeting->agendaItems()->create(['title' => 'بررسی امور', 'sort_order' => 1]);
        $url = "/api/v1/meetings/{$meeting->id}";
        Sanctum::actingAs($outsider);
        $this->patchJson($url, ['title' => 'غیرمجاز'])->assertForbidden();
        $this->postJson($url.'/submit')->assertForbidden();
        Sanctum::actingAs($chairman);
        $this->getJson($url)->assertOk()->assertJsonPath('data.meeting.allowed_actions.edit', true);
        $this->patchJson($url, ['title' => 'جلسه هفتگی اصلاح‌شده'])->assertOk();
        $this->postJson($url.'/submit')->assertOk()->assertJsonPath('data.meeting.status', 'scheduled');
        $this->assertFalse($meeting->fresh()->isEditableBeforeStart());
        $meeting->update(['status' => 'draft', 'started_at' => now()]);
        $this->assertFalse($meeting->fresh()->isEditableBeforeStart());
    }
}
