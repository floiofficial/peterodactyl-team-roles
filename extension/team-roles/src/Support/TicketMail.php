<?php

declare(strict_types=1);

namespace TeamRoles\Support;

/**
 * Team management - by floiofficial.
 *
 * Pure helpers for the e-mails about support tickets (no Laravel, testable on its own):
 * who gets an e-mail and what it says. Sending is done by TeamRoles\Services\TicketMailer.
 *
 * Rule: an e-mail is only a *pointer* ("go and look in the panel") plus a short excerpt.
 * The people who get e-mails about other people's tickets are exactly those whose role has
 * "see all tickets" + "get notified" (the Owner always) - one switch for panel AND e-mail.
 */
final class TicketMail
{
    public const EXCERPT = 300;
    public const MAX_RECIPIENTS = 50;

    /**
     * New ticket: all notified staff except the author.
     *
     * @param list<int> $staffIds
     * @return list<int>
     */
    public static function recipientsForNewTicket(array $staffIds, int $authorId): array
    {
        return self::clean(array_filter($staffIds, static fn (int $id): bool => $id !== $authorId));
    }

    /**
     * Reply: a team member answered => the author; the author answered => the notified staff.
     * Never the person who wrote the reply.
     *
     * @param list<int> $staffIds
     * @return list<int>
     */
    public static function recipientsForReply(array $staffIds, int $authorId, int $actorId): array
    {
        if ($actorId !== $authorId) {
            return self::clean([$authorId]);
        }

        return self::clean(array_filter($staffIds, static fn (int $id): bool => $id !== $actorId));
    }

    /** @return array{subject: string, body: string} */
    public static function newTicket(array $ticket, string $authorName, string $link): array
    {
        $priority = ucfirst((string) ($ticket['priority'] ?? 'normal'));

        return [
            'subject' => self::subject('New support ticket #' . $ticket['id'] . ': ' . $ticket['subject']),
            'body' => "{$authorName} opened a new support ticket.\n\n"
                . 'Subject:  ' . self::oneLine((string) $ticket['subject']) . "\n"
                . "Priority: {$priority}\n\n"
                . self::excerpt((string) ($ticket['description'] ?? '')) . "\n\n"
                . "Open it in the panel:\n{$link}\n",
        ];
    }

    /** @return array{subject: string, body: string} */
    public static function reply(array $ticket, string $actorName, bool $actorIsAuthor, string $text, string $link): array
    {
        $head = $actorIsAuthor
            ? "{$actorName} replied to the support ticket #{$ticket['id']}."
            : "There is a new reply to your support ticket #{$ticket['id']}.";

        return [
            'subject' => self::subject('Re: support ticket #' . $ticket['id'] . ': ' . $ticket['subject']),
            'body' => $head . "\n\n"
                . 'Subject: ' . self::oneLine((string) $ticket['subject']) . "\n\n"
                . self::excerpt($text) . "\n\n"
                . "Open it in the panel:\n{$link}\n",
        ];
    }

    public static function link(string $baseUrl, int $ticketId): string
    {
        return rtrim($baseUrl, '/') . '/account/support?ticket=' . $ticketId;
    }

    public static function excerpt(string $text): string
    {
        $text = trim((string) preg_replace('/\p{C}+/u', ' ', $text));

        return mb_strlen($text) > self::EXCERPT ? mb_substr($text, 0, self::EXCERPT) . '…' : $text;
    }

    /** One line, no control characters (also protects against header injection). */
    public static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\r\n\p{C}]+/u', ' ', $text)));
    }

    private static function subject(string $text): string
    {
        $line = self::oneLine($text);

        return mb_strlen($line) > 120 ? mb_substr($line, 0, 119) . '…' : $line;
    }

    /**
     * @param iterable<int> $ids
     * @return list<int>
     */
    private static function clean(iterable $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && ! in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return array_slice($out, 0, self::MAX_RECIPIENTS);
    }
}
