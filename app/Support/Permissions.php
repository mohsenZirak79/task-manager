<?php

namespace App\Support;

final class Permissions
{
    public const USERS_VIEW = 'users:view';

    public const USERS_CREATE = 'users:create';

    public const USERS_UPDATE = 'users:update';

    public const USERS_DELETE = 'users:delete';

    public const USERS_ACTIVATE = 'users:activate';

    public const USERS_RESET_PASSWORD = 'users:reset_password';

    public const USERS_MANAGE_ACCESS = 'users:manage_access';

    public const ORG_POSITIONS_VIEW = 'org_positions:view';

    public const ORG_POSITIONS_CREATE = 'org_positions:create';

    public const ORG_POSITIONS_UPDATE = 'org_positions:update';

    public const ORG_POSITIONS_DELETE = 'org_positions:delete';

    public const TASKS_VIEW = 'tasks:view';

    public const TASKS_CREATE = 'tasks:create';

    public const TASKS_UPDATE = 'tasks:update';

    public const TASKS_APPROVE = 'tasks:approve';

    public const TASKS_REJECT = 'tasks:reject';

    public const TASKS_UPDATE_STATUS = 'tasks:update_status';

    public const TASKS_UPDATE_PROGRESS = 'tasks:update_progress';

    public const TASKS_DELETE = 'tasks:delete';

    public const MEETINGS_VIEW = 'meetings:view';

    public const MEETINGS_CREATE = 'meetings:create';

    public const MEETINGS_UPDATE = 'meetings:update';

    public const MEETINGS_DELETE = 'meetings:delete';

    public const MEETINGS_COMPLETE = 'meetings:complete';

    public const MEETINGS_MANAGE_RESOLUTIONS = 'meetings:manage_resolutions';

    public const SYSTEM_ACCESS_ADMIN_SECTIONS = 'system:access_admin_sections';

    /** @return array<string, string> */
    public static function definitions(): array
    {
        return [
            self::USERS_VIEW => 'View users',
            self::USERS_CREATE => 'Create users',
            self::USERS_UPDATE => 'Update users',
            self::USERS_DELETE => 'Delete users',
            self::USERS_ACTIVATE => 'Activate or deactivate users',
            self::USERS_RESET_PASSWORD => 'Reset user passwords',
            self::USERS_MANAGE_ACCESS => 'Assign access roles to users',
            self::ORG_POSITIONS_VIEW => 'View the organization chart',
            self::ORG_POSITIONS_CREATE => 'Create organization positions',
            self::ORG_POSITIONS_UPDATE => 'Update organization positions',
            self::ORG_POSITIONS_DELETE => 'Delete organization positions',
            self::TASKS_VIEW => 'View permitted tasks',
            self::TASKS_CREATE => 'Create tasks',
            self::TASKS_UPDATE => 'Update permitted tasks',
            self::TASKS_APPROVE => 'Approve requests',
            self::TASKS_REJECT => 'Reject or request revision of requests',
            self::TASKS_UPDATE_STATUS => 'Update task status',
            self::TASKS_UPDATE_PROGRESS => 'Update task progress',
            self::TASKS_DELETE => 'Delete own draft tasks',
            self::MEETINGS_VIEW => 'View permitted meetings',
            self::MEETINGS_CREATE => 'Create meetings',
            self::MEETINGS_UPDATE => 'Update permitted meetings',
            self::MEETINGS_DELETE => 'Delete permitted meetings',
            self::MEETINGS_COMPLETE => 'Complete permitted meetings',
            self::MEETINGS_MANAGE_RESOLUTIONS => 'Manage meeting resolutions and tasks',
            self::SYSTEM_ACCESS_ADMIN_SECTIONS => 'Access administration-only settings and contact sections',
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::definitions());
    }

    /** @return list<string> */
    public static function admin(): array
    {
        return self::all();
    }

    /** @return list<string> */
    public static function user(): array
    {
        return [
            self::ORG_POSITIONS_VIEW,
            self::MEETINGS_VIEW,
            self::MEETINGS_CREATE,
            self::TASKS_VIEW,
            self::TASKS_CREATE,
            self::TASKS_UPDATE,
            self::TASKS_DELETE,
            self::TASKS_APPROVE,
            self::TASKS_REJECT,
            self::TASKS_UPDATE_STATUS,
            self::TASKS_UPDATE_PROGRESS,
        ];
    }
}
