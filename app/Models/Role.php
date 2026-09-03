<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = [
        'title',
        'parent_id',
        'user_id',
        'sort_order',
        'visible_tabs',
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
            ->with(['user', 'children']);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'user_id' => 'integer',
            'sort_order' => 'integer',
            'visible_tabs' => 'array',
            'request_up_levels' => 'integer',
            'assignment_down_levels' => 'integer',
        ];
    }
}
