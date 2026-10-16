<?php

declare(strict_types=1);

// Teamverwaltung - von floiofficial.
// Testet TeamService gegen eine SQLite-Datenbank im Speicher.
// HINWEIS: Dieser Test wurde beim Erstellen NICHT ausgefuehrt (in der Entwicklungsumgebung war
// Packagist gesperrt, illuminate/database liess sich nicht installieren). Er ist ungeprueft -
// ein Fehlschlag kann auch am Test selbst liegen, nicht nur am Code.
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

// Migration aus der Extension 1:1 ausfuehren
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

// Ausgangslage wie bei einem bestehenden Panel: 1 = Betreiber (Admin), 2+3 = Kunden
Capsule::table('users')->insert([
    ['username' => 'floi', 'email' => 'floi@example.com', 'root_admin' => 1],
    ['username' => 'kunde1', 'email' => 'k1@example.com', 'root_admin' => 0],
    ['username' => 'kunde2', 'email' => 'k2@example.com', 'root_admin' => 0],
    ['username' => 'freund', 'email' => 'f@example.com', 'root_admin' => 0],
]);

$team = new TeamService($conn);

// 1. Erster Zugriff: Standard-Rollen + bisheriges Verhalten bleibt
$r = $team->roleFor((object) ['id' => 1]);
check('bestehender Admin wird Owner', $r['slug'] === 'owner' && $r['staff']);
check('Kunde ist Benutzer ohne Rechte', $team->roleFor((object) ['id' => 2])['slug'] === 'user' && ! $team->roleFor((object) ['id' => 2])['staff']);
check('5 Standardrollen angelegt', count($team->rolesList()) === 5);
check('root_admin unveraendert', root(1) === 1 && root(2) === 0);

// 2. Moderator ernennen => bekommt intern Admin-Zugang (root_admin=1), ist aber nicht Owner
$team->setMemberRole(2, 'moderator');
$mod = $team->roleFor((object) ['id' => 2]);
check('Moderator-Rolle gesetzt', $mod['slug'] === 'moderator' && $mod['rank'] === 60);
check('Moderator hat Rechte (nur Ansehen)', in_array('users.view', $mod['permissions'], true) && ! in_array('users.edit', $mod['permissions'], true));
check('Moderator bekommt root_admin=1', root(2) === 1);

// 3. Freund hat standardmaessig keinen Admin-Zugang
$team->setMemberRole(4, 'friend');
check('Freund ohne Rechte => root_admin=0', root(4) === 0);

// 4. Rechte einer Rolle aendern => Mitglieder werden mitgezogen
$team->setRolePermissions('friend', ['activity.view']);
check('Freund mit Recht => root_admin=1', root(4) === 1);
$team->setRolePermissions('friend', []);
check('Freund ohne Rechte => wieder root_admin=0', root(4) === 0);

// 5. Rechte setzen/validieren
$team->setRolePermissions('moderator', ['users.view', 'users.edit', 'servers.view']);
check('Rechte gespeichert', $team->roleFor((object) ['id' => 2])['permissions'] === ['servers.view', 'users.edit', 'users.view']);
foreach ([['extensions.view'], ['users.fly'], ['*']] as $bad) {
    try {
        $team->setRolePermissions('moderator', $bad);
        check('ungueltiges Recht abgelehnt ' . $bad[0], false);
    } catch (InvalidArgumentException) {
        check('ungueltiges Recht abgelehnt ' . $bad[0], true);
    }
}
try {
    $team->setRolePermissions('owner', ['users.view']);
    check('Owner-Rechte nicht aenderbar', false);
} catch (InvalidArgumentException) {
    check('Owner-Rechte nicht aenderbar', true);
}

// 6. Letzter Owner kann sich nicht selbst entmachten
try {
    $team->setMemberRole(1, 'administrator');
    check('letzter Owner geschuetzt', false);
} catch (DomainException) {
    check('letzter Owner geschuetzt', true);
}
$team->setMemberRole(3, 'owner');
$team->setMemberRole(1, 'administrator');
check('mit zweitem Owner darf herabgestuft werden', $team->roleFor((object) ['id' => 1])['slug'] === 'administrator' && root(1) === 1);

// 7. Manipulation: root_admin wird ausserhalb gesetzt (Kunde -> root_admin=1 ohne Rolle=Staff)
Capsule::table('users')->where('id', 4)->update(['root_admin' => 1]);
$team->reconcile();
check('Manipulation wird zurueckgesetzt', root(4) === 0);
// ...und der umgekehrte Fall: Moderator verliert root_admin ausserhalb => wird wiederhergestellt
Capsule::table('users')->where('id', 2)->update(['root_admin' => 0]);
$team->reconcile();
check('Moderator-Zugang wird wiederhergestellt', root(2) === 1);

// 8. Neuer Admin per Konsole (root_admin=1, keine Rolle) => Owner (Notfall-Zugang bleibt moeglich)
Capsule::table('users')->insert(['username' => 'cli', 'email' => 'cli@example.com', 'root_admin' => 1]);
$team->reconcile();
$cli = (int) Capsule::table('users')->where('username', 'cli')->value('id');
check('Konsolen-Admin wird Owner', $team->roleFor((object) ['id' => $cli])['slug'] === 'owner');

// 9. Geloeschter Benutzer => Rolleneintrag wird aufgeraeumt
Capsule::table('users')->where('id', 4)->delete();
$team->reconcile();
check('Waisen-Eintrag entfernt', Capsule::table('ext_team_roles_members')->where('user_id', 4)->count() === 0);

// 10. Rang
check('Rang Owner > Administrator > Moderator', $team->rankOfUserId(3) > $team->rankOfUserId(1) && $team->rankOfUserId(1) > $team->rankOfUserId(2));

// 11. Mitgliederliste
$list = $team->members('', '', 1, 25);
check('Mitgliederliste vollstaendig', $list['meta']['total'] === 4);
$search = $team->members('kunde', '', 1, 25);
check('Suche', $search['meta']['total'] === 2);
check('Rollenfilter', $team->members('', 'owner', 1, 25)['meta']['total'] === 2);
check('LIKE-Sonderzeichen sind harmlos', $team->members('%', '', 1, 25)['meta']['total'] === 0);
check('member()', $team->member(2)['role'] === 'moderator' && $team->member(999) === null);

// 12. Notfall-Befehl "demote-staff": Nicht-Owner verlieren Admin, Owner bleiben
Capsule::table('users')->whereIn('id', fn ($q) => $q->select('user_id')->from('ext_team_roles_members')->where('role_slug', '!=', 'owner'))->where('root_admin', 1)->update(['root_admin' => 0]);
check('demote-staff: Moderator + Administrator raus', root(2) === 0 && root(1) === 0);
check('demote-staff: Owner bleiben', root(3) === 1 && root($cli) === 1);

echo ($failed === 0 ? "OK" : "FEHLER") . ": $total Pruefungen, $failed fehlgeschlagen\n";
exit($failed === 0 ? 0 : 1);
