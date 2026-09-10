<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    public const ADMIN_VISIBLE_TABS = [
        'tasks',
        'requests',
        'users',
        'organization',
        'settings',
        'contact',
    ];

    public const USER_VISIBLE_TABS = [
        'tasks',
        'requests',
        'organization',
    ];

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'org_code',
        'first_name',
        'last_name',
        'mobile',
        'email',
        'password',
        'birth_date',
        'internal_phone',
        'avatar_file_id',
        'signature_file_id',
        'title',
        'is_active',
        'is_admin',
        'is_super_admin',
        'must_change_password',
        'last_login_at',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function specialDates(): HasMany
    {
        return $this->hasMany(UserSpecialDate::class);
    }

    public function role(): HasOne
    {
        return $this->hasOne(Role::class);
    }

    public function visibleTabs(): array
    {
        return $this->is_admin ? self::ADMIN_VISIBLE_TABS : self::USER_VISIBLE_TABS;
    }

    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    public function requestedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'requester_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'is_active' => 'boolean',
            'is_admin' => 'boolean',
            'is_super_admin' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
