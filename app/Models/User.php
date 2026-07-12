<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = ['org_code', 'first_name', 'last_name', 'mobile', 'email', 'password', 'birth_date', 'internal_phone', 'avatar_file_id', 'signature_file_id', 'title', 'is_active', 'must_change_password', 'last_login_at', 'created_by', 'updated_by'];

    protected $hidden = ['password', 'remember_token'];

    public function specialDates(): HasMany
    {
        return $this->hasMany(UserSpecialDate::class);
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withPivot(['id', 'started_at', 'ended_at', 'is_active'])->withTimestamps();
    }

    public function sentRelationExceptions(): HasMany
    {
        return $this->hasMany(UserRelationException::class, 'from_user_id');
    }

    public function receivedRelationExceptions(): HasMany
    {
        return $this->hasMany(UserRelationException::class, 'to_user_id');
    }

    protected function casts(): array
    {
        return ['birth_date' => 'date', 'is_active' => 'boolean', 'must_change_password' => 'boolean', 'last_login_at' => 'datetime', 'password' => 'hashed'];
    }
}
