<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrgPosition extends Model
{
    protected $fillable = [
        'title',
        'parent_id',
        'sort_order',
        'request_up_levels',
        'assignment_down_levels',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with(['users', 'children']);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'org_position_user')->withTimestamps();
    }

    public function assignedUser(): ?User
    {
        return $this->relationLoaded('users')
            ? $this->users->first()
            : $this->users()->first();
    }

    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'sort_order' => 'integer',
            'request_up_levels' => 'integer',
            'assignment_down_levels' => 'integer',
        ];
    }
}
