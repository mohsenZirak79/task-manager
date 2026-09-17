<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AccessRole extends Model
{
    protected $fillable = ['name', 'slug', 'is_system'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'access_role_user')->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'access_role_permission')->withTimestamps();
    }

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }
}
