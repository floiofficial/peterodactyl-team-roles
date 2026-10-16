<?php

declare(strict_types=1);

// Team management - by floiofficial.
// Tests TicketService against SQLite using a tiny stand-in for Laravel's query builder
// (tests/support/mini_laravel.php). Run: php tests/ticket_service_test.php
// NOTE: the stand-in is not Laravel; this tests the logic, not the real query builder.

require __DIR__ . '/support/mini_laravel.php';
$src = __DIR__ . '/../extension/team-roles/src/';
require $src . 'Support/PermissionMap.php';
require $src . 'Support/Defaults.php';
require $src . 'Support/TicketRules.php';
require $src . 'Services/TicketService.php';

use MiniLaravel\Connection;
use TeamRoles\Services\TicketService;
use TeamRoles\Support\Defaults;
use TeamRoles\Support\TicketRules as R;

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

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)');
$pdo->exec('CREATE TABLE ext_team_roles_tickets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, subject TEXT, description TEXT, priority TEXT DEFAULT "normal", status TEXT DEFAULT "open", activity_seq INT DEFAULT 1, last_actor_id INT, last_activity_at TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE ext_team_roles_ticket_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INT, user_id INT, body TEXT, is_staff INT DEFAULT 0, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE ext_team_roles_ticket_reads (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INT, user_id INT, seen_seq INT DEFAULT 0, created_at TEXT, updated_at TEXT, UNIQUE(ticket_id, user_id))');
$pdo->exec('CREATE TABLE ext_team_roles_ticket_prefs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT UNIQUE, email_notifications INT DEFAULT 1, created_at TEXT, updated_at TEXT)');
foreach ([[1, 'floi'], [2, 'friend1'], [3, 'mod1'], [4, 'customer1'], [5, 'customer2']] as $u) {
    $pdo->prepare('INSERT INTO users VALUES (?, ?)')->execute($u);
}

$svc = new TicketService(new Connection($pdo));
$role = fn (string $slug, array $perms) => ['slug' => $slug, 'permissions' => $perms];
$owner = $role('owner', []);
$friend = $role('friend', Defaults::friendPermissions());
$mod = $role('moderator', Defaults::moderatorPermissions());
$customer = $role('user', []);
$viewOnly = $role('friend', ['tickets.view']);
$notifyNoView = $role('friend', ['tickets.notify']);
$new = fn (string $subject, string $prio = 'normal') => ['subject' => $subject, 'description' => 'something is broken here', 'priority' => $prio];

// --- create
$t1 = $svc->create(4, $new('Server will not start', 'high'));
check('ticket created', $t1['id'] === 1 && $t1['status'] === 'open' && $t1['priority'] === 'high' && $t1['user_id'] === 4);
check('author has read it already', $svc->summary($customer, 4)['unread'] === 0);
$t2 = $svc->create(4, $new('Billing question', 'low'));
$t3 = $svc->create(5, $new('Cannot log in', 'urgent'));

// --- limit of active tickets
$ok = true;
try {
    for ($i = 0; $i < 20; $i++) {
        $svc->create(5, $new('Spam ' . $i));
    }
    $ok = false;
} catch (DomainException) {
}
check('active ticket limit enforced', $ok && (int) $pdo->query('SELECT COUNT(*) FROM ext_team_roles_tickets WHERE user_id = 5')->fetchColumn() === R::MAX_ACTIVE_PER_USER);
$pdo->exec('DELETE FROM ext_team_roles_tickets WHERE user_id = 5 AND id > 3');
$pdo->exec('DELETE FROM ext_team_roles_ticket_reads WHERE ticket_id > 3');

// --- lists
$mine = $svc->list(4, [], 4, 1, 20);
check('mine: only own tickets', $mine['meta']['total'] === 2 && count($mine['data']) === 2);
$all = $svc->list(null, [], 3, 1, 20);
check('all: every ticket with author names', $all['meta']['total'] === 3 && $all['data'][0]['author']['username'] !== '');
check('priority filter', $svc->list(null, ['priority' => 'urgent'], 3, 1, 20)['meta']['total'] === 1);
check('status filter active', $svc->list(null, ['status' => 'active'], 3, 1, 20)['meta']['total'] === 3);
check('status filter closed none', $svc->list(null, ['status' => 'closed'], 3, 1, 20)['meta']['total'] === 0);
check('search', $svc->list(null, ['search' => 'billing'], 3, 1, 20)['meta']['total'] === 1);
check('search no match', $svc->list(null, ['search' => 'zzz'], 3, 1, 20)['meta']['total'] === 0);
$byPrio = array_column($svc->list(null, ['sort' => 'priority'], 3, 1, 20)['data'], 'priority');
check('sort by priority: urgent, high, low', $byPrio === ['urgent', 'high', 'low']);
$p1 = $svc->list(null, [], 3, 1, 2);
$p2 = $svc->list(null, [], 3, 2, 2);
check('pagination', count($p1['data']) === 2 && count($p2['data']) === 1 && $p1['meta']['total'] === 3 && $p1['data'][0]['id'] !== $p2['data'][0]['id']);
check('unlisted unknown status ignored', $svc->list(null, ['status' => "x' OR 1=1"], 3, 1, 20)['meta']['total'] === 3);

