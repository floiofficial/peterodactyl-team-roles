<?php

declare(strict_types=1);

namespace TeamRoles\Support;

/**
 * Team management - by floiofficial.
 *
 * Reine Entscheidungslogik (ohne Laravel, deshalb einzeln testbar).
 * The middleware only calls this class and enforces the result.
 *
 * $role = ['slug' => string, 'rank' => int, 'permissions' => list<string>]
 */
final class Policy
{
    /** @return array{area: string, rest: string}|null */
    public static function parse(string $path): ?array
    {
        if (! preg_match('#^api/(admin|application|client)(?:/(.*))?$#', trim($path, '/'), $m)) {
            return null;
        }

        return ['area' => $m[1], 'rest' => trim($m[2] ?? '', '/')];
    }

    /** Admin-API (/api/admin, /api/application). */
    public static function decideAdmin(array $role, string $method, string $rest): array
    {
        $allow = static fn (?string $resource = null, ?string $action = null, ?string $perm = null, bool $rank = false, bool $strip = false): array => [
            'allow' => true, 'reason' => '', 'resource' => $resource, 'action' => $action,
            'permission' => $perm, 'rankCheck' => $rank, 'stripRoot' => $strip,
        ];
        $deny = static fn (string $reason, ?string $resource = null, ?string $action = null): array => [
            'allow' => false, 'reason' => $reason, 'resource' => $resource, 'action' => $action,
            'permission' => null, 'rankCheck' => false, 'stripRoot' => false,
        ];

        $segments = $rest === '' ? [] : explode('/', strtolower($rest));
        $first = $segments[0] ?? '';

        if ($role['slug'] === Defaults::OWNER) {
            $resource = PermissionMap::resourceForSegment($first);

            return $allow($resource, PermissionMap::actionFor($method));
        }

        // Installing/running extensions = code on the server. Owner only, always.
        if ($first === 'extensions') {
            return $deny('Only the Owner may manage extensions and team management.');
        }

        $resource = PermissionMap::resourceForSegment($first) ?? 'other';
        $action = PermissionMap::actionFor($method);
        $meta = PermissionMap::resources()[$resource];

        if (! in_array($action, $meta['actions'], true)) {
            return $deny('This action is only allowed for the Owner in this area.', $resource, $action);
        }

        $key = $resource . '.' . $action;
        if (! in_array($key, $role['permissions'], true)) {
            return $deny('You are missing the permission "' . $key . '".', $resource, $action);
        }

        $write = PermissionMap::isWrite($method);

        return $allow(
            $resource,
            $action,
            $key,
            $resource === 'users' && $write && count($segments) >= 2,
            $resource === 'users' && $write,
        );
    }

    /**
     * Client API (/api/client). Only affects users who are admins through a role:
     * the core lets root admins into EVERY server. Here that is limited to "server-access".
     *
     * @return array{permission: ?string, serverScope: bool}
     */
    public static function decideClient(array $role, string $method, string $rest, string $listType = ''): array
    {
        $pass = ['permission' => null, 'serverScope' => false];
        if ($role['slug'] === Defaults::OWNER) {
            return $pass;
        }

        $segments = $rest === '' ? [] : explode('/', strtolower($rest));

        if ($segments === []) {
            // Dashboard-Liste "alle Server" (Admin-Ansicht).
            if (in_array(strtolower($listType), ['admin', 'admin-all'], true)) {
                return ['permission' => 'server-access.view', 'serverScope' => false];
            }

            return $pass;
        }

        if ($segments[0] === 'servers' && count($segments) >= 2) {
            return ['permission' => 'server-access.' . PermissionMap::actionFor($method), 'serverScope' => true];
        }

        return $pass;
    }

    /** true if the request creates/changes/deletes users (roles are reconciled afterwards). */
    public static function writesUsers(string $area, string $method, string $rest): bool
    {
        if (! in_array($area, ['admin', 'application'], true) || ! PermissionMap::isWrite($method)) {
            return false;
        }
        $first = strtolower(explode('/', $rest)[0] ?? '');

        return PermissionMap::resourceForSegment($first) === 'users';
    }

    /** users/123/... => 123, "users" => null, users/<not a number> => null (unclear). */
    public static function targetUserId(string $rest): ?int
    {
        $segments = explode('/', $rest);
        $candidate = $segments[1] ?? null;

        return ($candidate !== null && ctype_digit($candidate)) ? (int) $candidate : null;
    }
}
