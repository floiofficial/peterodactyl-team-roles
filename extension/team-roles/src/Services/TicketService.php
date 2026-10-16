<?php

declare(strict_types=1);

namespace TeamRoles\Services;

use DomainException;
use Illuminate\Database\ConnectionInterface;
use TeamRoles\Support\TicketRules;

/**
 * Team management - by floiofficial.
 *
 * Database access for the support tickets. Only simple queries on purpose; all decisions
 * (who may do what) live in TicketRules and are checked by the controller before calling this.
 */
final class TicketService
{
    public const TICKETS = 'ext_team_roles_tickets';
    public const MESSAGES = 'ext_team_roles_ticket_messages';
    public const READS = 'ext_team_roles_ticket_reads';
    public const PREFS = 'ext_team_roles_ticket_prefs';
    public const FILES = 'ext_team_roles_ticket_attachments';

    /** Max. tickets scanned for the notification counter. */
    private const SUMMARY_LIMIT = 1000;

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public static function make(): self
    {
        return new self(app('db')->connection());
    }

    private static bool $tablesChecked = false;

    /**
     * Self-healing after an update: if the ticket tables do not exist yet (the panel did not run the new
     * migration), create them once from the extension's own migration file. The migration is idempotent.
     */
    public function ensureTables(): void
    {
        if (self::$tablesChecked) {
            return;
        }
        $schema = $this->db->getSchemaBuilder();
        $dir = dirname(__DIR__, 2) . '/database/migrations/';
        if (! $schema->hasTable(self::TICKETS) || ! $schema->hasTable(self::MESSAGES) || ! $schema->hasTable(self::READS)) {
            $migration = require $dir . '2026_10_16_000100_create_ext_team_roles_ticket_tables.php';
            $migration->up();
        }
        if (! $schema->hasTable(self::PREFS)) {
            $migration = require $dir . '2026_10_17_000000_create_ext_team_roles_ticket_prefs.php';
            $migration->up();
        }
        if (! $schema->hasTable(self::FILES)) {
            $migration = require $dir . '2026_10_18_000000_create_ext_team_roles_ticket_attachments.php';
            $migration->up();
        }
        self::$tablesChecked = true;
    }

    /** Does this user want e-mails about ticket messages? Default: yes. */
    public function mailEnabled(int $userId): bool
    {
        return ! in_array($userId, $this->mailDisabledIds([$userId]), true);
    }

    public function setMailEnabled(int $userId, bool $enabled): void
    {
        $now = $this->now();
        $existing = $this->db->table(self::PREFS)->where('user_id', $userId)->first();
        if ($existing === null) {
            $this->db->table(self::PREFS)->insert(['user_id' => $userId, 'email_notifications' => $enabled ? 1 : 0, 'created_at' => $now, 'updated_at' => $now]);
        } else {
            $this->db->table(self::PREFS)->where('id', $existing->id)->update(['email_notifications' => $enabled ? 1 : 0, 'updated_at' => $now]);
        }
    }

