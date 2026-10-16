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
 *   php team-tool.php <panel-verzeichnis> resolve-owners "a@x.de,b@y.de"      (vor der Installation: E-Mails pruefen)
 *   php team-tool.php <panel-verzeichnis> set-owners "a@x.de" [--others=administrator|moderator|friend|user|owner] [--dry-run]
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

/** @return list<string> */
function parseEmails(?string $csv): array
{
    $list = preg_split('/[\s,;]+/', mb_strtolower((string) $csv), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    return array_values(array_unique($list));
}

/** @return array{found: list<object>, missing: list<string>} */
function findUsersByEmail(array $emails): array
{
    $found = [];
    $missing = [];
    $ids = [];
    foreach ($emails as $email) {
        $row = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->first(['id', 'username', 'email', 'root_admin']);
        if ($row === null) {
            $missing[] = $email;
        } elseif (! isset($ids[$row->id])) {
            $ids[$row->id] = true;
            $found[] = $row;
        }
    }

    return ['found' => $found, 'missing' => $missing];
}

function optionValue(string $name, ?string $default = null): ?string
{
    global $argv;
    foreach ($argv as $a) {
        if (str_starts_with($a, $name . '=')) {
            return substr($a, strlen($name) + 1);
        }
    }

    return $default;
}

switch ($command) {
    case 'resolve-owners':
        $emails = parseEmails($arg);
        if ($emails === []) {
            fwrite(STDERR, "Keine E-Mail-Adresse angegeben.\n");
            exit(2);
        }
        $res = findUsersByEmail($emails);
        if ($res['missing'] !== []) {
            fwrite(STDERR, 'Kein Benutzer mit dieser E-Mail im Panel: ' . implode(', ', $res['missing']) . "\n");
            exit(3);
        }
        $ids = array_map(fn ($u) => (int) $u->id, $res['found']);
        foreach ($res['found'] as $u) {
            out(sprintf('  #%d  %s  <%s>  (%s)', $u->id, $u->username, $u->email, (int) $u->root_admin === 1 ? 'ist bereits Admin' : 'ist kein Admin - wird Owner'));
        }
        $others = DB::table('users')->where('root_admin', 1)->whereNotIn('id', $ids)->count();
        out('OTHERS=' . $others);
        break;

    case 'set-owners':
        // Nur die genannten Konten werden Owner. Alle uebrigen bisherigen Admins bzw. Owner bekommen --others.
        $emails = parseEmails($arg);
        $othersSlug = optionValue('--others', 'administrator');
        if ($emails === [] || ! in_array($othersSlug, ['owner', 'administrator', 'moderator', 'friend', 'user'], true)) {
            fwrite(STDERR, "Aufruf: set-owners \"a@x.de[,b@y.de]\" [--others=administrator|moderator|friend|user|owner]\n");
            exit(2);
        }
        if (! Schema::hasTable(MEMBERS)) {
            fwrite(STDERR, "Teamverwaltungs-Tabellen fehlen - erst installieren.\n");
            exit(2);
        }
        $res = findUsersByEmail($emails);
        if ($res['missing'] !== [] || $res['found'] === []) {
            fwrite(STDERR, 'Kein Benutzer mit dieser E-Mail im Panel: ' . implode(', ', $res['missing']) . "\n");
            exit(3);
        }
        $ownerIds = array_map(fn ($u) => (int) $u->id, $res['found']);
        $dry = in_array('--dry-run', $argv, true);

        // Wer ist bisher Admin oder (laut Tabelle) Owner, aber nicht gewaehlt?
        $others = DB::table('users')
            ->where(function ($q) {
                $q->where('root_admin', 1)->orWhereIn('id', fn ($s) => $s->select('user_id')->from(MEMBERS)->where('role_slug', 'owner'));
            })
            ->whereNotIn('id', $ownerIds)
            ->orderBy('id')
            ->get(['id', 'username', 'email']);

        foreach ($res['found'] as $u) {
            out(sprintf('  OWNER          #%d %s <%s>', $u->id, $u->username, $u->email));
        }
        foreach ($others as $u) {
            out(sprintf('  %-14s #%d %s <%s>', strtoupper($othersSlug), $u->id, $u->username, $u->email));
        }
        if ($dry) {
            out('Probelauf - nichts geaendert.');
            break;
        }

        DB::transaction(function () use ($ownerIds, $others, $othersSlug) {
            $now = date('Y-m-d H:i:s');
            $assign = function (int $id, string $slug) use ($now) {
                if (DB::table(MEMBERS)->where('user_id', $id)->exists()) {
                    DB::table(MEMBERS)->where('user_id', $id)->update(['role_slug' => $slug, 'updated_at' => $now]);
                } else {
                    DB::table(MEMBERS)->insert(['user_id' => $id, 'role_slug' => $slug, 'created_at' => $now, 'updated_at' => $now]);
                }
            };
            foreach ($ownerIds as $id) {
                $assign($id, 'owner');   // zuerst: es gibt nie einen Moment ohne Owner
            }
            foreach ($others as $u) {
                $assign((int) $u->id, $othersSlug);
            }

            // users.root_admin aus der Rolle ableiten (wie die Extension selbst): Owner + Rollen mit mindestens einem Recht.
            $staff = ['owner'];
            $roles = Schema::hasTable(ROLES) ? DB::table(ROLES)->get(['slug', 'permissions']) : collect();
            if ($roles->isEmpty()) {
                $staff = ['owner', 'administrator', 'moderator'];   // Standard-Rollen, noch nicht angelegt
            } else {
                foreach ($roles as $r) {
                    $perm = json_decode((string) $r->permissions, true);
                    if ($r->slug !== 'owner' && is_array($perm) && $perm !== []) {
                        $staff[] = $r->slug;
                    }
                }
            }
            DB::table('users')->whereIn('id', fn ($q) => $q->select('user_id')->from(MEMBERS)->whereIn('role_slug', $staff))->where('root_admin', '!=', 1)->update(['root_admin' => 1]);
            DB::table('users')->whereIn('id', fn ($q) => $q->select('user_id')->from(MEMBERS)->whereNotIn('role_slug', $staff))->where('root_admin', '!=', 0)->update(['root_admin' => 0]);

            if (DB::table(MEMBERS)->where('role_slug', 'owner')->count() < 1 || DB::table('users')->where('root_admin', 1)->count() < 1) {
                throw new RuntimeException('Abbruch: danach waere kein Owner/Admin mehr vorhanden.');
            }
        });
        out('Fertig: ' . count($ownerIds) . ' Owner gesetzt, ' . count($others) . ' weitere Konto/Konten auf ' . $othersSlug . '.');
        break;

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
