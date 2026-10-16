<?php

declare(strict_types=1);

namespace TeamRoles\Support;

/**
 * Team management - by floiofficial.
 *
 * Pure data class (no Laravel dependency): describes which permissions exist and which
 * URL area of the admin API belongs to which permission.
 */
final class PermissionMap
{
    /** Actions, derived from the HTTP method. */
    public const ACTIONS = [
        'view' => 'View',
        'create' => 'Create / actions',
        'edit' => 'Edit',
        'delete' => 'Delete',
    ];

    /** URL segment below /api/admin/ or /api/application/ => permission area. */
    private const SEGMENTS = [
        'users' => 'users',
        'servers' => 'servers',
        'nodes' => 'nodes',
        'locations' => 'locations',
        'eggs' => 'eggs',
        'nests' => 'eggs',
        'egg-catalog' => 'eggs',
        'catalog' => 'eggs',
        'mounts' => 'mounts',
        'database-hosts' => 'database-hosts',
        'databasehosts' => 'database-hosts',
        'database_hosts' => 'database-hosts',
        'tags' => 'tags',
        'activity' => 'activity',
        'activities' => 'activity',
        'settings' => 'settings',
        'api-keys' => 'api-keys',
        'api_keys' => 'api-keys',
        'apikeys' => 'api-keys',
        'keys' => 'api-keys',
    ];

    /**
     * @return array<string, array{label: string, hint: string, danger: bool, actions: list<string>}>
     */
    public static function resources(): array
    {
        $all = array_keys(self::ACTIONS);

        return [
            'users' => ['label' => 'Users', 'hint' => 'Accounts of other users. Only users with a lower rank can be changed; only the Owner assigns roles.', 'danger' => false, 'actions' => $all],
            'servers' => ['label' => 'Servers (management)', 'hint' => 'Create, suspend, edit and delete servers.', 'danger' => false, 'actions' => $all],
            'nodes' => ['label' => 'Nodes', 'hint' => 'Wings nodes and allocations.', 'danger' => false, 'actions' => $all],
            'locations' => ['label' => 'Locations', 'hint' => '', 'danger' => false, 'actions' => $all],
            'eggs' => ['label' => 'Eggs & nests', 'hint' => 'Including the egg catalog.', 'danger' => false, 'actions' => $all],
            'mounts' => ['label' => 'Mounts', 'hint' => '', 'danger' => false, 'actions' => $all],
            'database-hosts' => ['label' => 'Database hosts', 'hint' => '', 'danger' => false, 'actions' => $all],
            'tags' => ['label' => 'Tags', 'hint' => '', 'danger' => false, 'actions' => $all],
            'activity' => ['label' => 'Activity log', 'hint' => 'Read-only.', 'danger' => false, 'actions' => ['view']],
            'settings' => ['label' => 'Panel settings', 'hint' => 'Mail, branding, security - grant with care.', 'danger' => true, 'actions' => $all],
            'api-keys' => ['label' => 'API keys', 'hint' => 'Keys only work within the role of their creator.', 'danger' => true, 'actions' => $all],
            'server-access' => ['label' => 'Access to other users\' servers', 'hint' => 'Console, files, backups, databases etc. of servers the user does not own.', 'danger' => true, 'actions' => $all],
            'other' => ['label' => 'Other admin areas', 'hint' => 'Admin pages not listed here, read-only.', 'danger' => false, 'actions' => ['view']],
            // Support tickets: these rights NEVER grant access to the admin area (see grantsAdminAccess()).
            'tickets' => ['label' => 'Support tickets', 'hint' => 'Everyone can always create tickets and see their own. These rights control access to OTHER people\'s tickets.', 'danger' => false, 'actions' => self::TICKET_ACTIONS],
        ];
    }

    /** Actions of the "tickets" area (own column set in the UI). */
    public const TICKET_ACTIONS = ['view', 'reply', 'manage', 'delete', 'notify'];

    public const TICKET_ACTION_LABELS = [
        'view' => 'See all tickets',
        'reply' => 'Reply to tickets',
        'manage' => 'Change status & priority',
        'delete' => 'Delete tickets',
        'notify' => 'Get notified (new tickets & replies)',
    ];

    /**
     * Does this permission list give access to the admin area?
     * Ticket rights alone must never turn someone into a (root) admin.
     *
     * @param list<string> $permissions
     */
    public static function grantsAdminAccess(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (is_string($permission) && ! str_starts_with($permission, 'tickets.')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> all grantable permissions, e.g. "users.view". */
    public static function allKeys(): array
    {
        $keys = [];
        foreach (self::resources() as $resource => $meta) {
            foreach ($meta['actions'] as $action) {
                $keys[] = $resource . '.' . $action;
            }
        }

        return $keys;
    }

    public static function isGrantable(string $key): bool
    {
        return in_array($key, self::allKeys(), true);
    }

    public static function resourceForSegment(string $segment): ?string
    {
        return self::SEGMENTS[strtolower($segment)] ?? null;
    }

    public static function isWrite(string $method): bool
    {
        return ! in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public static function actionFor(string $method): string
    {
        return match (strtoupper($method)) {
            'GET', 'HEAD', 'OPTIONS' => 'view',
            'POST' => 'create',
            'DELETE' => 'delete',
            default => 'edit',
        };
    }

    /** Prepared for the UI. */
    public static function catalogForUi(): array
    {
        $actions = [];
        foreach (self::ACTIONS as $key => $label) {
            $actions[] = ['key' => $key, 'label' => $label];
        }

        $resources = [];
        foreach (self::resources() as $key => $meta) {
            $resources[] = ['key' => $key] + $meta;
        }

        $ticketActions = [];
        foreach (self::TICKET_ACTION_LABELS as $key => $label) {
            $ticketActions[] = ['key' => $key, 'label' => $label];
        }

        return ['actions' => $actions, 'ticketActions' => $ticketActions, 'resources' => $resources];
    }
}
