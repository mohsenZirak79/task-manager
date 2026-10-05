<?php

namespace App\Models;

use App\Enums\MeetingResolutionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MeetingResolution extends Model
{
    protected $fillable = ['meeting_id', 'agenda_item_id', 'title', 'description', 'resolution_type', 'task_id', 'created_by', 'sort_order'];

    protected static function booted(): void
    {
        static::deleting(function (MeetingResolution $resolution): void {
            $resolution->report?->delete();
        });
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class)->withTrashed();
    }

    public function agendaItem(): BelongsTo
    {
        return $this->belongsTo(MeetingAgendaItem::class, 'agenda_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function report(): HasOne
    {
        return $this->hasOne(Report::class);
    }

    protected function casts(): array
    {
        return ['resolution_type' => MeetingResolutionType::class];
    }
}
