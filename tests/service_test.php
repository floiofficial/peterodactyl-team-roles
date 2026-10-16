<?php

declare(strict_types=1);

// Team management - by floiofficial.
// Tests TeamService against an in-memory SQLite database with the REAL illuminate/database.
// NOTE: this test has NOT been executed so far (packagist was unreachable in the development
// environment, illuminate/database could not be installed). A failure may also be a bug in the test.
//   mkdir /tmp/t && cd /tmp/t && composer require illuminate/database illuminate/support
//   php tests/service_test.php /tmp/t/vendor/autoload.php

require $argv[1] ?? __DIR__ . '/../vendor/autoload.php';

$src = __DIR__ . '/../extension/team-roles/src/';
require $src . 'Support/PermissionMap.php';
require $src . 'Support/Defaults.php';
require $src . 'Services/TeamService.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use TeamRoles\Services\TeamService;

$db = new Capsule();
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db->setAsGlobal();
$conn = $db->getConnection();
$schema = $conn->getSchemaBuilder();

$schema->create('users', function (Blueprint $t) {
    $t->increments('id');
    $t->string('username');
    $t->string('email');
    $t->boolean('root_admin')->default(false);
});

// Run the extension's migration unchanged
$migration = require __DIR__ . '/../extension/team-roles/database/migrations/2026_10_16_000000_create_ext_team_roles_tables.php';
Illuminate\Support\Facades\Facade::setFacadeApplication($app = new Illuminate\Container\Container());
$app->instance('db', $db->getDatabaseManager());
$app->instance('db.schema', $schema);
$migration->up();

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
function root(int $id): int
{
    return (int) Capsule::table('users')->where('id', $id)->value('root_admin');
}

// Starting point like an existing panel: 1 = operator (admin), 2+3 = customers
Capsule::table('users')->insert([
    ['username' => 'floi', 'email' => 'floi@example.com', 'root_admin' => 1],
    ['username' => 'customer1', 'email' => 'k1@example.com', 'root_admin' => 0],
    ['username' => 'customer2', 'email' => 'k2@example.com', 'root_admin' => 0],
    ['username' => 'friend', 'email' => 'f@example.com', 'root_admin' => 0],
]);

$team = new TeamService($conn);

// 1. First access: default roles + existing behaviour stays
$r = $team->roleFor((object) ['id' => 1]);
check('existing admin becomes Owner', $r['slug'] === 'owner' && $r['staff']);
check('customer is a user without rights', $team->roleFor((object) ['id' => 2])['slug'] === 'user' && ! $team->roleFor((object) ['id' => 2])['staff']);
check('5 default roles created', count($team->rolesList()) === 5);
check('root_admin unchanged', root(1) === 1 && root(2) === 0);

// 2. Appoint a moderator => gets internal admin access (root_admin=1) but is not an Owner
$team->setMemberRole(2, 'moderator');
$mod = $team->roleFor((object) ['id' => 2]);
check('moderator role set', $mod['slug'] === 'moderator' && $mod['rank'] === 60);
check('moderator has rights (view only)', in_array('users.view', $mod['permissions'], true) && ! in_array('users.edit', $mod['permissions'], true));
check('moderator gets root_admin=1', root(2) === 1);

// 3. A friend has no admin access by default - even though the friend role has ticket rights
$team->setMemberRole(4, 'friend');
check('friend (ticket rights only) => root_admin=0', root(4) === 0);
check('friend has the default ticket rights', in_array('tickets.view', $team->roleFor((object) ['id' => 4])['permissions'], true) && ! $team->roleFor((object) ['id' => 4])['staff']);

