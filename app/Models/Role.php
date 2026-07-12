<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'level', 'description', 'is_active'];

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')->withPivot(['id', 'started_at', 'ended_at', 'is_active'])->withTimestamps();
    }

    public function parentRelations(): HasMany
    {
        return $this->hasMany(RoleRelation::class, 'parent_role_id');
    }

    public function childRelations(): HasMany
    {
        return $this->hasMany(RoleRelation::class, 'child_role_id');
    }

    protected function casts(): array
    {
        return ['level' => 'integer', 'is_active' => 'boolean'];
    }
}