    /**
     * Of the given users: those who switched e-mails off.
     *
     * @param list<int> $userIds
     * @return list<int>
     */
    public function mailDisabledIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }
        $off = [];
        foreach (array_chunk($userIds, 500) as $chunk) {
            foreach ($this->db->table(self::PREFS)->whereIn('user_id', $chunk)->get(['user_id', 'email_notifications']) as $row) {
                if ((int) $row->email_notifications === 0) {
                    $off[] = (int) $row->user_id;
                }
            }
        }

        return $off;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->table(self::TICKETS)->where('id', $id)->first();

        return $row === null ? null : $this->asArray($row);
    }

    /** @param array{subject: string, description: string, priority: string} $clean */
    public function create(int $userId, array $clean): array
    {
        $active = (int) $this->db->table(self::TICKETS)
            ->where('user_id', $userId)
            ->whereIn('status', ['open', 'in_progress'])
            ->count();
        if ($active >= TicketRules::MAX_ACTIVE_PER_USER) {
            throw new DomainException('You already have ' . TicketRules::MAX_ACTIVE_PER_USER . ' open tickets. Please wait for an answer or close one first.');
        }

        $now = $this->now();
        $id = $this->db->table(self::TICKETS)->insertGetId([
            'user_id' => $userId,
            'subject' => $clean['subject'],
            'description' => $clean['description'],
            'priority' => $clean['priority'],
            'status' => 'open',
            'activity_seq' => 1,
            'last_actor_id' => $userId,
            'last_activity_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->markRead((int) $id, $userId, 1);

        return $this->find((int) $id) ?? [];
    }

    /**
     * @param array{status?: string, priority?: string, search?: string, sort?: string} $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function list(?int $onlyUserId, array $filters, int $viewerId, int $page, int $perPage): array
    {
        $query = $this->db->table(self::TICKETS);
        if ($onlyUserId !== null) {
            $query->where('user_id', $onlyUserId);
        }

        $status = $filters['status'] ?? '';
        if ($status === 'active') {
            $query->whereIn('status', ['open', 'in_progress']);
        } elseif (in_array($status, TicketRules::STATUSES, true)) {
            $query->where('status', $status);
        }
        $priority = $filters['priority'] ?? '';
        if (in_array($priority, TicketRules::PRIORITIES, true)) {
            $query->where('priority', $priority);
        }
        $search = $filters['search'] ?? '';
        if ($search !== '') {
            $query->where('subject', 'like', '%' . addcslashes($search, '\\%_') . '%');
        }

        $total = (clone $query)->count();

        if (($filters['sort'] ?? '') === 'priority') {
            $query->orderByRaw("CASE priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'normal' THEN 2 ELSE 1 END DESC");
        }
        $rows = $query->orderBy('last_activity_at', 'desc')->orderBy('id', 'desc')
            ->offset(($page - 1) * $perPage)->limit($perPage)->get();

        $tickets = [];
        foreach ($rows as $row) {
            $tickets[] = $this->asArray($row);
        }

        $seen = $this->seenMap($viewerId, array_column($tickets, 'id'));
        $names = $this->usernames(array_column($tickets, 'user_id'));
        foreach ($tickets as &$t) {
            $t['unread'] = TicketRules::isUnread($t, $viewerId, $seen[$t['id']] ?? null);
            $t['author'] = ['id' => $t['user_id'], 'username' => $names[$t['user_id']] ?? '#' . $t['user_id']];
        }
        unset($t);

        return ['data' => $tickets, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]];
    }

    /** @return list<array<string, mixed>> */
    public function messages(int $ticketId): array
    {
        $rows = $this->db->table(self::MESSAGES)->where('ticket_id', $ticketId)->orderBy('id', 'asc')->get();
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->asArray($row);
        }
        $names = $this->usernames(array_column($out, 'user_id'));
        $files = $this->attachmentsGrouped($ticketId);
        foreach ($out as &$m) {
            $m['author'] = ['id' => $m['user_id'], 'username' => $names[$m['user_id']] ?? '#' . $m['user_id']];
            $m['attachments'] = $files[$m['id']] ?? [];
        }
        unset($m);

        return $out;
    }

    /** Attachments of the ticket itself (the first message). @return list<array<string, mixed>> */
    public function ticketAttachments(int $ticketId): array
    {
        return $this->attachmentsGrouped($ticketId)[0] ?? [];
    }

    public function attachmentCount(int $ticketId): int
    {
        return (int) $this->db->table(self::FILES)->where('ticket_id', $ticketId)->count();
    }

    /** @param array{name: string, kind: string, size: int, stored: string} $meta */
    public function addAttachment(int $ticketId, ?int $messageId, int $userId, array $meta): int
    {
        $now = $this->now();

        return (int) $this->db->table(self::FILES)->insertGetId([
            'ticket_id' => $ticketId,
            'message_id' => $messageId,
            'user_id' => $userId,
            'name' => $meta['name'],
            'kind' => $meta['kind'],
            'size' => $meta['size'],
            'stored' => $meta['stored'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array<string, mixed>|null a row incl. 'stored' (never sent to the browser) */
    public function findAttachment(int $ticketId, int $attachmentId): ?array
    {
        $row = $this->db->table(self::FILES)->where('id', $attachmentId)->where('ticket_id', $ticketId)->first();

        return $row === null ? null : $this->asArray($row);
    }

    /** @return list<string> stored file names of the ticket (to delete them from disk) */
    public function attachmentFiles(int $ticketId): array
    {
        $out = [];
        foreach ($this->db->table(self::FILES)->where('ticket_id', $ticketId)->get(['stored']) as $row) {
            $out[] = (string) $row->stored;
        }

        return $out;
    }

    /**
     * message id (0 = the ticket itself) => list of public attachment info
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function attachmentsGrouped(int $ticketId): array
    {
        $out = [];
        foreach ($this->db->table(self::FILES)->where('ticket_id', $ticketId)->orderBy('id', 'asc')->get(['id', 'message_id', 'name', 'kind', 'size']) as $row) {
            $out[(int) ($row->message_id ?? 0)][] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'kind' => (string) $row->kind,
                'size' => (int) $row->size,
            ];
        }

        return $out;
    }

    public function username(int $userId): string
    {
        return $this->usernames([$userId])[$userId] ?? '#' . $userId;
    }

    public function addReply(array $ticket, int $userId, string $body, bool $isStaff): int
    {
        $now = $this->now();
        $isAuthor = TicketRules::isAuthor($ticket, $userId);
        $newStatus = TicketRules::statusAfterReply((string) $ticket['status'], $isAuthor);

        $messageId = 0;
        $this->db->transaction(function () use ($ticket, $userId, $body, $isStaff, $now, $newStatus, &$messageId): void {
            $messageId = (int) $this->db->table(self::MESSAGES)->insertGetId([
                'ticket_id' => $ticket['id'],
                'user_id' => $userId,
                'body' => $body,
                'is_staff' => $isStaff ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->bump((int) $ticket['id'], $userId, ['status' => $newStatus]);
        });
        $this->markRead((int) $ticket['id'], $userId);

        return $messageId;
    }

    public function setStatus(int $ticketId, int $actorId, string $status): void
    {
        $this->bump($ticketId, $actorId, ['status' => $status]);
        $this->markRead($ticketId, $actorId);
    }

    public function setPriority(int $ticketId, int $actorId, string $priority): void
    {
        $this->bump($ticketId, $actorId, ['priority' => $priority]);
        $this->markRead($ticketId, $actorId);
    }

    public function delete(int $ticketId): void
    {
        $this->db->transaction(function () use ($ticketId): void {
            $this->db->table(self::MESSAGES)->where('ticket_id', $ticketId)->delete();
            $this->db->table(self::READS)->where('ticket_id', $ticketId)->delete();
            $this->db->table(self::FILES)->where('ticket_id', $ticketId)->delete();
            $this->db->table(self::TICKETS)->where('id', $ticketId)->delete();
        });
    }

    /** Mark the ticket as seen up to the given (or its current) activity number. */
    public function markRead(int $ticketId, int $userId, ?int $seq = null): void
    {
        if ($seq === null) {
            $seq = (int) $this->db->table(self::TICKETS)->where('id', $ticketId)->value('activity_seq');
        }
        $now = $this->now();
        $existing = $this->db->table(self::READS)->where('ticket_id', $ticketId)->where('user_id', $userId)->first();
        if ($existing === null) {
            $this->db->table(self::READS)->insert(['ticket_id' => $ticketId, 'user_id' => $userId, 'seen_seq' => $seq, 'created_at' => $now, 'updated_at' => $now]);
        } elseif ((int) $existing->seen_seq < $seq) {
            $this->db->table(self::READS)->where('id', $existing->id)->update(['seen_seq' => $seq, 'updated_at' => $now]);
        }
    }

    /**
     * Counter for the notification badge.
     *
     * @return array{unread: int, unread_mine: int, unread_staff: int, active_total: int}
     */
    public function summary(array $role, int $userId): array
    {
        $viewAll = TicketRules::canViewAll($role);
        $notify = TicketRules::canNotify($role);

        $query = $this->db->table(self::TICKETS)->where('status', '!=', 'closed');
        if (! $viewAll) {
            $query->where('user_id', $userId);
        }
        $rows = $query->orderBy('id', 'desc')->limit(self::SUMMARY_LIMIT)->get(['id', 'user_id', 'last_actor_id', 'activity_seq', 'status']);

        $tickets = [];
        foreach ($rows as $row) {
            $tickets[] = $this->asArray($row);
        }
        $seen = $this->seenMap($userId, array_column($tickets, 'id'));

        $mine = 0;
        $staff = 0;
        $active = 0;
        foreach ($tickets as $t) {
            if (TicketRules::isActive((string) $t['status'])) {
                $active++;
            }
            if (! TicketRules::isUnread($t, $userId, $seen[$t['id']] ?? null)) {
                continue;
            }
            if (TicketRules::isAuthor($t, $userId)) {
                $mine++;
            } elseif ($notify) {
                $staff++;   // other people's tickets only count for people who asked for notifications
            }
        }

        return [
            'unread' => $mine + $staff,
            'unread_mine' => $mine,
            'unread_staff' => $staff,
            'active_total' => $viewAll ? $active : 0,
        ];
    }

    // ---------------------------------------------------------------- internals

    /** Count a new activity, remember who acted and apply further changes. */
    private function bump(int $ticketId, int $actorId, array $changes): void
    {
        $now = $this->now();
        $this->db->table(self::TICKETS)->where('id', $ticketId)->increment('activity_seq', 1, $changes + [
            'last_actor_id' => $actorId,
            'last_activity_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param list<int|string> $ids
     * @return array<int, int> ticket id => last seen activity number
     */
    private function seenMap(int $userId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach (array_chunk(array_map('intval', $ids), 500) as $chunk) {
            foreach ($this->db->table(self::READS)->where('user_id', $userId)->whereIn('ticket_id', $chunk)->get(['ticket_id', 'seen_seq']) as $row) {
                $map[(int) $row->ticket_id] = (int) $row->seen_seq;
            }
        }

        return $map;
    }

    /**
     * @param list<int|string> $ids
     * @return array<int, string>
     */
    private function usernames(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach ($this->db->table('users')->whereIn('id', $ids)->get(['id', 'username']) as $row) {
            $map[(int) $row->id] = (string) $row->username;
        }

        return $map;
    }

    /** @return array<string, mixed> */
    private function asArray(object $row): array
    {
        $a = (array) $row;
        foreach (['id', 'user_id', 'ticket_id', 'message_id', 'size', 'activity_seq', 'last_actor_id'] as $int) {
            if (array_key_exists($int, $a) && $a[$int] !== null) {
                $a[$int] = (int) $a[$int];
            }
        }
        if (array_key_exists('is_staff', $a)) {
            $a['is_staff'] = (bool) $a['is_staff'];
        }

        return $a;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
