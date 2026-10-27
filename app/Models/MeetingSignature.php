<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingSignature extends Model
{
    public $timestamps = false;

    protected $fillable = ['meeting_id', 'user_id', 'content_version', 'content_snapshot', 'signed_at'];

    protected $hidden = ['content_snapshot'];

    protected function casts(): array
    {
        return ['content_snapshot' => 'array', 'signed_at' => 'datetime'];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
