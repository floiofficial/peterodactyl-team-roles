<?php

declare(strict_types=1);

namespace TeamRoles\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use TeamRoles\Services\TeamService;
use TeamRoles\Services\TicketMailer;
use TeamRoles\Services\TicketService;
use TeamRoles\Support\TicketRules;

/**
 * Team management - by floiofficial.
 *
 * Support tickets. Reachable for EVERY signed-in user (routes/client.php); what a user may
 * see or do is decided per request by TicketRules (own tickets always, others only with rights).
 * Tickets the user may not see answer with 404 (so ticket numbers cannot be probed).
 */
final class TicketController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly TeamService $team,
        private readonly TicketService $tickets,
        private readonly TicketMailer $mailer,
    )
    {
        $this->tickets->ensureTables();
    }

    /** GET /tickets/summary - counter for the notification badge */
    public function summary(Request $request): JsonResponse
    {
        $role = $this->team->roleFor($request->user());

        return new JsonResponse($this->tickets->summary($role, (int) $request->user()->id) + ['can' => TicketRules::abilities($role)]);
    }

    /** GET /tickets?scope=mine|all&status=&priority=&search=&sort=&page= */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $this->team->roleFor($user);
        $scope = (string) $request->query('scope', 'mine');

        if ($scope === 'all') {
            abort_unless(TicketRules::canViewAll($role), 403, 'You are not allowed to see all tickets.');
            $only = null;
        } else {
            $only = (int) $user->id;
        }

        $filters = [
            'status' => (string) $request->query('status', ''),
            'priority' => (string) $request->query('priority', ''),
            'search' => mb_substr(trim((string) $request->query('search', '')), 0, 100),
            'sort' => (string) $request->query('sort', ''),
        ];
        $page = max(1, (int) $request->query('page', 1));

        return new JsonResponse($this->tickets->list($only, $filters, (int) $user->id, $page, self::PER_PAGE));
    }

    /** POST /tickets */
    public function store(Request $request): JsonResponse
    {
        $check = TicketRules::validateNew([
            'subject' => $request->input('subject'),
            'description' => $request->input('description'),
            'priority' => $request->input('priority', 'normal'),
        ]);
        if ($check['errors'] !== []) {
            throw ValidationException::withMessages(array_map(static fn (string $m): array => [$m], $check['errors']));
        }

        try {
            $ticket = $this->tickets->create((int) $request->user()->id, $check['clean']);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['subject' => [$e->getMessage()]]);
        }

        $this->mailer->newTicket($ticket, $this->tickets->username((int) $request->user()->id));

        return new JsonResponse($this->present($ticket, $this->team->roleFor($request->user()), (int) $request->user()->id), 201);
    }

    /** GET /tickets/{ticket} - also marks the ticket as read for the viewer */
    public function show(Request $request, int $ticket): JsonResponse
    {
        [$role, $userId, $row] = $this->context($request, $ticket);

        $this->tickets->markRead($ticket, $userId);

        return new JsonResponse($this->present($row, $role, $userId) + [
            'messages' => $this->tickets->messages($ticket),
        ]);
    }

    /** POST /tickets/{ticket}/messages */
    public function reply(Request $request, int $ticket): JsonResponse
    {
        [$role, $userId, $row] = $this->context($request, $ticket);
        abort_unless(
            TicketRules::canReply($role, $row, $userId),
            403,
            $row['status'] === 'closed' ? 'This ticket is closed. Reopen it to write a message.' : 'You are not allowed to reply to this ticket.'
        );

        $check = TicketRules::validateReply($request->input('body'));
        if ($check['error'] !== null) {
            throw ValidationException::withMessages(['body' => [$check['error']]]);
        }

        $isStaff = ! TicketRules::isAuthor($row, $userId);
        $this->tickets->addReply($row, $userId, $check['body'], $isStaff);
        $this->mailer->reply($row, $userId, $this->tickets->username($userId), $check['body']);

        return $this->fresh($ticket, $role, $userId, 201);
    }

    /** PUT /tickets/{ticket}  { status?, priority? } */
    public function update(Request $request, int $ticket): JsonResponse
    {
        [$role, $userId, $row] = $this->context($request, $ticket);
        $status = $request->input('status');
        $priority = $request->input('priority');
        abort_if($status === null && $priority === null, 422, 'Nothing to change.');

        if ($priority !== null) {
            abort_unless(is_string($priority) && in_array($priority, TicketRules::PRIORITIES, true), 422, 'Unknown priority.');
            abort_unless(TicketRules::canChangePriority($role) && TicketRules::canView($role, $row, $userId), 403, 'You are not allowed to change the priority.');
            if ($priority !== $row['priority']) {
                $this->tickets->setPriority($ticket, $userId, $priority);
            }
        }

        if ($status !== null) {
            abort_unless(is_string($status) && in_array($status, TicketRules::STATUSES, true), 422, 'Unknown status.');
            if ($status !== $row['status']) {
                abort_unless(TicketRules::canChangeStatus($role, $row, $userId, $status), 403, 'You are not allowed to set this status.');
                $this->tickets->setStatus($ticket, $userId, $status);
            }
        }

        return $this->fresh($ticket, $role, $userId);
    }

    /** DELETE /tickets/{ticket} */
    public function destroy(Request $request, int $ticket): JsonResponse
    {
        [$role] = $this->context($request, $ticket);
        abort_unless(TicketRules::canDelete($role), 403, 'You are not allowed to delete tickets.');

        $this->tickets->delete($ticket);

        return new JsonResponse(null, 204);
    }

    // ------------------------------------------------------------------ internals

    /** @return array{0: array<string, mixed>, 1: int, 2: array<string, mixed>} role, user id, ticket */
    private function context(Request $request, int $ticket): array
    {
        $user = $request->user();
        $role = $this->team->roleFor($user);
        $row = $this->tickets->find($ticket);
        abort_if($row === null || ! TicketRules::canView($role, $row, (int) $user->id), 404, 'Ticket not found.');

        return [$role, (int) $user->id, $row];
    }

    private function fresh(int $ticket, array $role, int $userId, int $status = 200): JsonResponse
    {
        $row = $this->tickets->find($ticket) ?? [];

        return new JsonResponse($this->present($row, $role, $userId) + ['messages' => $this->tickets->messages($ticket)], $status);
    }

    /** @return array<string, mixed> */
    private function present(array $row, array $role, int $userId): array
    {
        return [
            'id' => $row['id'],
            'subject' => $row['subject'],
            'description' => $row['description'],
            'priority' => $row['priority'],
            'status' => $row['status'],
            'author' => ['id' => $row['user_id'], 'username' => $this->tickets->username((int) $row['user_id'])],
            'created_at' => $this->iso($row['created_at'] ?? null),
            'updated_at' => $this->iso($row['last_activity_at'] ?? $row['updated_at'] ?? null),
            'can' => TicketRules::ticketAbilities($role, $row, $userId),
        ];
    }

    private function iso(mixed $value): ?string
    {
        $time = is_string($value) ? strtotime($value) : false;

        return $time === false ? null : date(DATE_ATOM, $time);
    }
}
