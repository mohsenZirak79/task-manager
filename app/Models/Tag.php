<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    protected $fillable = ['title'];

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class)->withTimestamps();
    }

    public function reports(): BelongsToMany
    {
        return $this->belongsToMany(Report::class, 'report_tag')->withTimestamps();
    }
}
