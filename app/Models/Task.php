<?php

namespace App\Models;

use App\Enums\TaskParticipantRole;
use App\Enums\TaskStatus;
use App\Enums\TaskSubmissionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Task extends Model
{
    protected $fillable = [
        'title',
        'short_description',
        'request_description',
        'duration_minutes',
        'due_date',
        'progress_percentage',
        'status',
        'submission_type',
        'requester_id',
        'created_by',
        'financial_resources',
        'financial_estimated_cost',
        'financial_provider_user_id',
        'equipment_resources',
        'equipment_estimated_cost',
        'equipment_provider_user_id',
        'rejection_reason',
        'registered_at',
        'started_at',
        'completion_requested_at',
        'completed_at',
        'closed_at',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function financialProvider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'financial_provider_user_id');
    }

    public function equipmentProvider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'equipment_provider_user_id');
    }

    public function participantRecords(): HasMany
    {
        return $this->hasMany(TaskParticipant::class);
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_participants')
            ->wherePivot('role', TaskParticipantRole::Assignee->value)
            ->withTimestamps();
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_participants')
            ->wherePivot('role', TaskParticipantRole::Follower->value)
            ->withTimestamps();
    }

    public function supervisors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_participants')
            ->wherePivot('role', TaskParticipantRole::Supervisor->value)
            ->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withTimestamps();
    }

    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(MediaFile::class)->withTimestamps();
    }

    public function planningItems(): HasMany
    {
        return $this->hasMany(TaskPlanningItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    public function workflowHistory(): HasMany
    {
        return $this->hasMany(TaskWorkflowHistory::class)->latest('id');
    }

    public function meetingResolution(): HasOne
    {
        return $this->hasOne(MeetingResolution::class);
    }

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'duration_minutes' => 'integer',
            'progress_percentage' => 'integer',
            'financial_estimated_cost' => 'decimal:2',
            'equipment_estimated_cost' => 'decimal:2',
            'status' => TaskStatus::class,
            'submission_type' => TaskSubmissionType::class,
            'registered_at' => 'datetime',
            'started_at' => 'datetime',
            'completion_requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
