<?php

namespace Database\Seeders;

use App\Enums\MeetingStatus;
use App\Enums\TaskParticipantRole;
use App\Enums\TaskStatus;
use App\Enums\TaskSubmissionType;
use App\Models\AccessRole;
use App\Models\Meeting;
use App\Models\OrgPosition;
use App\Models\Permission;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Support\AccessRoles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LocalDemoDataSeeder extends Seeder
{
    private const PASSWORD = 'DemoPass!123';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Local demo data can only be seeded in local or testing environments.');
        }

        Artisan::call('access:sync');

        DB::transaction(function (): void {
            $admin = User::query()->where('username', 'admin.local')->firstOrFail();
            $users = $this->seedUsers($admin);
            $this->seedAccessRoles($users);
            $positions = $this->seedOrganization($admin, $users);
            $tasks = $this->seedTasks($admin, $users);
            $this->seedMeetings($admin, $users, $tasks);

            $this->command?->info(sprintf(
                'Local demo data ready: %d users, %d access roles, %d positions, %d tasks, %d meetings.',
                User::query()->count(),
                AccessRole::query()->count(),
                count($positions),
                Task::query()->count(),
                Meeting::query()->count(),
            ));
        });

        $this->command?->line('Demo login: 09130000001 / '.self::PASSWORD);
    }

    /** @return array<int, User> */
    private function seedUsers(User $admin): array
    {
        $firstNames = ['علی', 'زهرا', 'محمد', 'سارا', 'رضا', 'نگار', 'حسین', 'مریم', 'امیر', 'نازنین', 'مهدی', 'الهام', 'پویا', 'شبنم', 'آرمان', 'سمیه', 'کیوان', 'لیلا', 'میلاد', 'بهاره', 'سامان', 'رعنا', 'پارسا', 'آیدا'];
        $lastNames = ['رضایی', 'محمدی', 'احمدی', 'کریمی', 'مرادی', 'حسینی', 'کاظمی', 'موسوی', 'صادقی', 'جعفری', 'رستمی', 'قاسمی', 'عباسی', 'نوری', 'رحیمی', 'اکبری', 'شریفی', 'یوسفی', 'نادری', 'سلطانی', 'حیدری', 'امینی', 'بهرامی', 'زمانی'];
        $titles = ['مدیر عملیات', 'مدیر محصول', 'مدیر پشتیبانی', 'سرپرست فروش', 'سرپرست منابع انسانی', 'سرپرست مالی', 'سرپرست فناوری', 'کارشناس فروش', 'کارشناس بازاریابی', 'طراح محصول', 'توسعه‌دهنده بک‌اند', 'توسعه‌دهنده فرانت‌اند', 'کارشناس پشتیبانی', 'کارشناس تضمین کیفیت', 'کارشناس مالی', 'کارشناس منابع انسانی', 'تحلیلگر داده', 'مدیر پروژه', 'کارشناس تدارکات', 'کارشناس آموزش', 'کارشناس حقوقی', 'کارشناس محتوا', 'کارشناس روابط عمومی', 'کارآموز'];
        $userRoleId = AccessRole::query()->where('slug', AccessRoles::USER)->value('id');
        $users = [];

        foreach (range(1, 24) as $index) {
            $number = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $user = User::query()->updateOrCreate(
                ['username' => "demo{$number}"],
                [
                    'org_code' => (string) (200000 + $index),
                    'first_name' => $firstNames[$index - 1],
                    'last_name' => $lastNames[$index - 1],
                    'mobile' => sprintf('0913%07d', $index),
                    'email' => "demo{$number}@local.test",
                    'password' => self::PASSWORD,
                    'title' => $titles[$index - 1],
                    'is_active' => $index <= 22,
                    'must_change_password' => false,
                    'last_login_at' => now()->subHours($index * 2),
                    'last_activity_at' => now()->subMinutes($index * 13),
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ],
            );
            $user->accessRoles()->sync([$userRoleId]);
            $user->specialDates()->updateOrCreate(
                ['title' => 'سالگرد همکاری'],
                [
                    'date' => now()->addDays($index * 9)->toDateString(),
                    'description' => 'رویداد آزمایشی برای تقویم و پروفایل کاربر',
                ],
            );
            $users[$index] = $user;
        }

        return $users;
    }

    /** @param array<int, User> $users */
    private function seedAccessRoles(array $users): void
    {
        $permissionIds = Permission::query()->orderBy('id')->pluck('id')->all();

        foreach (range(1, 17) as $index) {
            $number = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $role = AccessRole::query()->updateOrCreate(
                ['slug' => "local_demo_role_{$number}"],
                ['name' => "نقش تست {$number}", 'is_system' => false],
            );

            if ($permissionIds !== []) {
                $role->permissions()->sync(array_values(array_filter(
                    $permissionIds,
                    fn (int $permissionId, int $offset): bool => ($offset + $index) % 4 === 0,
                    ARRAY_FILTER_USE_BOTH,
                )));
            }

            $users[$index]->accessRoles()->syncWithoutDetaching([$role->id]);
        }
    }

    /** @param array<int, User> $users
     * @return array<int, OrgPosition>
     */
    private function seedOrganization(User $admin, array $users): array
    {
        // Keep the local demo chart deterministic when this seeder is rerun.
        DB::table('org_position_user')->delete();
        OrgPosition::query()->update(['parent_id' => null]);
        OrgPosition::query()->delete();

        $definitions = [
            1 => ['مدیرعامل', null, $admin],
            2 => ['دفتر مدیرعامل', 1, $users[1]],
            3 => ['معاونت عملیات', 1, $users[2]],
            4 => ['معاونت محصول و فناوری', 1, $users[3]],
            5 => ['معاونت بازرگانی', 1, $users[4]],
            6 => ['مدیریت منابع انسانی', 3, $users[5]],
            7 => ['مدیریت مالی', 3, $users[6]],
            8 => ['مدیریت اداری و پشتیبانی', 3, $users[7]],
            9 => ['مدیریت محصول', 4, $users[8]],
            10 => ['مدیریت مهندسی نرم‌افزار', 4, $users[9]],
            11 => ['مدیریت داده و تحلیل', 4, $users[10]],
            12 => ['مدیریت زیرساخت', 4, $users[11]],
            13 => ['مدیریت فروش', 5, $users[12]],
            14 => ['مدیریت بازاریابی', 5, $users[13]],
            15 => ['مدیریت ارتباط با مشتریان', 5, $users[14]],
            16 => ['تیم توسعه بک‌اند', 10, $users[15]],
            17 => ['تیم توسعه فرانت‌اند', 10, $users[16]],
            18 => ['تیم تضمین کیفیت', 10, $users[17]],
            19 => ['تیم هوش تجاری', 11, $users[18]],
            20 => ['تیم دواپس', 12, $users[19]],
            21 => ['فروش سازمانی', 13, $users[20]],
            22 => ['فروش منطقه‌ای', 13, $users[21]],
            23 => ['بازاریابی دیجیتال', 14, $users[22]],
            24 => ['موفقیت مشتری', 15, $users[23]],
            25 => ['مرکز تماس', 15, $users[24]],
        ];
        $positions = [];
        $depths = [];

        foreach ($definitions as $key => [$title, $parentKey, $user]) {
            $depth = $parentKey ? $depths[$parentKey] + 1 : 0;
            $position = OrgPosition::query()->updateOrCreate(
                ['title' => $title],
                [
                    'parent_id' => $parentKey ? $positions[$parentKey]->id : null,
                    'sort_order' => $key,
                    'request_up_levels' => $depth,
                    'assignment_down_levels' => max(0, 3 - $depth),
                ],
            );
            $position->users()->sync([$user->id]);
            $positions[$key] = $position;
            $depths[$key] = $depth;
        }

        return $positions;
    }

    /** @param array<int, User> $users
     * @return array<int, Task>
     */
    private function seedTasks(User $admin, array $users): array
    {
        $tags = collect(['فوری', 'مالی', 'فروش', 'فنی', 'منابع انسانی'])
            ->mapWithKeys(fn (string $title) => [$title => Tag::query()->firstOrCreate(['title' => $title])]);
        $subjects = ['تهیه گزارش عملکرد', 'بازبینی برنامه فروش', 'طراحی داشبورد مدیریتی', 'بهبود فرایند پاسخ‌گویی', 'بررسی بودجه فصل آینده', 'آماده‌سازی کمپین محصول', 'مستندسازی فرایند استخدام', 'رفع خطاهای سامانه'];
        $tasks = [];

        foreach (range(1, 40) as $index) {
            $isDraft = $index <= 8;
            $isRequest = $index > 24;
            $submissionType = $isDraft ? null : ($isRequest ? TaskSubmissionType::Request : TaskSubmissionType::Assignment);
            $status = match (true) {
                $isDraft => TaskStatus::Draft,
                $isRequest && $index % 5 === 0 => TaskStatus::Rejected,
                $isRequest && $index % 4 === 0 => TaskStatus::RevisionRequested,
                $isRequest => TaskStatus::PendingApproval,
                $index % 6 === 0 => TaskStatus::Completed,
                default => TaskStatus::InProgress,
            };
            $creator = $isRequest ? $users[8 + ($index % 12)] : $users[1 + ($index % 19)];
            $assignee = $isRequest ? $admin : $creator;
            $number = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $task = Task::query()->updateOrCreate(
                ['title' => "LOCAL-DEMO-TASK-{$number} | ".$subjects[($index - 1) % count($subjects)]],
                [
                    'short_description' => $isRequest ? 'درخواست آزمایشی از یکی از اعضای سازمان' : 'تسک آزمایشی برای بررسی رابط کاربری',
                    'request_description' => 'این رکورد فقط برای تست محیط لوکال ساخته شده است.',
                    'duration_minutes' => 30 + (($index % 8) * 30),
                    'due_date' => now()->addDays(1 + ($index % 25))->toDateString(),
                    'progress_percentage' => $status === TaskStatus::Completed ? 100 : ($status === TaskStatus::InProgress ? ($index * 7) % 90 : 0),
                    'status' => $status,
                    'submission_type' => $submissionType,
                    'requester_id' => $creator->id,
                    'created_by' => $creator->id,
                    'rejection_reason' => in_array($status, [TaskStatus::Rejected, TaskStatus::RevisionRequested], true) ? 'نیازمند اصلاح اطلاعات و زمان‌بندی است.' : null,
                ],
            );
            $task->participantRecords()->delete();
            $task->participantRecords()->createMany([
                ['user_id' => $assignee->id, 'role' => TaskParticipantRole::Assignee],
                ['user_id' => $users[20 + ($index % 4)]->id, 'role' => TaskParticipantRole::Follower],
            ]);
            $task->tags()->sync([
                $tags->get($isRequest ? 'فوری' : 'فنی')->id,
                $tags->get($index % 2 === 0 ? 'مالی' : 'فروش')->id,
            ]);
            $task->planningItems()->delete();
            foreach ([
                ['title' => 'بررسی و جمع‌آوری اطلاعات', 'weight' => 30],
                ['title' => 'اجرای اقدام اصلی', 'weight' => 40],
                ['title' => 'کنترل نهایی و تحویل', 'weight' => 30],
            ] as $sortOrder => $planningItem) {
                $task->planningItems()->create([
                    ...$planningItem,
                    'progress_percentage' => max(0, min(100, $task->progress_percentage + (20 - ($sortOrder * 20)))),
                    'sort_order' => $sortOrder + 1,
                ]);
            }

            $commentIds = DB::table('task_comments')->where('task_id', $task->id)->pluck('id');
            DB::table('task_comment_reactions')->whereIn('task_comment_id', $commentIds)->delete();
            DB::table('task_comments')->where('task_id', $task->id)->update(['parent_id' => null]);
            DB::table('task_comments')->where('task_id', $task->id)->delete();
            $comment = $task->comments()->create([
                'user_id' => $creator->id,
                'body' => 'لطفاً وضعیت این مورد را بررسی کنید و نتیجه را در همین گفتگو بنویسید.',
            ]);
            $task->comments()->create([
                'user_id' => $assignee->id,
                'parent_id' => $comment->id,
                'body' => 'بررسی شد؛ پیشرفت کار طبق برنامه در حال ثبت است.',
            ]);
            $comment->reactions()->create([
                'user_id' => $users[20 + ($index % 4)]->id,
                'reaction' => 'like',
            ]);
            $task->workflowHistory()->delete();
            if (! $isDraft) {
                $task->workflowHistory()->create([
                    'actor_id' => $creator->id,
                    'action' => 'submitted',
                    'from_status' => TaskStatus::Draft,
                    'to_status' => $status,
                ]);
            }
            $tasks[$index] = $task;
        }

        return $tasks;
    }

    /** @param array<int, User> $users
     * @param  array<int, Task>  $tasks
     */
    private function seedMeetings(User $admin, array $users, array $tasks): void
    {
        foreach (range(1, 6) as $index) {
            $meeting = Meeting::query()->updateOrCreate(
                ['title' => "LOCAL-DEMO-MEETING-{$index} | جلسه هماهنگی شماره {$index}"],
                [
                    'short_description' => 'مرور وضعیت اقدامات و تصمیم‌گیری برای دوره بعد',
                    'location' => 'سالن جلسات مرکزی',
                    'meeting_date' => now()->addDays($index)->toDateString(),
                    'start_time' => sprintf('%02d:00', 8 + $index),
                    'status' => $index <= 4 ? MeetingStatus::Completed : MeetingStatus::Scheduled,
                    'chairman_user_id' => $admin->id,
                    'secretary_user_id' => $users[1]->id,
                    'created_by' => $admin->id,
                    'submitted_at' => now()->subDay(),
                    'completed_at' => $index <= 4 ? now() : null,
                ],
            );
            $meeting->attendees()->sync([$admin->id, $users[1]->id, $users[2]->id, $users[3]->id]);
            foreach ([1, 2] as $order) {
                $agenda = $meeting->agendaItems()->updateOrCreate(
                    ['sort_order' => $order],
                    ['title' => "دستور جلسه {$order}", 'description' => 'شرح دستور جلسه آزمایشی'],
                );
                $meeting->resolutions()->updateOrCreate(
                    ['sort_order' => $order],
                    [
                        'agenda_item_id' => $agenda->id,
                        'title' => "مصوبه {$order} جلسه {$index}",
                        'description' => 'اقدام مصوب باید تا موعد تعیین‌شده پیگیری شود.',
                        'task_id' => null,
                        'created_by' => $admin->id,
                    ],
                );
            }
        }
    }
}
