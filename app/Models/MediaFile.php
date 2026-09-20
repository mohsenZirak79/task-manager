<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class MediaFile extends Model
{
    protected $fillable = [
        'disk',
        'path',
        'original_name',
        'mime_type',
        'category',
        'size',
        'uploaded_by',
    ];

    protected $appends = ['url'];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class)->withTimestamps();
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
