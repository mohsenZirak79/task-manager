<?php

namespace App\Support;

final class AccessRoles
{
    public const SUPER_ADMIN = 'super_admin';

    public const ADMIN = 'admin';

    public const USER = 'user';

    /** @return array<string, array{name: string, is_system: bool, permissions: list<string>}> */
    public static function definitions(): array
    {
        return [
            self::SUPER_ADMIN => [
                'name' => 'Super Admin',
                'is_system' => true,
                'permissions' => Permissions::all(),
            ],
            self::ADMIN => [
                'name' => 'Admin',
                'is_system' => false,
                'permissions' => Permissions::admin(),
            ],
            self::USER => [
                'name' => 'User',
                'is_system' => false,
                'permissions' => Permissions::user(),
            ],
        ];
    }
}
