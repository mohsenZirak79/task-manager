<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetingAgendaItem extends Model
{
    protected $fillable = ['meeting_id', 'title', 'description', 'sort_order'];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function resolutions(): HasMany
    {
        return $this->hasMany(MeetingResolution::class, 'agenda_item_id');
    }
}
