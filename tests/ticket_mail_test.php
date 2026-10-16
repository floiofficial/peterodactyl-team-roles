<?php

declare(strict_types=1);

// Team management - by floiofficial.
// Tests the ticket e-mails: who gets one (pure rules), what it says, and the mailer on SQLite with a
// fake mailer (tests/support/mini_laravel.php is a stand-in, not Laravel). Run: php tests/ticket_mail_test.php

namespace {
    require __DIR__ . '/support/mini_laravel.php';
    $src = __DIR__ . '/../extension/team-roles/src/';
    require $src . 'Support/PermissionMap.php';
    require $src . 'Support/Defaults.php';
    require $src . 'Support/TicketRules.php';
    require $src . 'Support/TicketMail.php';
    require $src . 'Services/TeamService.php';
    require $src . 'Services/TicketMailer.php';

    use MiniLaravel\Connection;
    use TeamRoles\Services\TeamService;
    use TeamRoles\Services\TicketMailer;
    use TeamRoles\Support\TicketMail as M;

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

    // ---- fakes for the Laravel helpers used by the mailer
    $GLOBALS['sent'] = [];
    $GLOBALS['mailFails'] = [];
    $GLOBALS['logged'] = [];
    final class FakeMessage
    {
        public ?string $to = null;
        public ?string $subject = null;
        public function to(string $a): self { $this->to = $a; return $this; }
        public function subject(string $s): self { $this->subject = $s; return $this; }
    }
    final class FakeMailer
    {
        public function raw(string $body, \Closure $cb): void
        {
            $m = new FakeMessage();
            $cb($m);
            if (in_array($m->to, $GLOBALS['mailFails'], true)) {
                throw new \RuntimeException('SMTP down');
            }
            $GLOBALS['sent'][] = ['to' => $m->to, 'subject' => $m->subject, 'body' => $body];
        }
    }
    function app(string $name = '') { return new FakeMailer(); }
    function config(string $key, mixed $default = null) { return $key === 'app.url' ? 'https://panel.example.com/' : $default; }
    function logger() { return new class { public function warning(string $m): void { $GLOBALS['logged'][] = $m; } }; }

    // =============================== pure rules
    check('new ticket: staff without the author', M::recipientsForNewTicket([1, 3, 5], 3) === [1, 5]);
    check('new ticket: no staff => nobody', M::recipientsForNewTicket([], 3) === []);
    check('new ticket: author only => nobody', M::recipientsForNewTicket([3], 3) === []);
    check('reply by team => author only', M::recipientsForReply([1, 2], 9, 2) === [9]);
    check('reply by author => staff', M::recipientsForReply([1, 2], 9, 9) === [1, 2]);
    check('reply by author who is also staff => other staff only', M::recipientsForReply([1, 9], 9, 9) === [1]);
    check('duplicates and invalid ids removed', M::recipientsForNewTicket([1, 1, 0, -4, 2], 99) === [1, 2]);
    check('recipient cap', count(M::recipientsForNewTicket(range(1, 200), 0)) === M::MAX_RECIPIENTS);

    $ticket = ['id' => 7, 'user_id' => 9, 'subject' => "Server down\r\nBcc: evil@x.com", 'priority' => 'urgent', 'description' => str_repeat('a', 400)];
    $link = M::link('https://panel.example.com/', 7);
    check('link built without double slash', $link === 'https://panel.example.com/account/support?ticket=7');
    $n = M::newTicket($ticket, 'alice', $link);
    check('new-ticket subject is one line (no header injection)', ! preg_match('/[\r\n]/', $n['subject']) && str_starts_with($n['subject'], 'New support ticket #7: Server down'));
    check('new-ticket body: author, priority, link', str_contains($n['body'], 'alice') && str_contains($n['body'], 'Priority: Urgent') && str_contains($n['body'], $link));
    check('description excerpt is cut', str_contains($n['body'], str_repeat('a', 300) . '…') && ! str_contains($n['body'], str_repeat('a', 301)));
    $r1 = M::reply($ticket, 'staffy', false, 'We fixed it', $link);
    check('team reply text addresses the customer', str_contains($r1['body'], 'new reply to your support ticket #7') && str_starts_with($r1['subject'], 'Re: support ticket #7'));
    $r2 = M::reply($ticket, 'alice', true, 'Still broken', $link);
    check('customer reply text names the customer', str_contains($r2['body'], 'alice replied to the support ticket #7'));
    check('long subjects are shortened', mb_strlen(M::newTicket(['id' => 1, 'subject' => str_repeat('x', 300), 'description' => 'd'], 'a', 'l')['subject']) <= 120);
    check('control characters stripped in excerpt', ! str_contains(M::excerpt("a\x00b\x07c"), "\x00"));

