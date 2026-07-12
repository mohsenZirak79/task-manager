<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleRelation extends Model
{
    use HasFactory;

    public const TYPE_TOP_DOWN = 'top_down';

    public const TYPE_SAME_LEVEL = 'same_level';

    protected $fillable = ['parent_role_id', 'child_role_id', 'relation_type'];

    public function parentRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'parent_role_id');
    }

    public function childRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'child_role_id');
    }
}
