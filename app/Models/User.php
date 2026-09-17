<?php

namespace App\Models;

use App\Support\AccessRoles;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    protected $fillable = [
        'org_code',
        'username',
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
        'must_change_password',
        'last_login_at',
        'last_activity_at',
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

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'avatar_file_id');
    }

    public function accessRoles(): BelongsToMany
    {
        return $this->belongsToMany(AccessRole::class, 'access_role_user')->withTimestamps();
    }

    public function orgPositions(): BelongsToMany
    {
        return $this->belongsToMany(OrgPosition::class, 'org_position_user')->withTimestamps();
    }

    public function hasAccessRole(string $slug): bool
    {
        return $this->relationLoaded('accessRoles')
            ? $this->accessRoles->contains('slug', $slug)
            : $this->accessRoles()->where('slug', $slug)->exists();
    }

    public function isSuperAdmin(): bool
    {
        $matches = fn (AccessRole $role): bool => $role->slug === AccessRoles::SUPER_ADMIN && $role->is_system;

        return $this->relationLoaded('accessRoles')
            ? $this->accessRoles->contains($matches)
            : $this->accessRoles()->where('slug', AccessRoles::SUPER_ADMIN)->where('is_system', true)->exists();
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($this->relationLoaded('accessRoles') && $this->accessRoles->every->relationLoaded('permissions')) {
            return $this->accessRoles->contains(
                fn (AccessRole $role): bool => $role->permissions->contains('name', $permission),
            );
        }

        return $this->accessRoles()->whereHas('permissions', fn ($query) => $query->where('name', $permission))->exists();
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        if ($this->isSuperAdmin()) {
            return Permission::query()->orderBy('name')->pluck('name')->all();
        }

        if ($this->relationLoaded('accessRoles') && $this->accessRoles->every->relationLoaded('permissions')) {
            return $this->accessRoles->flatMap->permissions->pluck('name')->unique()->sort()->values()->all();
        }

        return Permission::query()
            ->whereHas('accessRoles.users', fn ($query) => $query->whereKey($this->getKey()))
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    public function requestedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'requester_id');
    }

    public function assignedTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_participants')
            ->wherePivot('role', 'assignee')
            ->withTimestamps();
    }

    public function attendedMeetings(): BelongsToMany
    {
        return $this->belongsToMany(Meeting::class, 'meeting_attendees')->withTimestamps();
    }

    public function createdMeetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'created_by');
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
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
