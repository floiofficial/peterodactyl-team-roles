<?php

declare(strict_types=1);

/*
 * Team management - by floiofficial.
 *
 * Console tool for the installer/uninstaller and for emergencies.
 *
 *   php team-tool.php <panel-dir> check
 *   php team-tool.php <panel-dir> status
 *   php team-tool.php <panel-dir> snapshot <file.csv>
 *   php team-tool.php <panel-dir> resolve-owners "a@x.de,b@y.de"      (before installing: check the e-mail addresses)
 *   php team-tool.php <panel-dir> set-owners "a@x.de" [--others=administrator|moderator|friend|user|owner] [--dry-run]
 *   php team-tool.php <panel-dir> demote-staff [--dry-run]
 *   php team-tool.php <panel-dir> drop-tables
 *
 * EMERGENCY (team management broken / moderators must leave the admin area immediately):
 *   php team-tool.php /var/www/pterodactyl demote-staff
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$panel = rtrim($argv[1] ?? '', '/');
$command = $argv[2] ?? 'status';
$arg = $argv[3] ?? null;

if ($panel === '' || ! is_file($panel . '/artisan')) {
    fwrite(STDERR, "Invalid panel directory: '{$panel}' (artisan not found).\n");
    exit(2);
}

require $panel . '/vendor/autoload.php';
$app = require $panel . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const ROLES = 'ext_team_roles_roles';
const MEMBERS = 'ext_team_roles_members';
const TICKETS = 'ext_team_roles_tickets';
const TICKET_MESSAGES = 'ext_team_roles_ticket_messages';
const TICKET_READS = 'ext_team_roles_ticket_reads';
const TICKET_PREFS = 'ext_team_roles_ticket_prefs';

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
            fwrite(STDERR, "No e-mail address given.\n");
            exit(2);
        }
        $res = findUsersByEmail($emails);
        if ($res['missing'] !== []) {
            fwrite(STDERR, 'No user with this e-mail in the panel: ' . implode(', ', $res['missing']) . "\n");
            exit(3);
        }
        $ids = array_map(fn ($u) => (int) $u->id, $res['found']);
        foreach ($res['found'] as $u) {
            out(sprintf('  #%d  %s  <%s>  (%s)', $u->id, $u->username, $u->email, (int) $u->root_admin === 1 ? 'is already an admin' : 'is not an admin - becomes Owner'));
        }
        $others = DB::table('users')->where('root_admin', 1)->whereNotIn('id', $ids)->count();
        out('OTHERS=' . $others);
        break;

    case 'set-owners':
        // Only the given accounts become Owner. All other current admins / owners get --others.
        $emails = parseEmails($arg);
        $othersSlug = optionValue('--others', 'administrator');
        if ($emails === [] || ! in_array($othersSlug, ['owner', 'administrator', 'moderator', 'friend', 'user'], true)) {
            fwrite(STDERR, "Usage: set-owners \"a@x.de[,b@y.de]\" [--others=administrator|moderator|friend|user|owner]\n");
            exit(2);
        }
        if (! Schema::hasTable(MEMBERS)) {
            fwrite(STDERR, "Team management tables are missing - install first.\n");
            exit(2);
        }
        $res = findUsersByEmail($emails);
        if ($res['missing'] !== [] || $res['found'] === []) {
            fwrite(STDERR, 'No user with this e-mail in the panel: ' . implode(', ', $res['missing']) . "\n");
            exit(3);
        }
        $ownerIds = array_map(fn ($u) => (int) $u->id, $res['found']);
        $dry = in_array('--dry-run', $argv, true);

        // Who is an admin so far (or Owner according to the table) but was not chosen?
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
            out('Dry run - nothing changed.');
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
                $assign($id, 'owner');   // first: there is never a moment without an Owner
            }
            foreach ($others as $u) {
                $assign((int) $u->id, $othersSlug);
            }

            // Derive users.root_admin from the role (like the extension itself): Owner + roles with at least one admin right.
            // Support ticket rights (tickets.*) alone never make a role an admin.
            $staff = ['owner'];
            $roles = Schema::hasTable(ROLES) ? DB::table(ROLES)->get(['slug', 'permissions']) : collect();
            if ($roles->isEmpty()) {
                $staff = ['owner', 'administrator', 'moderator'];   // default roles, not created yet
            } else {
                foreach ($roles as $r) {
                    $perm = json_decode((string) $r->permissions, true);
                    if ($r->slug !== 'owner' && is_array($perm) && array_filter($perm, static fn ($p) => ! str_starts_with((string) $p, 'tickets.')) !== []) {
                        $staff[] = $r->slug;
                    }
                }
            }
            DB::table('users')->whereIn('id', fn ($q) => $q->select('user_id')->from(MEMBERS)->whereIn('role_slug', $staff))->where('root_admin', '!=', 1)->update(['root_admin' => 1]);
            DB::table('users')->whereIn('id', fn ($q) => $q->select('user_id')->from(MEMBERS)->whereNotIn('role_slug', $staff))->where('root_admin', '!=', 0)->update(['root_admin' => 0]);

            if (DB::table(MEMBERS)->where('role_slug', 'owner')->count() < 1 || DB::table('users')->where('root_admin', 1)->count() < 1) {
                throw new RuntimeException('Aborted: there would be no Owner/admin left afterwards.');
            }
        });
        out('Done: ' . count($ownerIds) . ' Owner(s) set, ' . count($others) . ' other account(s) set to ' . $othersSlug . '.');
        break;

    case 'check':
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'root_admin')) {
            fwrite(STDERR, "Table 'users' or column 'root_admin' is missing - this panel is not compatible.\n");
            exit(2);
        }
        out('OK: users.root_admin exists, database connection works.');
        break;

    case 'status':
        $admins = DB::table('users')->where('root_admin', 1)->orderBy('id')->get(['id', 'username', 'email']);
        out('Current admins (root_admin = 1): ' . count($admins));
        foreach ($admins as $a) {
            out(sprintf('  #%d  %s  <%s>', $a->id, $a->username, $a->email));
        }
        if (Schema::hasTable(MEMBERS)) {
            out('Team management installed. Roles:');
            foreach (DB::table(MEMBERS)->selectRaw('role_slug, COUNT(*) as c')->groupBy('role_slug')->get() as $r) {
                out(sprintf('  %-15s %d', $r->role_slug, $r->c));
            }
        } else {
            out('Team management: tables not present.');
        }
        break;

    case 'snapshot':
        if ($arg === null) {
            fwrite(STDERR, "Target file missing.\n");
            exit(2);
        }
        $lines = ["id,username,email,root_admin"];
        foreach (DB::table('users')->where('root_admin', 1)->orderBy('id')->get(['id', 'username', 'email', 'root_admin']) as $u) {
            $lines[] = sprintf('%d,"%s","%s",%d', $u->id, str_replace('"', '""', (string) $u->username), str_replace('"', '""', (string) $u->email), $u->root_admin);
        }
        file_put_contents($arg, implode("\n", $lines) . "\n");
        chmod($arg, 0600);
        out('Backup written: ' . $arg . ' (' . (count($lines) - 1) . ' admin accounts)');
        break;

    case 'demote-staff':
        // Everyone who is NOT an Owner loses admin status. Owners stay unchanged.
        if (! Schema::hasTable(MEMBERS)) {
            out('No team management tables present - nothing to demote.');
            break;
        }
        $query = DB::table('users')
            ->where('root_admin', 1)
            ->whereIn('id', fn ($q) => $q->select('user_id')->from(MEMBERS)->where('role_slug', '!=', 'owner'));
        $affected = $query->get(['id', 'username']);
        foreach ($affected as $u) {
            out(sprintf('  #%d %s loses admin status', $u->id, $u->username));
        }
        if (in_array('--dry-run', $argv, true)) {
            out(count($affected) . ' account(s) would be demoted (dry run, nothing changed).');
            break;
        }
        $count = DB::table('users')
            ->where('root_admin', 1)
            ->whereIn('id', fn ($q) => $q->select('user_id')->from(MEMBERS)->where('role_slug', '!=', 'owner'))
            ->update(['root_admin' => 0]);
        out($count . ' account(s) demoted. Owners stay admins.');
        break;

    case 'drop-tables':
        foreach ([TICKET_PREFS, TICKET_READS, TICKET_MESSAGES, TICKETS, MEMBERS, ROLES] as $table) {
            Schema::dropIfExists($table);
        }
        // Remove the migration records so that a later re-install creates the tables again.
        if (Schema::hasTable('migrations')) {
            DB::table('migrations')->where('migration', 'like', '%ext_team_roles%')->delete();
        }
        out('Tables removed: ' . implode(', ', [TICKET_PREFS, TICKET_READS, TICKET_MESSAGES, TICKETS, MEMBERS, ROLES]));
        break;

    default:
        fwrite(STDERR, "Unknown command '{$command}'.\n");
        exit(2);
}
