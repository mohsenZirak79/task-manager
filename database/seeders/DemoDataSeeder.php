<?php

namespace Database\Seeders;

use App\Enums\TaskParticipantRole;
use App\Enums\TaskStatus;
use App\Enums\TaskSubmissionType;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoDataSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'DemoPass!123';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Demo data cannot be seeded in production.');
        }

        DB::transaction(function (): void {
            [$admin, $users] = $this->seedUsers();
            $roles = $this->seedOrganization($admin, $users);
            $this->seedTasks($admin, $users, $roles);
        });

        $this->command?->info('Demo data created: 25 users, 20 org positions, 40 tasks (16 requests).');
        $this->command?->line('Demo users: demo01 ... demo24 / password: '.self::DEMO_PASSWORD);
    }

    /**
     * @return array{0: User, 1: array<int, User>}
     */
    private function seedUsers(): array
    {
        $admin = User::query()->where('is_admin', true)->first();

        if (! $admin) {
            $admin = User::query()->create([
                'org_code' => '100001',
                'username' => 'admin',
                'first_name' => 'مدیر',
                'last_name' => 'سیستم',
                'mobile' => '09120000000',
                'email' => 'admin@local.test',
                'password' => Hash::make('LocalTest!123'),
                'title' => 'مدیر سیستم',
                'is_active' => true,
                'is_admin' => true,
                'must_change_password' => false,
            ]);
        } else {
            $admin->forceFill([
                'username' => $admin->username ?: 'admin',
                'title' => $admin->title ?: 'مدیر سیستم',
                'is_active' => true,
            ])->save();
        }

        $firstNames = [
            'علی', 'زهرا', 'محمد', 'سارا', 'رضا', 'نگار', 'حسین', 'مریم',
            'امیر', 'نازنین', 'مهدی', 'الهام', 'پویا', 'شبنم', 'آرمان', 'سمیه',
            'کیوان', 'لیلا', 'میلاد', 'بهاره', 'سامان', 'رعنا', 'پارسا', 'آیدا',
        ];
        $lastNames = [
            'رضایی', 'محمدی', 'احمدی', 'کریمی', 'مرادی', 'حسینی', 'کاظمی', 'موسوی',
            'صادقی', 'جعفری', 'رستمی', 'قاسمی', 'عباسی', 'نوری', 'رحیمی', 'اکبری',
            'شریفی', 'یوسفی', 'نادری', 'سلطانی', 'حیدری', 'امینی', 'بهرامی', 'زمانی',
        ];
        $jobTitles = [
            'مدیر عملیات', 'مدیر محصول', 'مدیر پشتیبانی', 'سرپرست فروش',
            'سرپرست منابع انسانی', 'سرپرست مالی', 'سرپرست فناوری', 'کارشناس فروش',
            'کارشناس بازاریابی', 'طراح محصول', 'توسعه‌دهنده بک‌اند', 'توسعه‌دهنده فرانت‌اند',
            'کارشناس پشتیبانی', 'کارشناس تضمین کیفیت', 'کارشناس مالی', 'کارشناس منابع انسانی',
            'تحلیلگر داده', 'مدیر پروژه', 'کارشناس تدارکات', 'کارشناس آموزش',
            'کارشناس حقوقی', 'کارشناس محتوا', 'کارشناس روابط عمومی', 'کارآموز',
        ];

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
                    'password' => self::DEMO_PASSWORD,
                    'birth_date' => now()->subYears(24 + ($index % 17))->subDays($index * 11)->toDateString(),
                    'internal_phone' => (string) (100 + $index),
                    'title' => $jobTitles[$index - 1],
                    'is_active' => $index < 22,
                    'is_admin' => false,
                    'must_change_password' => false,
                    'last_login_at' => now()->subHours($index * 3),
                    'last_activity_at' => now()->subMinutes($index * 17),
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ],
            );

            if ($index <= 10) {
                $user->specialDates()->updateOrCreate(
                    ['title' => 'تولد'],
                    [
                        'date' => now()->addMonths($index)->toDateString(),
                        'description' => 'داده آزمایشی برای نمایش در پروفایل کاربر',
                    ],
                );
            }

            $users[$index] = $user;
        }

        return [$admin, $users];
    }

    /**
     * @param  array<int, User>  $users
     * @return array<int, Role>
     */
    private function seedOrganization(User $admin, array $users): array
    {
        $definitions = [
            1 => ['مدیرعامل', null, $admin],
            2 => ['معاونت عملیات', 1, $users[1]],
            3 => ['معاونت محصول و فناوری', 1, $users[2]],
            4 => ['معاونت پشتیبانی و توسعه', 1, $users[3]],
            5 => ['مدیریت فروش', 2, $users[4]],
            6 => ['مدیریت منابع انسانی', 2, $users[5]],
            7 => ['مدیریت مالی', 2, $users[6]],
            8 => ['مدیریت تدارکات', 2, $users[7]],
            9 => ['مدیریت محصول', 3, $users[8]],
            10 => ['مدیریت مهندسی', 3, $users[9]],
            11 => ['مدیریت داده', 3, $users[10]],
            12 => ['مدیریت تضمین کیفیت', 3, $users[11]],
            13 => ['مدیریت پشتیبانی مشتریان', 4, $users[12]],
            14 => ['مدیریت آموزش', 4, $users[13]],
            15 => ['مدیریت حقوقی', 4, $users[14]],
            16 => ['مدیریت روابط عمومی', 4, $users[15]],
            17 => ['تیم فروش سازمانی', 5, $users[16]],
            18 => ['تیم توسعه بک‌اند', 10, $users[17]],
            19 => ['تیم توسعه فرانت‌اند', 10, $users[18]],
            20 => ['تیم پاسخ‌گویی ویژه', 13, $users[19]],
        ];

        $roles = [];
        foreach ($definitions as $key => [$title, $parentKey, $user]) {
            $role = Role::query()->firstOrNew(['title' => $title]);
            $role->fill([
                'parent_id' => $parentKey ? $roles[$parentKey]->id : null,
                'user_id' => $user->id,
                'sort_order' => $key,
            ])->save();
            $roles[$key] = $role;
        }

        return $roles;
    }

    /**
     * @param  array<int, User>  $users
     * @param  array<int, Role>  $roles
     */
    private function seedTasks(User $admin, array $users, array $roles): void
    {
        $statuses = [
            ...array_fill(0, 8, TaskStatus::Draft),
            ...array_fill(0, 8, TaskStatus::PendingApproval),
            ...array_fill(0, 4, TaskStatus::RevisionRequested),
            ...array_fill(0, 4, TaskStatus::Rejected),
            ...array_fill(0, 8, TaskStatus::InProgress),
            ...array_fill(0, 5, TaskStatus::Completed),
            ...array_fill(0, 3, TaskStatus::NotCompleted),
        ];
        $subjects = [
            'تهیه گزارش عملکرد ماهانه', 'بازبینی برنامه فروش', 'طراحی داشبورد مدیریتی',
            'بهبود فرایند پاسخ‌گویی', 'بررسی بودجه فصل آینده', 'آماده‌سازی کمپین محصول',
            'مستندسازی فرایند استخدام', 'رفع خطاهای سامانه', 'تحلیل بازخورد مشتریان',
            'برنامه‌ریزی دوره آموزشی', 'به‌روزرسانی محتوای سایت', 'کنترل کیفیت نسخه جدید',
        ];

        foreach ($statuses as $offset => $status) {
            $index = $offset + 1;
            $isRequest = in_array($status, [
                TaskStatus::PendingApproval,
                TaskStatus::RevisionRequested,
                TaskStatus::Rejected,
            ], true);
            $submissionType = $status === TaskStatus::Draft
                ? null
                : ($isRequest ? TaskSubmissionType::Request : TaskSubmissionType::Assignment);
            $creator = $isRequest ? $users[8 + ($index % 12)] : $admin;
            $assignee = $isRequest ? $admin : $users[1 + ($index % 19)];
            $progress = match ($status) {
                TaskStatus::Completed => 100,
                TaskStatus::InProgress => 10 + (($index * 13) % 80),
                TaskStatus::NotCompleted => 35 + (($index * 7) % 45),
                default => 0,
            };
            $number = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $title = "آزمایشی {$number} - ".$subjects[$offset % count($subjects)];

            $task = Task::query()->updateOrCreate(
                ['title' => $title],
                [
                    'short_description' => $isRequest
                        ? 'درخواست آزمایشی ثبت‌شده از یکی از اعضای سازمان'
                        : 'تسک آزمایشی برای بررسی لیست، جزئیات و گردش کار',
                    'request_description' => 'این رکورد توسط DemoDataSeeder ساخته شده و برای تست رابط کاربری قابل استفاده است.',
                    'duration_minutes' => 30 + (($index % 10) * 30),
                    'due_date' => now()->addDays(1 + ($index % 30))->toDateString(),
                    'progress_percentage' => $progress,
                    'status' => $status,
                    'submission_type' => $submissionType,
                    'requester_id' => $creator->id,
                    'created_by' => $creator->id,
                    'financial_resources' => $index % 4 === 0 ? 'بودجه جاری واحد' : null,
                    'financial_estimated_cost' => $index % 4 === 0 ? 5000000 + ($index * 250000) : null,
                    'financial_provider_user_id' => $index % 4 === 0 ? $users[6]->id : null,
                    'equipment_resources' => $index % 5 === 0 ? 'لپ‌تاپ و تجهیزات اداری' : null,
                    'equipment_estimated_cost' => $index % 5 === 0 ? 15000000 + ($index * 500000) : null,
                    'equipment_provider_user_id' => $index % 5 === 0 ? $users[7]->id : null,
                    'rejection_reason' => match ($status) {
                        TaskStatus::Rejected => 'اطلاعات درخواست برای تصمیم‌گیری کافی نیست.',
                        TaskStatus::RevisionRequested => 'لطفاً زمان‌بندی و برآورد هزینه را تکمیل کنید.',
                        TaskStatus::NotCompleted => 'کار در بازه تعیین‌شده تکمیل نشد.',
                        default => null,
                    },
                ],
            );

            $task->forceFill([
                'created_at' => now()->subDays(40 - $index)->subHours($index),
                'updated_at' => now()->subHours($index % 12),
            ])->saveQuietly();

            $task->participantRecords()->delete();
            $task->participantRecords()->createMany([
                ['user_id' => $assignee->id, 'role' => TaskParticipantRole::Assignee],
                ['user_id' => $users[20 + ($index % 4)]->id, 'role' => TaskParticipantRole::Follower],
                ['user_id' => $users[4 + ($index % 4)]->id, 'role' => TaskParticipantRole::Supervisor],
            ]);

            $task->workflowHistory()->delete();
            if ($status !== TaskStatus::Draft) {
                $initialStatus = $isRequest ? TaskStatus::PendingApproval : TaskStatus::InProgress;
                $task->workflowHistory()->create([
                    'actor_id' => $creator->id,
                    'action' => 'submitted',
                    'from_status' => TaskStatus::Draft,
                    'to_status' => $initialStatus,
                ]);

                if ($status !== $initialStatus) {
                    $task->workflowHistory()->create([
                        'actor_id' => $assignee->id,
                        'action' => match ($status) {
                            TaskStatus::RevisionRequested => 'revision_requested',
                            TaskStatus::Rejected => 'rejected',
                            default => 'status_changed',
                        },
                        'from_status' => $initialStatus,
                        'to_status' => $status,
                        'reason' => $task->rejection_reason,
                        'old_progress' => $status === TaskStatus::Completed ? 75 : null,
                        'new_progress' => in_array($status, [TaskStatus::Completed, TaskStatus::NotCompleted], true)
                            ? $progress
                            : null,
                    ]);
                }
            }
        }
    }
}
