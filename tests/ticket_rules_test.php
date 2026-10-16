<?php

declare(strict_types=1);

// Team management - by floiofficial.
// Tests the pure ticket rules (no panel, no database):  php tests/ticket_rules_test.php

$src = __DIR__ . '/../extension/team-roles/src/Support/';
require $src . 'PermissionMap.php';
require $src . 'Defaults.php';
require $src . 'TicketRules.php';

use TeamRoles\Support\Defaults;
use TeamRoles\Support\PermissionMap;
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

$role = fn (string $slug, array $perms) => ['slug' => $slug, 'permissions' => $perms];
$owner = $role('owner', []);
$admin = $role('administrator', Defaults::administratorPermissions());
$mod = $role('moderator', Defaults::moderatorPermissions());
$friend = $role('friend', Defaults::friendPermissions());
$customer = $role('user', []);
$ticket = fn (int $author, string $status = 'open') => ['user_id' => $author, 'status' => $status, 'priority' => 'normal', 'last_actor_id' => $author, 'activity_seq' => 1];

// --- defaults
check('admin default: all ticket rights', count(array_filter($admin['permissions'], fn ($p) => str_starts_with($p, 'tickets.'))) === 5);
check('moderator default: view, reply, notify', array_values(array_filter($mod['permissions'], fn ($p) => str_starts_with($p, 'tickets.'))) === ['tickets.view', 'tickets.reply', 'tickets.notify']);
check('friend default: view, reply, notify', $friend['permissions'] === ['tickets.view', 'tickets.reply', 'tickets.notify']);
check('customer default: nothing', $customer['permissions'] === []);
check('all ticket keys are grantable', PermissionMap::isGrantable('tickets.notify') && PermissionMap::isGrantable('tickets.manage'));
check('unknown ticket key not grantable', ! PermissionMap::isGrantable('tickets.hack') && ! PermissionMap::isGrantable('tickets.edit'));

// --- ticket rights never grant admin access
check('friend defaults: no admin access', ! PermissionMap::grantsAdminAccess($friend['permissions']));
check('only tickets.* : no admin access', ! PermissionMap::grantsAdminAccess(['tickets.view', 'tickets.delete', 'tickets.manage']));
check('users.view : admin access', PermissionMap::grantsAdminAccess(['users.view']));
check('mixed : admin access', PermissionMap::grantsAdminAccess(['tickets.view', 'nodes.view']));
check('empty : no admin access', ! PermissionMap::grantsAdminAccess([]));
check('moderator defaults : admin access', PermissionMap::grantsAdminAccess($mod['permissions']));

// --- visibility
check('author sees own', R::canView($customer, $ticket(7), 7));
check('customer cannot see others', ! R::canView($customer, $ticket(8), 7));
check('friend sees others', R::canView($friend, $ticket(8), 7));
check('owner sees all', R::canView($owner, $ticket(8), 7));
check('moderator without view cannot see', ! R::canView($role('moderator', ['users.view']), $ticket(8), 7));

// --- replies
check('author may reply', R::canReply($customer, $ticket(7), 7));
check('author may not reply to closed', ! R::canReply($customer, $ticket(7, 'closed'), 7));
check('friend may reply (default)', R::canReply($friend, $ticket(8), 7));
check('view without reply cannot reply', ! R::canReply($role('friend', ['tickets.view']), $ticket(8), 7));
check('reply without view cannot reply', ! R::canReply($role('friend', ['tickets.reply']), $ticket(8), 7));
check('staff may not reply to closed', ! R::canReply($admin, $ticket(8, 'closed'), 1));
check('owner may reply', R::canReply($owner, $ticket(8), 1));

// --- status / priority
check('author closes', R::canChangeStatus($customer, $ticket(7), 7, 'closed'));
check('author reopens closed', R::canChangeStatus($customer, $ticket(7, 'closed'), 7, 'open'));
check('author cannot set resolved', ! R::canChangeStatus($customer, $ticket(7), 7, 'resolved'));
check('author cannot set in_progress', ! R::canChangeStatus($customer, $ticket(7), 7, 'in_progress'));
check('author cannot reopen non-closed (same status)', ! R::canChangeStatus($customer, $ticket(7), 7, 'open'));
check('unknown status rejected', ! R::canChangeStatus($owner, $ticket(7), 1, 'banana'));
check('manage sets any status', R::canChangeStatus($admin, $ticket(8), 1, 'resolved'));
check('friend (no manage) cannot change status of others', ! R::canChangeStatus($friend, $ticket(8), 1, 'closed'));
check('customer cannot close others tickets', ! R::canChangeStatus($customer, $ticket(8), 7, 'closed'));
check('only manage changes priority', R::canChangePriority($admin) && ! R::canChangePriority($friend) && ! R::canChangePriority($customer));
check('only delete right deletes', R::canDelete($admin) && ! R::canDelete($mod) && ! R::canDelete($customer) && R::canDelete($owner));

