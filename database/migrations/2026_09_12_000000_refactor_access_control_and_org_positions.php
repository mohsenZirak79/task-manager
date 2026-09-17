<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_positions', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->foreignId('parent_id')->nullable()->constrained('org_positions')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedTinyInteger('request_up_levels')->nullable();
            $table->unsignedTinyInteger('assignment_down_levels')->nullable();
            $table->timestamps();
            $table->index(['parent_id', 'sort_order']);
        });

        Schema::create('access_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('access_role_user', function (Blueprint $table): void {
            $table->foreignId('access_role_id')->constrained('access_roles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['access_role_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('access_role_permission', function (Blueprint $table): void {
            $table->foreignId('access_role_id')->constrained('access_roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['access_role_id', 'permission_id']);
            $table->index('permission_id');
        });

        Schema::create('org_position_user', function (Blueprint $table): void {
            $table->foreignId('org_position_id')->constrained('org_positions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique('org_position_id');
            $table->index('user_id');
        });

        DB::transaction(function (): void {
            $this->copyOrganizationPositions();
            $this->backfillAccessRoles();
        });

        Schema::disableForeignKeyConstraints();
        try {
            Schema::drop('roles');
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex(['is_admin']);
                $table->dropIndex(['is_super_admin']);
                $table->dropColumn(['is_admin', 'is_super_admin']);
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_admin')->default(false)->index();
            $table->boolean('is_super_admin')->default(false)->index();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->foreignId('parent_id')->nullable()->constrained('roles')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('visible_tabs')->nullable();
            $table->unsignedTinyInteger('request_up_levels')->nullable();
            $table->unsignedTinyInteger('assignment_down_levels')->nullable();
            $table->timestamps();
            $table->index(['parent_id', 'sort_order']);
        });

        DB::transaction(function (): void {
            $this->copyBackToRoles();
            DB::table('users')->update(['is_admin' => false, 'is_super_admin' => false]);
            DB::table('users')->whereIn('id', $this->userIdsForRole('admin'))->update(['is_admin' => true]);
            DB::table('users')->whereIn('id', $this->userIdsForRole('super_admin'))
                ->update(['is_admin' => true, 'is_super_admin' => true]);
        });

        Schema::disableForeignKeyConstraints();
        try {
            Schema::drop('org_position_user');
            Schema::drop('access_role_permission');
            Schema::drop('access_role_user');
            Schema::drop('permissions');
            Schema::drop('access_roles');
            Schema::drop('org_positions');
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function copyOrganizationPositions(): void
    {
        $remaining = DB::table('roles')->orderBy('id')->get()->keyBy('id');
        $copied = [];

        while ($remaining->isNotEmpty()) {
            $copiedInPass = false;
            foreach ($remaining as $id => $role) {
                $isDisposableSystemPosition = $role->title === 'مدیر سامانه'
                    && $role->parent_id === null
                    && $role->user_id !== null
                    && ! DB::table('roles')->where('parent_id', $role->id)->exists()
                    && DB::table('users')->where('id', $role->user_id)->where('is_super_admin', true)->exists();
                if ($isDisposableSystemPosition) {
                    $remaining->forget($id);
                    $copiedInPass = true;

                    continue;
                }

                if ($role->parent_id !== null && ! isset($copied[$role->parent_id])) {
                    continue;
                }

                DB::table('org_positions')->insert([
                    'id' => $role->id,
                    'title' => $role->title,
                    'parent_id' => $role->parent_id,
                    'sort_order' => $role->sort_order,
                    'request_up_levels' => $role->request_up_levels,
                    'assignment_down_levels' => $role->assignment_down_levels,
                    'created_at' => $role->created_at,
                    'updated_at' => $role->updated_at,
                ]);

                if ($role->user_id !== null) {
                    DB::table('org_position_user')->insert([
                        'org_position_id' => $role->id,
                        'user_id' => $role->user_id,
                        'created_at' => $role->created_at,
                        'updated_at' => $role->updated_at,
                    ]);
                }

                $copied[$id] = true;
                $remaining->forget($id);
                $copiedInPass = true;
            }

            if (! $copiedInPass) {
                throw new RuntimeException('Legacy roles contain an invalid parent reference or cycle.');
            }
        }
    }

    private function backfillAccessRoles(): void
    {
        $now = now();
        $roleIds = [];
        foreach (['super_admin' => ['Super Admin', true], 'admin' => ['Admin', false], 'user' => ['User', false]] as $slug => [$name, $isSystem]) {
            $roleIds[$slug] = DB::table('access_roles')->insertGetId([
                'name' => $name,
                'slug' => $slug,
                'is_system' => $isSystem,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (DB::table('users')->get(['id', 'is_admin', 'is_super_admin']) as $user) {
            $slug = $user->is_super_admin ? 'super_admin' : ($user->is_admin ? 'admin' : 'user');
            DB::table('access_role_user')->insert([
                'access_role_id' => $roleIds[$slug],
                'user_id' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function copyBackToRoles(): void
    {
        $remaining = DB::table('org_positions')->orderBy('id')->get()->keyBy('id');
        $copied = [];
        $assignedUsers = [];

        while ($remaining->isNotEmpty()) {
            $copiedInPass = false;
            foreach ($remaining as $id => $position) {
                if ($position->parent_id !== null && ! isset($copied[$position->parent_id])) {
                    continue;
                }

                $userId = DB::table('org_position_user')->where('org_position_id', $position->id)->value('user_id');
                if ($userId !== null && in_array((int) $userId, $assignedUsers, true)) {
                    $userId = null;
                } elseif ($userId !== null) {
                    $assignedUsers[] = (int) $userId;
                }

                DB::table('roles')->insert([
                    'id' => $position->id,
                    'title' => $position->title,
                    'parent_id' => $position->parent_id,
                    'user_id' => $userId,
                    'sort_order' => $position->sort_order,
                    'visible_tabs' => null,
                    'request_up_levels' => $position->request_up_levels,
                    'assignment_down_levels' => $position->assignment_down_levels,
                    'created_at' => $position->created_at,
                    'updated_at' => $position->updated_at,
                ]);

                $copied[$id] = true;
                $remaining->forget($id);
                $copiedInPass = true;
            }

            if (! $copiedInPass) {
                throw new RuntimeException('Organization positions contain an invalid parent reference or cycle.');
            }
        }
    }

    /** @return list<int> */
    private function userIdsForRole(string $slug): array
    {
        return DB::table('access_role_user')
            ->join('access_roles', 'access_roles.id', '=', 'access_role_user.access_role_id')
            ->where('access_roles.slug', $slug)
            ->pluck('access_role_user.user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
};
