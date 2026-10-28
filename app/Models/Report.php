<?php

namespace App\Models;

use App\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class Report extends Model
{
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'source_report_id');
    }

    protected $fillable = [
        'meeting_resolution_id', 'title', 'short_description', 'description',
        'action_type', 'recipient_user_id', 'created_by', 'status', 'previous_status', 'sent_at', 'viewed_at', 'submission_key',
    ];

    protected function casts(): array
    {
        return ['status' => ReportStatus::class, 'previous_status' => ReportStatus::class, 'sent_at' => 'datetime', 'viewed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(function (Report $report): void {
            if ($report->tasks()->exists()) {
                throw new ConflictHttpException('گزارش دارای تسک مرتبط قابل حذف نیست؛ می‌توانید آن را غیرفعال کنید.');
            }
            // Remove self references before the report FK cascades to comments.
            $report->comments()->withTrashed()->update(['parent_id' => null]);
        });
        static::created(function (Report $report): void {
            $report->report_number = 'RPT-'.str_pad((string) $report->id, 6, '0', STR_PAD_LEFT);
            $report->saveQuietly();
        });
        static::updating(function (Report $report): void {
            if ($report->isDirty('report_number')) {
                throw new \LogicException('شماره گزارش قابل تغییر نیست.');
            }
        });
    }

    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(MediaFile::class)->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    public function resolution(): BelongsTo
    {
        return $this->belongsTo(MeetingResolution::class, 'meeting_resolution_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function ccUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'report_cc_user')->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'report_tag')->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
