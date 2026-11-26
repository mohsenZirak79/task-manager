<?php

namespace Database\Seeders;

use App\Models\AccessRole;
use App\Models\Meeting;
use App\Models\OrgPosition;
use App\Models\Report;
use App\Models\Task;
use App\Models\User;
use App\Support\AccessRoles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LocalWorkflowDemoSeeder extends Seeder
{
    public function run(): void
    {
        $connection = config('database.default');
        $host = config("database.connections.$connection.host");
        if (! app()->environment('local') || ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new RuntimeException('Workflow demo requires a local environment and local database host.');
        }

        Artisan::call('access:sync');
        DB::transaction(function (): void {
            $definitions = [
                ['qa.manager', 'محسن', 'آزمایشی', 'مدیرعامل آزمایشی', null],
                ['qa.lead', 'رضا', 'طبایی', 'سرپرست تیم آزمایشی', 0],
                ['qa.member', 'طاها', 'رضایی', 'کارشناس اجرایی آزمایشی', 1],
                ['qa.member2', 'نگار', 'احمدی', 'کارشناس برنامه‌ریزی آزمایشی', 1],
                ['qa.junior', 'علی', 'محمدی', 'همکار زیرمجموعه طاها', 2],
            ];
            $users = [];
            $positions = [];
            foreach ($definitions as $index => [$username, $first, $last, $title, $parent]) {
                $user = User::query()->firstOrCreate(['username' => $username], [
                    'org_code' => (string) (990001 + $index),
                    'first_name' => $first, 'last_name' => $last,
                    'mobile' => '0935999000'.($index + 1),
                    'email' => $username.'@local.test',
                    'password' => 'DemoPass!123', 'title' => $title,
                    'is_active' => true, 'must_change_password' => false,
                ]);
                $role = AccessRole::query()->where('slug', $index === 0 ? AccessRoles::SUPER_ADMIN : AccessRoles::USER)->firstOrFail();
                $user->accessRoles()->syncWithoutDetaching([$role->id]);
                $position = OrgPosition::query()->firstOrCreate(['title' => 'LOCAL-QA | '.$title], [
                    'parent_id' => $parent === null ? null : $positions[$parent]->id,
                    'sort_order' => 100 + $index, 'request_up_levels' => 4, 'assignment_down_levels' => 4,
                ]);
                $position->users()->syncWithoutDetaching([$user->id]);
                $users[$index] = $user;
                $positions[$index] = $position;
            }
            [$manager, $lead, $member, $second, $junior] = $users;
            $tasks = [];
            foreach ([
                ['LOCAL-QA | تست دیدگاه و منشن با @', 'in_progress', 'assignment', $lead, 600],
                ['LOCAL-QA | اجرای تیمی و پیشرفت کار اعضا', 'in_progress', 'assignment', $manager, 480],
                ['LOCAL-QA | درخواست آماده انتقال به زیرمجموعه', 'pending_approval', 'request', $manager, 120],
                ['LOCAL-QA | پیش‌نویس قابل ویرایش', 'draft', 'assignment', $lead, 960],
                ['LOCAL-QA | نمونه خاتمه‌یافته برای فیلتر', 'completed', 'assignment', $lead, 720],
            ] as $index => [$title, $status, $type, $creator, $duration]) {
                $task = Task::query()->firstOrCreate(['title' => $title, 'created_by' => $creator->id], [
                    'short_description' => 'داده آزمایشی لوکال برای بررسی رابط کاربری و گردش کار.',
                    'request_description' => 'در دیدگاه علامت @ را تایپ و رضا، طاها یا نگار را انتخاب کنید. داده‌های قبلی حفظ شده‌اند.',
                    'duration_minutes' => $duration, 'due_date' => today()->addDays($index + 1)->toDateString(),
                    'progress_percentage' => $status === 'completed' ? 100 : 0,
                    'status' => $status, 'submission_type' => $type, 'requester_id' => $creator->id,
                    'registered_at' => $status === 'draft' ? null : now(),
                    'started_at' => in_array($status, ['in_progress', 'completed'], true) ? now() : null,
                    'completed_at' => $status === 'completed' ? now() : null,
                ]);
                if ($task->wasRecentlyCreated) {
                    $task->participantRecords()->create(['user_id' => $lead->id, 'role' => 'assignee']);
                    if ($index === 0) {
                        foreach ([$member, $second, $junior] as $follower) {
                            $task->participantRecords()->create(['user_id' => $follower->id, 'role' => 'follower']);
                        }
                    }
                    if (in_array($index, [1, 2], true)) {
                        $task->teamMembers()->sync([$member->id, $second->id]);
                    }
                    foreach (['جمع‌آوری اطلاعات', 'اجرا و بررسی نتیجه'] as $order => $work) {
                        $task->planningItems()->create([
                            'title' => $work, 'weight' => 50, 'sort_order' => $order,
                            'progress_percentage' => $status === 'completed' ? 100 : 0,
                            'assignee_user_id' => in_array($index, [1, 2], true) ? ($order === 0 ? $member->id : $second->id) : null,
                        ]);
                    }
                    if ($index === 0) {
                        $comment = $task->comments()->create(['user_id' => $lead->id, 'body' => '@طاها رضایی لطفاً اطلاعات را بررسی کن. @نگار احمدی نتیجه را اینجا بنویس.']);
                        $comment->mentions()->sync([$member->id, $second->id]);
                        $task->comments()->create(['user_id' => $member->id, 'parent_id' => $comment->id, 'body' => 'این یک پاسخ آزمایشی است؛ برای منشن فرد دیگری @ بنویسید.']);
                    }
                }
                $tasks[] = $task;
            }
            $reports = [];
            foreach (['viewed', 'sent', 'draft'] as $index => $status) {
                $creator = $status === 'sent' ? $manager : $lead;
                $recipient = $status === 'sent' ? $lead : $manager;
                $report = Report::query()->firstOrCreate(['title' => 'LOCAL-QA | گزارش آزمایشی '.$status, 'created_by' => $creator->id], [
                    'short_description' => 'تست دیدگاه و منشن گزارش',
                    'description' => 'در بخش دیدگاه با تایپ @، مخاطبان مجاز را انتخاب کنید.',
                    'recipient_user_id' => $recipient->id, 'status' => $status,
                    'sent_at' => $status === 'draft' ? null : now(), 'viewed_at' => $status === 'viewed' ? now() : null,
                ]);
                if ($report->wasRecentlyCreated) {
                    $report->ccUsers()->sync([$member->id, $second->id, $junior->id]);
                    if ($status === 'viewed') {
                        $comment = $report->comments()->create(['user_id' => $lead->id, 'body' => '@طاها رضایی لطفاً گزارش را بررسی کن.']);
                        $comment->mentions()->sync([$member->id]);
                    }
                }
                $reports[] = $report;
            }
            $meeting = Meeting::query()->firstOrCreate(['title' => 'LOCAL-QA | جلسه هماهنگی تیم', 'created_by' => $manager->id], [
                'short_description' => 'جلسه آزمایشی با رئیس، دبیر و اعضای تیم', 'location' => 'اتاق تست لوکال',
                'meeting_date' => today()->addDay()->toDateString(), 'start_time' => '10:00', 'duration_minutes' => 60,
                'status' => 'scheduled', 'chairman_user_id' => $manager->id, 'secretary_user_id' => $lead->id, 'submitted_at' => now(),
            ]);
            if ($meeting->wasRecentlyCreated) {
                $meeting->attendees()->sync(collect($users)->pluck('id')->all());
                $meeting->agendaItems()->create(['title' => 'بررسی اجرای تیمی', 'description' => 'تست مصوبه و برنامه‌ریزی کارها', 'sort_order' => 1]);
            }
            $this->command?->line(json_encode([
                'tasks' => collect($tasks)->map(fn ($task) => ['id' => $task->id, 'title' => $task->title])->all(),
                'reports' => collect($reports)->map(fn ($report) => ['id' => $report->id, 'title' => $report->title])->all(),
                'meeting_id' => $meeting->id,
            ], JSON_UNESCAPED_UNICODE));
        });
    }
}
