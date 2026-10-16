<?php

declare(strict_types=1);

namespace TeamRoles\Services;

use Illuminate\Database\ConnectionInterface;
use Throwable;
use TeamRoles\Support\TicketMail;

/**
 * Team management - by floiofficial.
 *
 * Sends the e-mails about support tickets with the panel's own mail settings. An e-mail problem
 * (mail not configured, SMTP down, ...) must NEVER break creating or answering a ticket:
 * everything here is wrapped and only logged.
 */
final class TicketMailer
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TeamService $team,
        private readonly ?TicketService $tickets = null,
    )
    {
    }

    public static function make(): self
    {
        return new self(app('db')->connection(), app(TeamService::class), app(TicketService::class));
    }

    /** @param array<string, mixed> $ticket */
    public function newTicket(array $ticket, string $authorName): void
    {
        $this->guard(function () use ($ticket, $authorName): void {
            $ids = TicketMail::recipientsForNewTicket($this->team->ticketNotifyUserIds(), (int) $ticket['user_id']);
            $mail = TicketMail::newTicket($ticket, $authorName, TicketMail::link($this->baseUrl(), (int) $ticket['id']));
            $this->send($ids, $mail['subject'], $mail['body']);
        });
    }

    /** @param array<string, mixed> $ticket */
    public function reply(array $ticket, int $actorId, string $actorName, string $text): void
    {
        $this->guard(function () use ($ticket, $actorId, $actorName, $text): void {
            $authorId = (int) $ticket['user_id'];
            $ids = TicketMail::recipientsForReply($this->team->ticketNotifyUserIds(), $authorId, $actorId);
            $mail = TicketMail::reply($ticket, $actorName, $actorId === $authorId, $text, TicketMail::link($this->baseUrl(), (int) $ticket['id']));
            $this->send($ids, $mail['subject'], $mail['body']);
        });
    }

    /** @param list<int> $userIds */
    private function send(array $userIds, string $subject, string $body): void
    {
        if ($this->tickets !== null) {
            // People who switched e-mails off in their ticket settings get nothing.
            $userIds = array_values(array_diff($userIds, $this->tickets->mailDisabledIds($userIds)));
        }
        if ($userIds === []) {
            return;
        }
        $emails = [];
        foreach ($this->db->table('users')->whereIn('id', $userIds)->get(['id', 'email']) as $row) {
            $email = trim((string) $row->email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[] = $email;
            }
        }

        foreach ($emails as $email) {
            // One message per person (nobody sees the other recipients); a failure does not stop the rest.
            $this->guard(static function () use ($email, $subject, $body): void {
                app('mailer')->raw($body, static function ($message) use ($email, $subject): void {
                    $message->to($email)->subject($subject);
                });
            });
        }
    }

    private function baseUrl(): string
    {
        return (string) config('app.url', '');
    }

    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            try {
                logger()->warning('team-roles: ticket e-mail failed: ' . $e->getMessage());
            } catch (Throwable) {
                // logging must not fail either
            }
        }
    }
}
