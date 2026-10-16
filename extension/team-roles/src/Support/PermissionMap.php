<?php

declare(strict_types=1);

namespace TeamRoles\Support;

/**
 * Teamverwaltung - von floiofficial.
 *
 * Reine Datenklasse (keine Laravel-Abhaengigkeit): beschreibt, welche Rechte es gibt
 * und welcher URL-Bereich der Admin-API zu welchem Recht gehoert.
 */
final class PermissionMap
{
    /** Aktionen, abgeleitet aus der HTTP-Methode. */
    public const ACTIONS = [
        'view' => 'Ansehen',
        'create' => 'Erstellen / Aktionen',
        'edit' => 'Bearbeiten',
        'delete' => 'Löschen',
    ];

    /** URL-Segment unter /api/admin/ bzw. /api/application/ => Recht-Bereich. */
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
            'users' => ['label' => 'Benutzer', 'hint' => 'Konten anderer Nutzer. Nur Nutzer mit niedrigerem Rang änderbar; Rollen vergibt nur der Owner.', 'danger' => false, 'actions' => $all],
            'servers' => ['label' => 'Server (Verwaltung)', 'hint' => 'Server anlegen, sperren, bearbeiten, löschen.', 'danger' => false, 'actions' => $all],
            'nodes' => ['label' => 'Nodes', 'hint' => 'Wings-Nodes und Allocations.', 'danger' => false, 'actions' => $all],
            'locations' => ['label' => 'Locations', 'hint' => '', 'danger' => false, 'actions' => $all],
            'eggs' => ['label' => 'Eggs & Nests', 'hint' => 'Inklusive Egg-Katalog.', 'danger' => false, 'actions' => $all],
            'mounts' => ['label' => 'Mounts', 'hint' => '', 'danger' => false, 'actions' => $all],
            'database-hosts' => ['label' => 'Datenbank-Hosts', 'hint' => '', 'danger' => false, 'actions' => $all],
            'tags' => ['label' => 'Tags', 'hint' => '', 'danger' => false, 'actions' => $all],
            'activity' => ['label' => 'Aktivitätslog', 'hint' => 'Nur lesbar.', 'danger' => false, 'actions' => ['view']],
            'settings' => ['label' => 'Panel-Einstellungen', 'hint' => 'Mail, Branding, Sicherheit - vorsichtig vergeben.', 'danger' => true, 'actions' => $all],
            'api-keys' => ['label' => 'API-Schlüssel', 'hint' => 'Schlüssel gelten nur im Rahmen der Rolle ihres Erstellers.', 'danger' => true, 'actions' => $all],
            'server-access' => ['label' => 'Zugriff auf fremde Server', 'hint' => 'Konsole, Dateien, Backups, Datenbanken usw. von Servern, die dem Nutzer nicht gehören.', 'danger' => true, 'actions' => $all],
            'other' => ['label' => 'Sonstige Admin-Bereiche', 'hint' => 'Nicht zugeordnete Admin-Seiten, nur lesbar.', 'danger' => false, 'actions' => ['view']],
        ];
    }

    /** @return list<string> alle vergebbaren Rechte, z.B. "users.view". */
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

    /** Aufbereitet fuer die Oberflaeche. */
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

        return ['actions' => $actions, 'resources' => $resources];
    }
}
