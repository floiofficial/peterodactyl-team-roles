<?php

declare(strict_types=1);

namespace TeamRoles\Support;

/**
 * Team management - by floiofficial.
 *
 * Default roles. The Owner implicitly has ALL permissions (nothing of that is stored).
 */
final class Defaults
{
    public const OWNER = 'owner';
    public const ADMINISTRATOR = 'administrator';
    public const MODERATOR = 'moderator';
    public const FRIEND = 'friend';
    public const USER = 'user';

    /** @return list<array{slug: string, name: string, rank: int, permissions: list<string>}> */
    public static function roles(): array
    {
        return [
            ['slug' => self::OWNER, 'name' => 'Owner', 'rank' => 100, 'permissions' => []],
            ['slug' => self::ADMINISTRATOR, 'name' => 'Administrator', 'rank' => 80, 'permissions' => self::administratorPermissions()],
            ['slug' => self::MODERATOR, 'name' => 'Moderator', 'rank' => 60, 'permissions' => self::moderatorPermissions()],
            ['slug' => self::FRIEND, 'name' => 'Friend', 'rank' => 20, 'permissions' => self::friendPermissions()],
            ['slug' => self::USER, 'name' => 'User / Customer', 'rank' => 10, 'permissions' => []],
        ];
    }

    /** @return list<string> */
    public static function administratorPermissions(): array
    {
        $keys = [];
        foreach (PermissionMap::allKeys() as $key) {
            // Sensitive areas stay read-only / off by default.
            if (str_starts_with($key, 'api-keys.')) {
                continue;
            }
            if (str_starts_with($key, 'settings.') && $key !== 'settings.view') {
                continue;
            }
            $keys[] = $key;
        }

        return $keys;
    }

    /** @return list<string> */
    public static function moderatorPermissions(): array
    {
        $keys = [];
        foreach (['users', 'servers', 'nodes', 'locations', 'eggs', 'mounts', 'database-hosts', 'tags', 'activity', 'server-access'] as $resource) {
            $keys[] = $resource . '.view';
        }

        return array_merge($keys, self::staffTicketPermissions());
    }

    /** @return list<string> */
    public static function friendPermissions(): array
    {
        return self::staffTicketPermissions();
    }

    /** Tickets: see, reply, get notified (no manage/delete by default). @return list<string> */
    public static function staffTicketPermissions(): array
    {
        return ['tickets.view', 'tickets.reply', 'tickets.notify'];
    }
}
