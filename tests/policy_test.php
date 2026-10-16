<?php

declare(strict_types=1);

// Teamverwaltung - von floiofficial.
// Testet die reine Rechte-Logik (ohne Panel, ohne Datenbank):  php tests/policy_test.php

$src = __DIR__ . '/../extension/team-roles/src/Support/';
require $src . 'PermissionMap.php';
require $src . 'Defaults.php';
require $src . 'Policy.php';

use TeamRoles\Support\Defaults;
use TeamRoles\Support\PermissionMap;
use TeamRoles\Support\Policy;

$failed = 0;
$total = 0;
function check(string $name, bool $ok): void
{
    global $failed, $total;
    $total++;
    if (! $ok) {
        $failed++;
        echo "FAIL  $name\n";
    }
}

$role = fn (string $slug, int $rank, array $perms) => ['slug' => $slug, 'rank' => $rank, 'permissions' => $perms];
$owner = $role('owner', 100, []);
$admin = $role('administrator', 80, Defaults::administratorPermissions());
$mod = $role('moderator', 60, Defaults::moderatorPermissions());
$none = $role('friend', 20, []);

// Pfad-Erkennung
check('parse admin', Policy::parse('api/admin/users/5') === ['area' => 'admin', 'rest' => 'users/5']);
check('parse application', Policy::parse('/api/application/servers') === ['area' => 'application', 'rest' => 'servers']);
check('parse client root', Policy::parse('api/client') === ['area' => 'client', 'rest' => '']);
check('parse fremd', Policy::parse('api/remote/servers') === null);

// Owner darf alles, auch Extensions
check('owner extensions', Policy::decideAdmin($owner, 'POST', 'extensions/foo/install')['allow']);
check('owner users delete', Policy::decideAdmin($owner, 'DELETE', 'users/9')['allow']);

// Moderator: nur Lesen
check('mod users GET', Policy::decideAdmin($mod, 'GET', 'users')['allow']);
check('mod users POST verboten', ! Policy::decideAdmin($mod, 'POST', 'users')['allow']);
check('mod users PATCH verboten', ! Policy::decideAdmin($mod, 'PATCH', 'users/3')['allow']);
check('mod servers DELETE verboten', ! Policy::decideAdmin($mod, 'DELETE', 'servers/3')['allow']);
check('mod settings GET verboten', ! Policy::decideAdmin($mod, 'GET', 'settings')['allow']);
check('mod api-keys GET verboten', ! Policy::decideAdmin($mod, 'GET', 'api-keys')['allow']);
check('mod unbekannt GET verboten (other.view fehlt)', ! Policy::decideAdmin($mod, 'GET', 'irgendwas')['allow']);
check('mod activity GET', Policy::decideAdmin($mod, 'GET', 'activity')['allow']);
check('mod activity POST verboten', ! Policy::decideAdmin($mod, 'POST', 'activity')['allow']);

// Extensions + Teamverwaltung sind fuer Nicht-Owner IMMER tabu - auch mit "allen" Rechten
$everything = $role('administrator', 80, PermissionMap::allKeys());
check('admin extensions GET verboten', ! Policy::decideAdmin($everything, 'GET', 'extensions')['allow']);
check('admin extensions install verboten', ! Policy::decideAdmin($everything, 'POST', 'extensions/x/install')['allow']);
check('admin teamverwaltung verboten', ! Policy::decideAdmin($everything, 'PUT', 'extensions/team-roles/roles/moderator')['allow']);

// Administrator (Standard)
check('admin servers DELETE', Policy::decideAdmin($admin, 'DELETE', 'servers/3')['allow']);
check('admin settings GET', Policy::decideAdmin($admin, 'GET', 'settings')['allow']);
check('admin settings PATCH verboten (Standard)', ! Policy::decideAdmin($admin, 'PATCH', 'settings')['allow']);
check('admin api-keys verboten (Standard)', ! Policy::decideAdmin($admin, 'GET', 'api-keys')['allow']);

// Rang-Pruefung / Admin-Flag nur bei Benutzer-Schreibzugriffen
$d = Policy::decideAdmin($admin, 'PATCH', 'users/7');
check('users PATCH rankCheck', $d['allow'] && $d['rankCheck'] && $d['stripRoot']);
$d = Policy::decideAdmin($admin, 'POST', 'users');
check('users POST kein rankCheck, aber strip', $d['allow'] && ! $d['rankCheck'] && $d['stripRoot']);
$d = Policy::decideAdmin($admin, 'GET', 'users/7');
check('users GET weder rank noch strip', $d['allow'] && ! $d['rankCheck'] && ! $d['stripRoot']);
$d = Policy::decideAdmin($admin, 'PATCH', 'servers/7');
check('servers PATCH kein strip', $d['allow'] && ! $d['stripRoot']);
check('targetUserId', Policy::targetUserId('users/12/foo') === 12 && Policy::targetUserId('users') === null && Policy::targetUserId('users/abc') === null);
check('writesUsers', Policy::writesUsers('admin', 'POST', 'users') && ! Policy::writesUsers('admin', 'GET', 'users') && ! Policy::writesUsers('admin', 'POST', 'servers') && ! Policy::writesUsers('client', 'POST', 'users'));

// Alias-Segmente
check('nests -> eggs', Policy::decideAdmin($admin, 'GET', 'nests/1')['resource'] === 'eggs');
check('database-hosts', Policy::decideAdmin($admin, 'GET', 'database-hosts')['resource'] === 'database-hosts');

// Client-API: Staff ohne server-access kommt nicht auf fremde Server
$c = Policy::decideClient($role('moderator', 60, ['users.view']), 'GET', 'servers/abc123', '');
check('client server braucht server-access.view', $c === ['permission' => 'server-access.view', 'serverScope' => true]);
$c = Policy::decideClient($mod, 'POST', 'servers/abc123/power', '');
check('client server POST => create', $c['permission'] === 'server-access.create');
$c = Policy::decideClient($mod, 'DELETE', 'servers/abc123/files/delete', '');
check('client server DELETE => delete', $c['permission'] === 'server-access.delete');
check('client account frei', Policy::decideClient($none, 'GET', 'account', '')['permission'] === null);
check('client admin-liste gesperrt', Policy::decideClient($none, 'GET', '', 'admin-all')['permission'] === 'server-access.view');
check('client eigene liste frei', Policy::decideClient($none, 'GET', '', '')['permission'] === null);
check('client owner frei', Policy::decideClient($owner, 'GET', 'servers/x', '')['permission'] === null);

// Rechte-Katalog
check('Defaults nur gueltige Keys', array_diff(Defaults::administratorPermissions(), PermissionMap::allKeys()) === [] && array_diff(Defaults::moderatorPermissions(), PermissionMap::allKeys()) === []);
check('Owner-Slug in Defaults', Defaults::roles()[0]['slug'] === 'owner' && Defaults::roles()[0]['rank'] === 100);
check('keine extensions.* vergebbar', array_filter(PermissionMap::allKeys(), fn ($k) => str_starts_with($k, 'extensions')) === []);

echo ($failed === 0 ? "OK" : "FEHLER") . ": $total Pruefungen, $failed fehlgeschlagen\n";
exit($failed === 0 ? 0 : 1);
