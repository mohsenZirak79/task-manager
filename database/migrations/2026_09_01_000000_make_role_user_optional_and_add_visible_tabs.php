<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
            $table->json('visible_tabs')->nullable()->after('sort_order');
        });

        // Keep the existing bootstrap administrator usable after moving access
        // control from a user flag to role configuration.
        $admin = User::query()->where('is_admin', true)->first();
        if ($admin && ! $admin->role()->exists()) {
            Role::query()->create([
                'title' => 'مدیر سامانه',
                'user_id' => $admin->id,
                'sort_order' => 0,
                'visible_tabs' => ['tasks', 'requests', 'users', 'organization', 'settings', 'contact'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn('visible_tabs');
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
