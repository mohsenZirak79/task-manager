<?php

use App\Support\AccessRoles;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('task_comments')->restrictOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['task_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['parent_id', 'created_at']);
        });

        Schema::create('task_comment_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reaction', 10);
            $table->timestamps();

            $table->unique(['task_comment_id', 'user_id']);
            $table->index(['task_comment_id', 'reaction']);
        });

        $this->syncPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comment_reactions');
        Schema::dropIfExists('task_comments');

        $permissionNames = [
            Permissions::TASKS_VIEW_ALL,
            Permissions::TASKS_COMMENT,
            Permissions::TASKS_MANAGE_COMMENTS,
        ];
        $ids = DB::table('permissions')->whereIn('name', $permissionNames)->pluck('id');
        DB::table('access_role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }

    private function syncPermissions(): void
    {
        $now = now();
        $definitions = [
            Permissions::TASKS_VIEW_ALL => 'View all tasks across management scope',
            Permissions::TASKS_COMMENT => 'Create comments and reactions on visible tasks',
            Permissions::TASKS_MANAGE_COMMENTS => 'Delete comments by other users in management scope',
        ];

        foreach ($definitions as $name => $description) {
            DB::table('permissions')->updateOrInsert(['name' => $name], [
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissions = DB::table('permissions')->whereIn('name', array_keys($definitions))->pluck('id', 'name');
        $roles = DB::table('access_roles')->whereIn('slug', [
            AccessRoles::SUPER_ADMIN,
            AccessRoles::ADMIN,
            AccessRoles::USER,
        ])->pluck('id', 'slug');

        foreach ([AccessRoles::SUPER_ADMIN, AccessRoles::ADMIN] as $slug) {
            foreach ($permissions as $permissionId) {
                DB::table('access_role_permission')->insertOrIgnore([
                    'access_role_id' => $roles[$slug],
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        DB::table('access_role_permission')->insertOrIgnore([
            'access_role_id' => $roles[AccessRoles::USER],
            'permission_id' => $permissions[Permissions::TASKS_COMMENT],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
