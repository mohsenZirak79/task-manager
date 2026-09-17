<?php

namespace App\Console\Commands;

use App\Models\AccessRole;
use App\Models\Permission;
use App\Support\AccessRoles;
use App\Support\Permissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncAccessControlCommand extends Command
{
    protected $signature = 'access:sync';

    protected $description = 'Idempotently synchronize built-in access roles and permissions';

    public function handle(): int
    {
        DB::transaction(function (): void {
            $permissionIds = [];
            foreach (Permissions::definitions() as $name => $description) {
                $permissionIds[$name] = Permission::query()->updateOrCreate(['name' => $name], ['description' => $description])->id;
            }

            foreach (AccessRoles::definitions() as $slug => $definition) {
                $role = AccessRole::query()->updateOrCreate(
                    ['slug' => $slug],
                    ['name' => $definition['name'], 'is_system' => $definition['is_system']],
                );
                $role->permissions()->sync(array_map(fn (string $name): int => $permissionIds[$name], $definition['permissions']));
            }
        });

        $this->info('Access roles and permissions synchronized.');

        return self::SUCCESS;
    }
}