// --- notifications
check('friend gets notified (default)', R::canNotify($friend));
check('notify without view = no notification', ! R::canNotify($role('friend', ['tickets.notify'])));
check('customer no notification', ! R::canNotify($customer));
check('owner notified', R::canNotify($owner));

// --- abilities for the UI
$ab = R::ticketAbilities($customer, $ticket(7), 7);
check('abilities customer: reply+close, no manage/delete', $ab['reply'] && $ab['close'] && ! $ab['manage'] && ! $ab['delete'] && $ab['author'] && ! $ab['reopen']);
$ab = R::ticketAbilities($customer, $ticket(7, 'closed'), 7);
check('abilities closed: reopen, no reply', $ab['reopen'] && ! $ab['reply']);
$ab = R::ticketAbilities($admin, $ticket(8), 1);
check('abilities admin: manage+delete+reply', $ab['manage'] && $ab['delete'] && $ab['reply'] && ! $ab['author']);
check('abilities for user without access', ! R::ticketAbilities($customer, $ticket(8), 7)['reply']);

// --- validation
$v = R::validateNew(['subject' => '  Server  won\'t   start ', 'description' => "It crashes\r\nat boot - please help", 'priority' => 'HIGH']);
check('valid ticket', $v['errors'] === [] && $v['clean']['priority'] === 'high' && $v['clean']['subject'] === "Server won't start");
check('line breaks normalised', $v['clean']['description'] === "It crashes\nat boot - please help");
check('subject too short', isset(R::validateNew(['subject' => 'ab', 'description' => 'long enough text', 'priority' => 'low'])['errors']['subject']));
check('subject too long', isset(R::validateNew(['subject' => str_repeat('x', 151), 'description' => 'long enough text', 'priority' => 'low'])['errors']['subject']));
check('description too short', isset(R::validateNew(['subject' => 'Hello', 'description' => 'short', 'priority' => 'low'])['errors']['description']));
check('description too long', isset(R::validateNew(['subject' => 'Hello', 'description' => str_repeat('x', 5001), 'priority' => 'low'])['errors']['description']));
check('bad priority', isset(R::validateNew(['subject' => 'Hello', 'description' => 'long enough text', 'priority' => 'critical'])['errors']['priority']));
check('missing priority defaults to normal', R::validateNew(['subject' => 'Hello', 'description' => 'long enough text'])['clean']['priority'] === 'normal');
check('array input rejected', R::validateNew(['subject' => ['x'], 'description' => ['y'], 'priority' => ['z']])['errors'] !== []);
check('control chars stripped from subject', R::validateNew(['subject' => "Hel\x00lo\x07 there", 'description' => 'long enough text', 'priority' => 'low'])['clean']['subject'] === 'Hello there');
check('html is kept as text (UI escapes)', R::validateNew(['subject' => '<b>Hi</b> there', 'description' => 'long enough text', 'priority' => 'low'])['clean']['subject'] === '<b>Hi</b> there');
check('reply ok', R::validateReply("  hello\n\nworld ")['error'] === null);
check('reply empty', R::validateReply('   ')['error'] !== null);
check('reply too long', R::validateReply(str_repeat('a', 5001))['error'] !== null);
check('reply non-string', R::validateReply(['a'])['error'] !== null);

// --- status after reply
check('staff reply: open -> in_progress', R::statusAfterReply('open', false) === 'in_progress');
check('author reply on resolved: -> open', R::statusAfterReply('resolved', true) === 'open');
check('author reply on open stays open', R::statusAfterReply('open', true) === 'open');
check('staff reply on resolved stays', R::statusAfterReply('resolved', false) === 'resolved');

// --- unread
$t = ['last_actor_id' => 7, 'activity_seq' => 2];
check('own last action is never unread', ! R::isUnread($t, 7, null));
check('other acted, never opened = unread', R::isUnread($t, 9, null));
check('seen older = unread', R::isUnread($t, 9, 1));
check('seen latest = read', ! R::isUnread($t, 9, 2));

echo "\n" . ($failed === 0 ? "OK: $total checks, 0 failed\n" : "$failed of $total checks FAILED\n");
exit($failed === 0 ? 0 : 1);
