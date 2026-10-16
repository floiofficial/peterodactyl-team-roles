<?php

declare(strict_types=1);

namespace TeamRoles\Support;

/**
 * Team management - by floiofficial.
 *
 * Pure rules for the support tickets (no Laravel, testable on its own):
 * validation, who may see / reply / manage, status transitions and "unread" logic.
 *
 * $role = ['slug' => string, 'permissions' => list<string>]   (the Owner always may do everything)
 * $ticket = ['user_id' => int, 'status' => string, ...]
 */
final class TicketRules
{
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    public const SUBJECT_MIN = 3;
    public const SUBJECT_MAX = 150;
    public const BODY_MIN = 10;
    public const BODY_MAX = 5000;
    public const REPLY_MAX = 5000;

    /** Max. tickets per user that are not resolved/closed (spam protection). */
    public const MAX_ACTIVE_PER_USER = 10;

    // ------------------------------------------------------------------ permissions

    public static function can(array $role, string $permission): bool
    {
        if (($role['slug'] ?? '') === Defaults::OWNER) {
            return true;
        }

        return in_array($permission, $role['permissions'] ?? [], true);
    }

    /** Can see ALL tickets (not only their own). */
    public static function canViewAll(array $role): bool
    {
        return self::can($role, 'tickets.view');
    }

    /** Notifications only make sense for people who can also open the tickets. */
    public static function canNotify(array $role): bool
    {
        return self::can($role, 'tickets.notify') && self::can($role, 'tickets.view');
    }

    /** @return array<string, bool> for the UI */
    public static function abilities(array $role): array
    {
        return [
            'view' => self::canViewAll($role),
            'reply' => self::can($role, 'tickets.reply'),
            'manage' => self::can($role, 'tickets.manage'),
            'delete' => self::can($role, 'tickets.delete'),
            'notify' => self::canNotify($role),
        ];
    }

    public static function isAuthor(array $ticket, int $userId): bool
    {
        return (int) $ticket['user_id'] === $userId;
    }

    public static function canView(array $role, array $ticket, int $userId): bool
    {
        return self::isAuthor($ticket, $userId) || self::canViewAll($role);
    }

    /** Reply allowed? Closed tickets accept no replies (reopen first). */
    public static function canReply(array $role, array $ticket, int $userId): bool
    {
        if (! self::canView($role, $ticket, $userId) || $ticket['status'] === 'closed') {
            return false;
        }

        return self::isAuthor($ticket, $userId) || self::can($role, 'tickets.reply');
    }

    public static function canChangePriority(array $role): bool
    {
        return self::can($role, 'tickets.manage');
    }

    /**
     * Status change allowed? Staff with "manage" may set anything.
     * The author may only close their ticket or reopen a closed one.
     */
    public static function canChangeStatus(array $role, array $ticket, int $userId, string $new): bool
    {
        if (! in_array($new, self::STATUSES, true) || $new === $ticket['status']) {
            return false;
        }
        if (self::can($role, 'tickets.manage') && self::canView($role, $ticket, $userId)) {
            return true;
        }
        if (! self::isAuthor($ticket, $userId)) {
            return false;
        }

        return $new === 'closed' || ($new === 'open' && $ticket['status'] === 'closed');
    }

    public static function canDelete(array $role): bool
    {
        return self::can($role, 'tickets.delete');
    }

    /** What the UI may offer for one ticket. @return array<string, bool> */
    public static function ticketAbilities(array $role, array $ticket, int $userId): array
    {
        $author = self::isAuthor($ticket, $userId);

        return [
            'reply' => self::canReply($role, $ticket, $userId),
            'manage' => self::can($role, 'tickets.manage') && self::canView($role, $ticket, $userId),
            'close' => self::canChangeStatus($role, $ticket, $userId, 'closed'),
            'reopen' => $ticket['status'] === 'closed' && self::canChangeStatus($role, $ticket, $userId, 'open'),
            'delete' => self::canDelete($role) && self::canView($role, $ticket, $userId),
            'author' => $author,
        ];
    }

    // ------------------------------------------------------------------- validation

    /**
     * @param array<string, mixed> $input
     * @return array{clean: array<string, string>, errors: array<string, string>}
     */
    public static function validateNew(array $input): array
    {
        $subject = self::text($input['subject'] ?? '');
        $description = self::text($input['description'] ?? '', true);
        $priority = strtolower(is_string($input['priority'] ?? null) ? trim($input['priority']) : 'normal');
        $errors = [];

        $len = mb_strlen($subject);
        if ($len < self::SUBJECT_MIN || $len > self::SUBJECT_MAX) {
            $errors['subject'] = 'The subject must be between ' . self::SUBJECT_MIN . ' and ' . self::SUBJECT_MAX . ' characters.';
        }
        $len = mb_strlen($description);
        if ($len < self::BODY_MIN || $len > self::BODY_MAX) {
            $errors['description'] = 'Please describe the problem (' . self::BODY_MIN . ' to ' . self::BODY_MAX . ' characters).';
        }
        if (! in_array($priority, self::PRIORITIES, true)) {
            $errors['priority'] = 'Unknown priority.';
        }

        return ['clean' => ['subject' => $subject, 'description' => $description, 'priority' => $priority], 'errors' => $errors];
    }

    /** @return array{body: string, error: ?string} */
    public static function validateReply(mixed $body): array
    {
        $text = self::text($body, true);
        $len = mb_strlen($text);
        if ($len < 1 || $len > self::REPLY_MAX) {
            return ['body' => $text, 'error' => 'The message must be between 1 and ' . self::REPLY_MAX . ' characters.'];
        }

        return ['body' => $text, 'error' => null];
    }

    /** Trim, strip control characters (keeps line breaks in multi-line text). */
    public static function text(mixed $value, bool $multiline = false): string
    {
        if (! is_string($value)) {
            return '';
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace($multiline ? '/[^\P{C}\n\t]/u' : '/\p{C}/u', '', $value);
        if (! $multiline) {
            $value = (string) preg_replace('/\s+/u', ' ', $value);
        }

        return trim($value);
    }

    // ---------------------------------------------------------------- status logic

    /** Status after a reply: staff reply on "open" => in progress; author reply on "resolved" => open again. */
    public static function statusAfterReply(string $status, bool $replierIsAuthor): string
    {
        if (! $replierIsAuthor && $status === 'open') {
            return 'in_progress';
        }
        if ($replierIsAuthor && $status === 'resolved') {
            return 'open';
        }

        return $status;
    }

    /** Count as "active" for the per-user limit. */
    public static function isActive(string $status): bool
    {
        return in_array($status, ['open', 'in_progress'], true);
    }

    // --------------------------------------------------------------------- unread

    /**
     * Unread = somebody ELSE acted last and the user has not seen the latest activity.
     *
     * @param array{last_actor_id: ?int, activity_seq: int} $ticket
     * @param int|null $seenSeq last seen activity number of the user (null = never opened)
     */
    public static function isUnread(array $ticket, int $userId, ?int $seenSeq): bool
    {
        if ((int) ($ticket['last_actor_id'] ?? 0) === $userId) {
            return false;
        }

        return $seenSeq === null || $seenSeq < (int) $ticket['activity_seq'];
    }
}
