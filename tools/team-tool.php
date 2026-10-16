<?php

declare(strict_types=1);

/*
 * Teamverwaltung - von floiofficial.
 *
 * Konsolen-Werkzeug fuer Installer/Deinstaller und fuer den Notfall.
 *
 *   php team-tool.php <panel-verzeichnis> check
 *   php team-tool.php <panel-verzeichnis> status
 *   php team-tool.php <panel-verzeichnis> snapshot <datei.csv>
 *   php team-tool.php <panel-verzeichnis> demote-staff [--dry-run]
 *   php team-tool.php <panel-verzeichnis> drop-tables
 *
 * NOTFALL (Teamverwaltung kaputt / Moderatoren sollen sofort raus aus dem Admin-Bereich):
 *   php team-tool.php /var/www/pterodactyl demote-staff
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$panel = rtrim($argv[1] ?? '', '/');
$command = $argv[2] ?? 'status';
$arg = $argv[3] ?? null;

if ($panel === '' || ! is_file($panel . '/artisan')) {
    fwrite(STDERR, "Panel-Verzeichnis ungueltig: '{$panel}' (artisan nicht gefunden).\n");
    exit(2);
}

require $panel . '/vendor/autoload.php';
$app = require $panel . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const ROLES = 'ext_team_roles_roles';
const MEMBERS = 'ext_team_roles_members';

function out(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}

switch ($command) {
    case 'check':
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'root_admin')) {
            fwrite(STDERR, "Tabelle 'users' bzw. Spalte 'root_admin' fehlt - dieses Panel ist nicht kompatibel.\n");
            exit(2);
        }
        out('OK: users.root_admin vorhanden, Datenbankverbindung funktioniert.');
        break;

    case 'status':
        $admins = DB::table('users')->where('root_admin', 1)->orderBy('id')->get(['id', 'username', 'email']);
        out('Aktuelle Admins (root_admin = 1): ' . count($admins));
        foreach ($admins as $a) {
            out(sprintf('  #%d  %s  <%s>', $a->id, $a->username, $a->email));
        }
        if (Schema::hasTable(MEMBERS)) {
            out('Teamverwaltung installiert. Rollen:');
            foreach (DB::table(MEMBERS)->selectRaw('role_slug, COUNT(*) as c')->groupBy('role_slug')->get() as $r) {
                out(sprintf('  %-15s %d', $r->role_slug, $r->c));
            }
        } else {
            out('Teamverwaltung: Tabellen nicht vorhanden.');
        }
        break;

    case 'snapshot':
        if ($arg === null) {
            fwrite(STDERR, "Zieldatei fehlt.\n");
            exit(2);
        }
        $lines = ["id,username,email,root_admin"];
        foreach (DB::table('users')->where('root_admin', 1)->orderBy('id')->get(['id', 'username', 'email', 'root_admin']) as $u) {
            $lines[] = sprintf('%d,"%s","%s",%d', $u->id, str_replace('"', '""', (string) $u->username), str_replace('"', '""', (string) $u->email), $u->root_admin);
        }
        file_put_contents($arg, implode("\n", $lines) . "\n");
        chmod($arg, 0600);
        out('Sicherung geschrieben: ' . $arg . ' (' . (count($lines) - 1) . ' Admin-Konten)');
        break;

    case 'demote-staff':
        // Alle, die NICHT Owner sind, verlieren den Admin-Status. Owner bleiben unveraendert.
        if (! Schema::hasTable(MEMBERS)) {
            out('Keine Teamverwaltungs-Tabellen vorhanden - nichts herabzustufen.');
            break;
        }
        $query = DB::table('users')
            ->where('root_admin', 1)
            ->whereIn('id', fn ($q) => $q->select('user_id')->from(MEMBERS)->where('role_slug', '!=', 'owner'));
        $affected = $query->get(['id', 'username']);
        foreach ($affected as $u) {
            out(sprintf('  #%d %s verliert den Admin-Status', $u->id, $u->username));
        }
        if (in_array('--dry-run', $argv, true)) {
            out(count($affected) . ' Konto/Konten wuerden herabgestuft (Probelauf, nichts geaendert).');
            break;
        }
        $count = DB::table('users')
            ->where('root_admin', 1)
            ->whereIn('id', fn ($q) => $q->select('user_id')->from(MEMBERS)->where('role_slug', '!=', 'owner'))
            ->update(['root_admin' => 0]);
        out($count . ' Konto/Konten herabgestuft. Owner bleiben Admin.');
        break;

    case 'drop-tables':
        Schema::dropIfExists(MEMBERS);
        Schema::dropIfExists(ROLES);
        // Migrationseintrag entfernen, damit eine spaetere Neuinstallation die Tabellen wieder anlegt.
        if (Schema::hasTable('migrations')) {
            DB::table('migrations')->where('migration', 'like', '%create_ext_team_roles_tables%')->delete();
        }
        out('Tabellen ' . ROLES . ' und ' . MEMBERS . ' entfernt.');
        break;

    default:
        fwrite(STDERR, "Unbekannter Befehl '{$command}'.\n");
        exit(2);
}
