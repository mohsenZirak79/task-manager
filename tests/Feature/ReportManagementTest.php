<?php

namespace Tests\Feature;

use App\Enums\MeetingResolutionType;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingResolution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('access:sync');
    }

    public function test_super_admin_can_create_read_update_and_delete_a_resolution_report(): void
    {
        $admin = User::factory()->admin()->create();
        $recipient = User::factory()->create();
        $cc = User::factory()->create();
        [$meeting, $resolution] = $this->reportResolution($admin);
        Sanctum::actingAs($admin);

        $create = $this->postJson("/api/v1/meetings/{$meeting->id}/resolutions/{$resolution->id}/report", [
            'title' => 'گزارش عملکرد',
            'short_description' => 'خلاصه گزارش عملکرد ماهانه',
            'description' => 'شرح کامل گزارش عملکرد ماهانه',
            'recipient_user_id' => $recipient->id,
            'cc_user_ids' => [$cc->id],
            'tags' => ['مالی', 'ماهانه'],
        ])->assertCreated()
            ->assertJsonPath('data.report.title', 'گزارش عملکرد')
            ->assertJsonPath('data.report.recipient.id', $recipient->id)
            ->assertJsonPath('data.report.cc_users.0.id', $cc->id)
            ->assertJsonCount(2, 'data.report.tags');

        $reportId = $create->json('data.report.id');
        $this->getJson("/api/v1/meetings/{$meeting->id}/resolutions/{$resolution->id}/report")
            ->assertOk()
            ->assertJsonPath('data.report.id', $reportId);
        $this->getJson("/api/v1/reports/{$reportId}")
            ->assertOk()
            ->assertJsonPath('data.report.resolution.resolution_type', 'report');

        $this->patchJson("/api/v1/reports/{$reportId}", [
            'title' => 'گزارش عملکرد و بودجه',
            'cc_user_ids' => [],
            'tags' => ['بودجه'],
        ])->assertOk()
            ->assertJsonPath('data.report.title', 'گزارش عملکرد و بودجه')
            ->assertJsonCount(0, 'data.report.cc_users')
            ->assertJsonCount(1, 'data.report.tags');

        $this->deleteJson("/api/v1/reports/{$reportId}")->assertOk();
        $this->assertDatabaseMissing('reports', ['id' => $reportId]);
    }

    public function test_recipient_can_list_and_view_report_but_outsider_cannot(): void
    {
        $admin = User::factory()->admin()->create();
        $recipient = User::factory()->create();
        $outsider = User::factory()->create();
        [$meeting, $resolution] = $this->reportResolution($admin);
        Sanctum::actingAs($admin);
        $reportId = $this->postJson("/api/v1/meetings/{$meeting->id}/resolutions/{$resolution->id}/report", [
            'title' => 'گزارش محرمانه',
            'short_description' => 'خلاصه محرمانه',
            'description' => 'شرح محرمانه',
            'recipient_user_id' => $recipient->id,
        ])->assertCreated()->json('data.report.id');

        Sanctum::actingAs($recipient);
        $this->getJson('/api/v1/reports')->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $reportId);
        $this->getJson("/api/v1/reports/{$reportId}")->assertOk();

        Sanctum::actingAs($outsider);
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->getJson("/api/v1/reports/{$reportId}")->assertForbidden();
    }

    public function test_report_cannot_be_created_for_a_task_resolution(): void
    {
        $admin = User::factory()->admin()->create();
        $recipient = User::factory()->create();
        $meeting = Meeting::query()->create([
            'title' => 'جلسه', 'status' => MeetingStatus::Scheduled, 'created_by' => $admin->id,
        ]);
        $resolution = $meeting->resolutions()->create([
            'title' => 'مصوبه تسک', 'resolution_type' => MeetingResolutionType::Task, 'created_by' => $admin->id,
        ]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/meetings/{$meeting->id}/resolutions/{$resolution->id}/report", [
            'title' => 'گزارش',
            'short_description' => 'خلاصه',
            'description' => 'شرح',
            'recipient_user_id' => $recipient->id,
        ])->assertConflict();
    }

    /** @return array{Meeting, MeetingResolution} */
    private function reportResolution(User $creator): array
    {
        $meeting = Meeting::query()->create([
            'title' => 'جلسه گزارش', 'status' => MeetingStatus::Scheduled, 'created_by' => $creator->id,
        ]);
        $resolution = $meeting->resolutions()->create([
            'title' => 'مصوبه گزارش', 'resolution_type' => MeetingResolutionType::Report, 'created_by' => $creator->id,
        ]);

        return [$meeting, $resolution];
    }
}
