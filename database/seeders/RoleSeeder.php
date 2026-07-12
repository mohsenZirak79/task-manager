<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\RoleRelation;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $ceo = Role::query()->updateOrCreate(['name' => 'مدیرعامل'], ['level' => 1, 'is_active' => true]);
            $manager = Role::query()->updateOrCreate(['name' => 'مدیر واحد'], ['level' => 2, 'is_active' => true]);
            $expert = Role::query()->updateOrCreate(['name' => 'کارشناس'], ['level' => 3, 'is_active' => true]);
            $support = Role::query()->updateOrCreate(['name' => 'پشتیبان'], ['level' => 3, 'is_active' => true]);
            RoleRelation::query()->firstOrCreate(['parent_role_id' => $ceo->id, 'child_role_id' => $manager->id, 'relation_type' => 'top_down']);
            RoleRelation::query()->firstOrCreate(['parent_role_id' => $manager->id, 'child_role_id' => $expert->id, 'relation_type' => 'top_down']);
            RoleRelation::query()->firstOrCreate(['parent_role_id' => $expert->id, 'child_role_id' => $support->id, 'relation_type' => 'same_level']);
            $admin = User::query()->where('email', 'admin@example.com')->first();
            if ($admin && ! UserRole::query()->where('user_id', $admin->id)->where('role_id', $ceo->id)->where('is_active', true)->exists()) {
                UserRole::query()->create(['user_id' => $admin->id, 'role_id' => $ceo->id, 'started_at' => now(), 'is_active' => true]);
            }
        });
    }
}