    // =============================== mailer on SQLite
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, root_admin INT DEFAULT 0)');
    $pdo->exec('CREATE TABLE ext_team_roles_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, name TEXT, rank INT, permissions TEXT, is_system INT, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE ext_team_roles_members (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT UNIQUE, role_slug TEXT, created_at TEXT, updated_at TEXT)');
    $users = [[1, 'floi', 'floi@x.com', 'owner'], [2, 'adm', 'adm@x.com', 'administrator'], [3, 'mod', 'mod@x.com', 'moderator'], [4, 'fri', 'fri@x.com', 'friend'], [5, 'cust', 'cust@x.com', 'user'], [6, 'bad', 'not-an-email', 'moderator'], [7, 'quiet', 'quiet@x.com', 'moderator']];
    foreach ($users as $u) {
        $pdo->prepare('INSERT INTO users (id, username, email) VALUES (?,?,?)')->execute([$u[0], $u[1], $u[2]]);
        $pdo->prepare('INSERT INTO ext_team_roles_members (user_id, role_slug) VALUES (?,?)')->execute([$u[0], $u[3]]);
    }
    $db = new Connection($pdo);
    $team = new TeamService($db);
    $mailer = new TicketMailer($db, $team);
    $setPerms = function (string $slug, array $perms) use ($pdo): void {
        $pdo->prepare('UPDATE ext_team_roles_roles SET permissions = ? WHERE slug = ?')->execute([json_encode($perms), $slug]);
    };
    $reset = function () { $GLOBALS['sent'] = []; $GLOBALS['mailFails'] = []; $GLOBALS['logged'] = []; };

    $team->rolesList();   // creates the default roles
    $ids = $team->ticketNotifyUserIds();
    sort($ids);
    check('notify ids: owner, administrator, moderators, friend (default rights)', $ids === [1, 2, 3, 4, 6, 7]);
    check('customer never in notify ids', ! in_array(5, $ids, true));

    // the owner decides: moderators lose "get notified"
    $setPerms('moderator', ['users.view', 'tickets.view', 'tickets.reply']);
    $ids = (new TeamService($db))->ticketNotifyUserIds();
    sort($ids);
    check('moderators without notify are not emailed', $ids === [1, 2, 4]);
    // notify without view is not enough
    $setPerms('friend', ['tickets.notify']);
    $ids = (new TeamService($db))->ticketNotifyUserIds();
    sort($ids);
    check('notify without "see all tickets" => no e-mail', $ids === [1, 2]);

    $setPerms('moderator', ['users.view', 'tickets.view', 'tickets.reply', 'tickets.notify']);
    $setPerms('friend', ['tickets.view', 'tickets.reply', 'tickets.notify']);
    $mailer = new TicketMailer($db, new TeamService($db));
    $reset();
    $mailer->newTicket(['id' => 11, 'user_id' => 5, 'subject' => 'Help', 'priority' => 'high', 'description' => 'Cannot start my server'], 'cust');
    $to = array_column($GLOBALS['sent'], 'to');
    sort($to);
    check('new ticket: e-mail to owner, administrator, moderators, friend (valid addresses only), none to customer', $to === ['adm@x.com', 'floi@x.com', 'fri@x.com', 'mod@x.com', 'quiet@x.com']);
    check('every mail is addressed to ONE person', count(array_unique($to)) === count($to));
    check('mail contains link and subject', str_contains($GLOBALS['sent'][0]['body'], 'https://panel.example.com/account/support?ticket=11') && str_contains($GLOBALS['sent'][0]['subject'], 'Help'));

    $reset();
    $mailer->reply(['id' => 11, 'user_id' => 5, 'subject' => 'Help'], 3, 'mod', 'Restarted your node');
    check('team reply: only the customer gets an e-mail', array_column($GLOBALS['sent'], 'to') === ['cust@x.com']);
    $reset();
    $mailer->reply(['id' => 11, 'user_id' => 5, 'subject' => 'Help'], 5, 'cust', 'Thanks, still failing');
    $to = array_column($GLOBALS['sent'], 'to');
    sort($to);
    check('customer reply: notified staff get an e-mail (not the customer)', $to === ['adm@x.com', 'floi@x.com', 'fri@x.com', 'mod@x.com', 'quiet@x.com'] && ! in_array('cust@x.com', $to, true));

    // mail problems never break the ticket flow and do not stop the other recipients
    $reset();
    $GLOBALS['mailFails'] = ['adm@x.com'];
    $threw = false;
    try {
        $mailer->newTicket(['id' => 12, 'user_id' => 5, 'subject' => 'Again', 'priority' => 'low', 'description' => 'Another problem here'], 'cust');
    } catch (\Throwable $e) {
        $threw = true;
    }
    check('SMTP failure does not throw', ! $threw);
    check('other recipients still get the mail', in_array('floi@x.com', array_column($GLOBALS['sent'], 'to'), true) && ! in_array('adm@x.com', array_column($GLOBALS['sent'], 'to'), true));
    check('failure is logged', count($GLOBALS['logged']) === 1 && str_contains($GLOBALS['logged'][0], 'SMTP down'));

    echo $failed === 0 ? "OK: $total checks, 0 failed\n" : "FAILED: $failed of $total checks\n";
    exit($failed === 0 ? 0 : 1);
}
