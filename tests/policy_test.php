<?php

declare(strict_types=1);

// Team management - by floiofficial.
// Tests the pure permission logic (no panel, no database):  php tests/policy_test.php

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

// Path detection
check('parse admin', Policy::parse('api/admin/users/5') === ['area' => 'admin', 'rest' => 'users/5']);
check('parse application', Policy::parse('/api/application/servers') === ['area' => 'application', 'rest' => 'servers']);
check('parse client root', Policy::parse('api/client') === ['area' => 'client', 'rest' => '']);
check('parse foreign', Policy::parse('api/remote/servers') === null);

// Owner darf alles, auch Extensions
check('owner extensions', Policy::decideAdmin($owner, 'POST', 'extensions/foo/install')['allow']);
check('owner users delete', Policy::decideAdmin($owner, 'DELETE', 'users/9')['allow']);

// Moderator: nur Lesen
check('mod users GET', Policy::decideAdmin($mod, 'GET', 'users')['allow']);
check('mod users POST forbidden', ! Policy::decideAdmin($mod, 'POST', 'users')['allow']);
check('mod users PATCH forbidden', ! Policy::decideAdmin($mod, 'PATCH', 'users/3')['allow']);
check('mod servers DELETE forbidden', ! Policy::decideAdmin($mod, 'DELETE', 'servers/3')['allow']);
check('mod settings GET forbidden', ! Policy::decideAdmin($mod, 'GET', 'settings')['allow']);
check('mod api-keys GET forbidden', ! Policy::decideAdmin($mod, 'GET', 'api-keys')['allow']);
check('mod unknown GET forbidden (other.view fehlt)', ! Policy::decideAdmin($mod, 'GET', 'irgendwas')['allow']);
check('mod activity GET', Policy::decideAdmin($mod, 'GET', 'activity')['allow']);
check('mod activity POST forbidden', ! Policy::decideAdmin($mod, 'POST', 'activity')['allow']);

// Extensions + team management are ALWAYS off limits for non-owners - even with "all" rights
$everything = $role('administrator', 80, PermissionMap::allKeys());
check('admin extensions GET forbidden', ! Policy::decideAdmin($everything, 'GET', 'extensions')['allow']);
check('admin extensions install forbidden', ! Policy::decideAdmin($everything, 'POST', 'extensions/x/install')['allow']);
check('admin team management forbidden', ! Policy::decideAdmin($everything, 'PUT', 'extensions/team-roles/roles/moderator')['allow']);

// Administrator (default)
check('admin servers DELETE', Policy::decideAdmin($admin, 'DELETE', 'servers/3')['allow']);
check('admin settings GET', Policy::decideAdmin($admin, 'GET', 'settings')['allow']);
check('admin settings PATCH forbidden (default)', ! Policy::decideAdmin($admin, 'PATCH', 'settings')['allow']);
check('admin api-keys forbidden (default)', ! Policy::decideAdmin($admin, 'GET', 'api-keys')['allow']);

// Rank check / admin flag only on user write requests
$d = Policy::decideAdmin($admin, 'PATCH', 'users/7');
check('users PATCH rankCheck', $d['allow'] && $d['rankCheck'] && $d['stripRoot']);
$d = Policy::decideAdmin($admin, 'POST', 'users');
check('users POST no rankCheck, but strip', $d['allow'] && ! $d['rankCheck'] && $d['stripRoot']);
$d = Policy::decideAdmin($admin, 'GET', 'users/7');
check('users GET weder rank noch strip', $d['allow'] && ! $d['rankCheck'] && ! $d['stripRoot']);
$d = Policy::decideAdmin($admin, 'PATCH', 'servers/7');
check('servers PATCH no strip', $d['allow'] && ! $d['stripRoot']);
check('targetUserId', Policy::targetUserId('users/12/foo') === 12 && Policy::targetUserId('users') === null && Policy::targetUserId('users/abc') === null);
check('writesUsers', Policy::writesUsers('admin', 'POST', 'users') && ! Policy::writesUsers('admin', 'GET', 'users') && ! Policy::writesUsers('admin', 'POST', 'servers') && ! Policy::writesUsers('client', 'POST', 'users'));

// Alias-Segmente
check('nests -> eggs', Policy::decideAdmin($admin, 'GET', 'nests/1')['resource'] === 'eggs');
check('database-hosts', Policy::decideAdmin($admin, 'GET', 'database-hosts')['resource'] === 'database-hosts');

// Client API: staff without server-access cannot reach foreign servers
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

// Permission catalog
check('Defaults nur gueltige Keys', array_diff(Defaults::administratorPermissions(), PermissionMap::allKeys()) === [] && array_diff(Defaults::moderatorPermissions(), PermissionMap::allKeys()) === []);
check('Owner slug in Defaults', Defaults::roles()[0]['slug'] === 'owner' && Defaults::roles()[0]['rank'] === 100);
check('no extensions.* grantable', array_filter(PermissionMap::allKeys(), fn ($k) => str_starts_with($k, 'extensions')) === []);

// ticket rights never open admin API areas
$friendTickets = $role('friend', 20, Defaults::friendPermissions());
check('friend with ticket rights: admin API GET forbidden', ! Policy::decideAdmin($friendTickets, 'GET', 'users')['allow']);
check('moderator ticket rights do not unlock servers.edit', ! Policy::decideAdmin($mod, 'PATCH', 'servers/3')['allow']);
check('"tickets" is not an admin API segment', PermissionMap::resourceForSegment('tickets') === null);
check('ticket keys are grantable', PermissionMap::isGrantable('tickets.view') && PermissionMap::isGrantable('tickets.notify'));
check('moderator default has ticket view/reply/notify', in_array('tickets.view', Defaults::moderatorPermissions(), true) && in_array('tickets.notify', Defaults::moderatorPermissions(), true));
check('client API ticket routes pass for staff (no server scope)', Policy::decideClient($mod, 'POST', 'extensions/team-roles/tickets') === ['permission' => null, 'serverScope' => false]);

echo ($failed === 0 ? "OK" : "FAILED") . ": $total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
