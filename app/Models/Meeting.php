<?php

namespace App\Models;

use App\Enums\MeetingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Meeting extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title', 'location', 'meeting_date', 'start_time', 'status',
        'chairman_user_id', 'secretary_user_id', 'created_by',
        'submitted_at', 'completed_at',
    ];

    public function chairman(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chairman_user_id');
    }

    public function secretary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'secretary_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'meeting_attendees')->withTimestamps();
    }

    public function agendaItems(): HasMany
    {
        return $this->hasMany(MeetingAgendaItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function resolutions(): HasMany
    {
        return $this->hasMany(MeetingResolution::class)->orderBy('sort_order')->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'meeting_date' => 'date',
            'start_time' => 'datetime:H:i',
            'status' => MeetingStatus::class,
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
