<?php

declare(strict_types=1);

namespace TeamRoles\Support;

/**
 * Teamverwaltung - von floiofficial.
 *
 * Standard-Rollen. Der Owner hat implizit ALLE Rechte (nichts davon wird gespeichert).
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
            ['slug' => self::FRIEND, 'name' => 'Freund', 'rank' => 20, 'permissions' => []],
            ['slug' => self::USER, 'name' => 'Benutzer / Kunde', 'rank' => 10, 'permissions' => []],
        ];
    }

    /** @return list<string> */
    public static function administratorPermissions(): array
    {
        $keys = [];
        foreach (PermissionMap::allKeys() as $key) {
            // Heikle Bereiche bleiben standardmaessig nur lesbar bzw. aus.
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

        return $keys;
    }
}