// 4. Changing the rights of a role updates its members
$team->setRolePermissions('friend', ['tickets.view', 'tickets.manage', 'tickets.delete']);
check('only ticket rights => still root_admin=0', root(4) === 0);
$team->setRolePermissions('friend', ['tickets.view', 'activity.view']);
check('friend with a non-ticket right => root_admin=1', root(4) === 1);
$team->setRolePermissions('friend', ['tickets.view', 'tickets.reply', 'tickets.notify']);
check('friend back to ticket rights only => root_admin=0', root(4) === 0);

// 5. Set / validate rights
$team->setRolePermissions('moderator', ['users.view', 'users.edit', 'servers.view']);
check('rights saved', $team->roleFor((object) ['id' => 2])['permissions'] === ['servers.view', 'users.edit', 'users.view']);
foreach ([['extensions.view'], ['users.fly'], ['*']] as $bad) {
    try {
        $team->setRolePermissions('moderator', $bad);
        check('invalid right rejected ' . $bad[0], false);
    } catch (InvalidArgumentException) {
        check('invalid right rejected ' . $bad[0], true);
    }
}
try {
    $team->setRolePermissions('owner', ['users.view']);
    check('Owner rights cannot be changed', false);
} catch (InvalidArgumentException) {
    check('Owner rights cannot be changed', true);
}

// 6. The last Owner cannot demote themselves
try {
    $team->setMemberRole(1, 'administrator');
    check('last Owner protected', false);
} catch (DomainException) {
    check('last Owner protected', true);
}
$team->setMemberRole(3, 'owner');
$team->setMemberRole(1, 'administrator');
check('with a second Owner demotion is allowed', $team->roleFor((object) ['id' => 1])['slug'] === 'administrator' && root(1) === 1);

// 7. Tampering: root_admin set from outside (customer -> root_admin=1 without a staff role)
Capsule::table('users')->where('id', 4)->update(['root_admin' => 1]);
$team->reconcile();
check('tampering is reverted', root(4) === 0);
// ...and the reverse: moderator loses root_admin from outside => restored
Capsule::table('users')->where('id', 2)->update(['root_admin' => 0]);
$team->reconcile();
check('moderator access is restored', root(2) === 1);

// 8. New admin created on the console (root_admin=1, no role) => Owner (emergency access stays possible)
Capsule::table('users')->insert(['username' => 'cli', 'email' => 'cli@example.com', 'root_admin' => 1]);
$team->reconcile();
$cli = (int) Capsule::table('users')->where('username', 'cli')->value('id');
check('console admin becomes Owner', $team->roleFor((object) ['id' => $cli])['slug'] === 'owner');

// 9. Deleted user => role entry is cleaned up
Capsule::table('users')->where('id', 4)->delete();
$team->reconcile();
check('orphan entry removed', Capsule::table('ext_team_roles_members')->where('user_id', 4)->count() === 0);

// 10. Rank
check('rank Owner > Administrator > Moderator', $team->rankOfUserId(3) > $team->rankOfUserId(1) && $team->rankOfUserId(1) > $team->rankOfUserId(2));

// 11. Member list
$list = $team->members('', '', 1, 25);
check('member list complete', $list['meta']['total'] === 4);
$search = $team->members('customer', '', 1, 25);
check('search', $search['meta']['total'] === 2);
check('role filter', $team->members('', 'owner', 1, 25)['meta']['total'] === 2);
check('LIKE wildcards are harmless', $team->members('%', '', 1, 25)['meta']['total'] === 0);
check('member()', $team->member(2)['role'] === 'moderator' && $team->member(999) === null);

// 12. Emergency command "demote-staff": non-owners lose admin, owners stay
Capsule::table('users')->whereIn('id', fn ($q) => $q->select('user_id')->from('ext_team_roles_members')->where('role_slug', '!=', 'owner'))->where('root_admin', 1)->update(['root_admin' => 0]);
check('demote-staff: moderator + administrator out', root(2) === 0 && root(1) === 0);
check('demote-staff: owners stay', root(3) === 1 && root($cli) === 1);

echo ($failed === 0 ? "OK" : "FAILED") . ": $total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
