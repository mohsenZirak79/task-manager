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
        Schema::table('meeting_resolutions', function (Blueprint $table): void {
            $table->string('resolution_type', 20)->default('task')->after('description');
            $table->index('resolution_type');
        });

        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meeting_resolution_id')->unique()->constrained('meeting_resolutions')->cascadeOnDelete();
            $table->string('title');
            $table->string('short_description', 500);
            $table->text('description');
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['recipient_user_id', 'created_at']);
            $table->index(['created_by', 'created_at']);
        });

        Schema::create('report_cc_user', function (Blueprint $table): void {
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['report_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('report_tag', function (Blueprint $table): void {
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['report_id', 'tag_id']);
            $table->index('tag_id');
        });

        $this->syncPermissions();
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('name', [
            Permissions::REPORTS_VIEW,
            Permissions::REPORTS_MANAGE,
        ])->pluck('id');
        DB::table('access_role_permission')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        Schema::dropIfExists('report_tag');
        Schema::dropIfExists('report_cc_user');
        Schema::dropIfExists('reports');

        Schema::table('meeting_resolutions', function (Blueprint $table): void {
            $table->dropIndex(['resolution_type']);
            $table->dropColumn('resolution_type');
        });
    }

    private function syncPermissions(): void
    {
        $now = now();
        $definitions = [
            Permissions::REPORTS_VIEW => 'View permitted reports',
            Permissions::REPORTS_MANAGE => 'Create, update, and delete permitted reports',
        ];
        foreach ($definitions as $name => $description) {
            DB::table('permissions')->updateOrInsert(['name' => $name], [
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissionIds = DB::table('permissions')->whereIn('name', array_keys($definitions))->pluck('id', 'name');
        $roleIds = DB::table('access_roles')->whereIn('slug', [
            AccessRoles::SUPER_ADMIN, AccessRoles::ADMIN, AccessRoles::USER,
        ])->pluck('id', 'slug');

        foreach ([AccessRoles::SUPER_ADMIN, AccessRoles::ADMIN] as $slug) {
            if (! isset($roleIds[$slug])) {
                continue;
            }
            foreach ($permissionIds as $permissionId) {
                DB::table('access_role_permission')->insertOrIgnore([
                    'access_role_id' => $roleIds[$slug], 'permission_id' => $permissionId,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        if (isset($roleIds[AccessRoles::USER])) {
            DB::table('access_role_permission')->insertOrIgnore([
                'access_role_id' => $roleIds[AccessRoles::USER],
                'permission_id' => $permissionIds[Permissions::REPORTS_VIEW],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
};