// --- unread + notifications
check('staff: 3 unread', $svc->summary($friend, 2)['unread'] === 3 && $svc->summary($friend, 2)['unread_staff'] === 3);
check('staff list marks unread', $svc->list(null, [], 2, 1, 20)['data'][0]['unread'] === true);
check('customer own ticket not unread for author', $svc->summary($customer, 4)['unread'] === 0);
check('view-only staff: no notifications', $svc->summary($viewOnly, 2)['unread'] === 0);
check('notify without view: no notifications', $svc->summary($notifyNoView, 2)['unread'] === 0);
check('owner notified', $svc->summary($owner, 1)['unread'] === 3);
check('customer sees no staff counters', $svc->summary($customer, 4)['unread_staff'] === 0 && $svc->summary($customer, 4)['active_total'] === 0);
check('view right shows active total', $svc->summary($viewOnly, 2)['active_total'] === 3);

$svc->markRead(1, 2);
check('markRead clears one ticket', $svc->summary($friend, 2)['unread'] === 2);

// --- staff reply
$ticket = $svc->find(1);
$svc->addReply($ticket, 2, 'Have you tried restarting?', true);
$after = $svc->find(1);
check('reply: status in_progress, seq 2, actor friend', $after['status'] === 'in_progress' && $after['activity_seq'] === 2 && $after['last_actor_id'] === 2);
check('replier not unread', $svc->summary($friend, 2)['unread'] === 2);
check('other staff unread again', $svc->summary($mod, 3)['unread_staff'] === 3);
check('customer notified about reply', $svc->summary($customer, 4)['unread_mine'] === 1);
check('messages stored', count($svc->messages(1)) === 1 && $svc->messages(1)[0]['is_staff'] === true && $svc->messages(1)[0]['author']['username'] === 'friend1');
$svc->markRead(1, 4);
check('customer reads reply', $svc->summary($customer, 4)['unread'] === 0);

// --- customer replies, resolved -> open
$svc->setStatus(1, 2, 'resolved');
check('status change notifies customer', $svc->summary($customer, 4)['unread_mine'] === 1 && $svc->find(1)['status'] === 'resolved');
$svc->markRead(1, 4);
$svc->addReply($svc->find(1), 4, 'It works now but broke again', false);
check('customer reply reopens resolved', $svc->find(1)['status'] === 'open');
check('staff unread after customer reply', $svc->summary($friend, 2)['unread_staff'] >= 1);

// --- priority + close
$svc->setPriority(2, 3, 'urgent');
check('priority changed', $svc->find(2)['priority'] === 'urgent');
$svc->setStatus(2, 4, 'closed');
check('closed ticket is listed as closed', $svc->list(null, ['status' => 'closed'], 1, 1, 20)['meta']['total'] === 1);
check('active total shrinks', $svc->summary($viewOnly, 2)['active_total'] === 2);
check('closed ticket leaves the staff counter', $svc->summary($mod, 3)['unread_staff'] === 2);

// --- delete
$svc->addReply($svc->find(3), 2, 'Looking into it', true);
$svc->delete(3);
check('delete removes ticket, messages, reads', $svc->find(3) === null
    && (int) $pdo->query('SELECT COUNT(*) FROM ext_team_roles_ticket_messages WHERE ticket_id = 3')->fetchColumn() === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM ext_team_roles_ticket_reads WHERE ticket_id = 3')->fetchColumn() === 0);
check('other tickets untouched', $svc->find(1) !== null && $svc->find(2) !== null);

// --- unknown / edge
check('find unknown', $svc->find(999) === null);
check('username fallback', $svc->username(999) === '#999');

// --- e-mail preference (default on, per user)
check('mail default is on', $svc->mailEnabled(4) === true && $svc->mailDisabledIds([4, 5]) === []);
$svc->setMailEnabled(4, false);
check('mail switched off', $svc->mailEnabled(4) === false && $svc->mailEnabled(5) === true);
check('disabled list only contains opted-out users', $svc->mailDisabledIds([1, 2, 3, 4, 5]) === [4]);
$svc->setMailEnabled(4, false);
check('switching off twice keeps one row', (int) $pdo->query('SELECT COUNT(*) FROM ext_team_roles_ticket_prefs WHERE user_id = 4')->fetchColumn() === 1);
$svc->setMailEnabled(4, true);
check('mail switched on again', $svc->mailEnabled(4) === true && $svc->mailDisabledIds([4]) === []);
$svc->setMailEnabled(2, false);
check('staff can opt out too', $svc->mailDisabledIds([2, 3, 4]) === [2]);
check('empty list', $svc->mailDisabledIds([]) === []);

echo "\n" . ($failed === 0 ? "OK: $total checks, 0 failed\n" : "$failed of $total checks FAILED\n");
exit($failed === 0 ? 0 : 1);
