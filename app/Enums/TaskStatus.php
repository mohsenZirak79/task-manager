<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case RevisionRequested = 'revision_requested';
    case ReadyToStart = 'ready_to_start';
    case InProgress = 'in_progress';
    case PendingCompletionApproval = 'pending_completion_approval';
    case Completed = 'completed';
    case NotCompleted = 'not_completed';
    case Rejected = 'rejected';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'پیش‌نویس', self::PendingApproval => 'در انتظار تأیید',
            self::RevisionRequested => 'نیازمند اصلاح', self::ReadyToStart => 'آماده شروع',
            self::InProgress => 'در حال انجام', self::PendingCompletionApproval => 'در انتظار تأیید اتمام',
            self::Completed => 'تکمیل‌شده', self::NotCompleted => 'انجام‌نشده',
            self::Rejected => 'ردشده', self::Closed => 'بسته‌شده',
        };
    }

    /** Exhaustive match: a new enum case requires an explicit dashboard decision. */
    public function dashboardRules(): array
    {
        [$task, $request, $schedule] = match ($this) {
            self::Draft, self::Rejected, self::Closed, self::NotCompleted => [false, false, 'excluded'],
            self::PendingApproval, self::RevisionRequested => [false, true, 'open'],
            self::ReadyToStart, self::InProgress, self::PendingCompletionApproval => [true, true, 'open'],
            self::Completed => [false, false, 'completed'],
        };

        return ['active_task' => $task, 'active_request' => $request, 'schedule' => $schedule,
            'project_eligible' => $schedule !== 'excluded'];
    }

    public static function dashboardValues(string $rule, bool|string $value): array
    {
        return array_values(array_map(fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => $status->dashboardRules()[$rule] === $value)));
    }
}
