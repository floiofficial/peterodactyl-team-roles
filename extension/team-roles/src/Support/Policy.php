<?php

declare(strict_types=1);

namespace TeamRoles\Support;

/**
 * Teamverwaltung - von floiofficial.
 *
 * Reine Entscheidungslogik (ohne Laravel, deshalb einzeln testbar).
 * Die Middleware ruft nur noch diese Klasse auf und setzt das Ergebnis durch.
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

        // Extensions installieren/ausfuehren = Code auf dem Server. Immer nur Owner.
        if ($first === 'extensions') {
            return $deny('Extensions und die Teamverwaltung darf nur der Owner verwalten.');
        }

        $resource = PermissionMap::resourceForSegment($first) ?? 'other';
        $action = PermissionMap::actionFor($method);
        $meta = PermissionMap::resources()[$resource];

        if (! in_array($action, $meta['actions'], true)) {
            return $deny('Diese Aktion ist in diesem Bereich nur dem Owner erlaubt.', $resource, $action);
        }

        $key = $resource . '.' . $action;
        if (! in_array($key, $role['permissions'], true)) {
            return $deny('Dir fehlt das Recht "' . $key . '".', $resource, $action);
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
     * Client-API (/api/client). Betrifft nur Nutzer, die ueber eine Rolle Admin-Status haben:
     * Der Core laesst Root-Admins auf JEDEN Server. Hier wird das auf "server-access" begrenzt.
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

    /** true, wenn die Anfrage Benutzer anlegt/aendert/loescht (danach wird abgeglichen). */
    public static function writesUsers(string $area, string $method, string $rest): bool
    {
        if (! in_array($area, ['admin', 'application'], true) || ! PermissionMap::isWrite($method)) {
            return false;
        }
        $first = strtolower(explode('/', $rest)[0] ?? '');

        return PermissionMap::resourceForSegment($first) === 'users';
    }

    /** users/123/... => 123, "users" => null, users/<kein Zahl> => null (unklar). */
    public static function targetUserId(string $rest): ?int
    {
        $segments = explode('/', $rest);
        $candidate = $segments[1] ?? null;

        return ($candidate !== null && ctype_digit($candidate)) ? (int) $candidate : null;
    }
}
