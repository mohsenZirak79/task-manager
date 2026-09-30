<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Report extends Model
{
    protected $fillable = [
        'meeting_resolution_id', 'title', 'short_description', 'description',
        'recipient_user_id', 'created_by',
    ];

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
