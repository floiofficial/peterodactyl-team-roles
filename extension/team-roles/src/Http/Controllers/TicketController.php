<?php

declare(strict_types=1);

namespace TeamRoles\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Throwable;
use TeamRoles\Services\TeamService;
use TeamRoles\Services\TicketMailer;
use TeamRoles\Services\TicketService;
use TeamRoles\Support\TicketFiles;
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

    /** GET /tickets/settings - { email_notifications: bool } */
    public function settings(Request $request): JsonResponse
    {
        return new JsonResponse(['email_notifications' => $this->tickets->mailEnabled((int) $request->user()->id)]);
    }

    /** PUT /tickets/settings  { email_notifications: bool } */
    public function updateSettings(Request $request): JsonResponse
    {
        $value = filter_var($request->input('email_notifications'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw ValidationException::withMessages(['email_notifications' => ['Please send true or false.']]);
        }
        $userId = (int) $request->user()->id;
        $this->tickets->setMailEnabled($userId, $value);

        return new JsonResponse(['email_notifications' => $this->tickets->mailEnabled($userId)]);
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

        $files = $this->incomingFiles($request, 0);

        try {
            $ticket = $this->tickets->create((int) $request->user()->id, $check['clean']);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['subject' => [$e->getMessage()]]);
        }
        $this->saveFiles((int) $ticket['id'], null, (int) $request->user()->id, $files);

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

        $files = $this->incomingFiles($request, $this->tickets->attachmentCount($ticket));

        $isStaff = ! TicketRules::isAuthor($row, $userId);
        $messageId = $this->tickets->addReply($row, $userId, $check['body'], $isStaff);
        $this->saveFiles($ticket, $messageId, $userId, $files);
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

        $stored = $this->tickets->attachmentFiles($ticket);
        $this->tickets->delete($ticket);
        $this->removeFiles($ticket, $stored);

        return new JsonResponse(null, 204);
    }

    /** GET /tickets/{ticket}/attachments/{attachment} - only for people who may see the ticket */
    public function download(Request $request, int $ticket, int $attachment): BinaryFileResponse
    {
        $this->context($request, $ticket);
        $row = $this->tickets->findAttachment($ticket, $attachment);
        abort_if($row === null, 404, 'File not found.');
        $path = $this->fileDir($ticket) . '/' . basename((string) $row['stored']);
        abort_unless(is_file($path), 404, 'File not found.');

        $name = (string) $row['name'];
        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => TicketFiles::mimeFor((string) $row['kind']),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, (string) preg_replace('/[^\x20-\x7E]|[%\\\/"]/', '_', $name));

        return $response;
    }

    // ------------------------------------------------------------------ internals

    private function fileDir(int $ticket): string
    {
        return rtrim(storage_path('app/team-roles/tickets'), '/') . '/' . $ticket;
    }

    /**
     * Check the uploaded files BEFORE anything is saved (all or nothing).
     *
     * @return list<array{content: string, meta: array<string, mixed>}>
     */
    private function incomingFiles(Request $request, int $already): array
    {
        $uploads = $request->file('files');
        if ($uploads === null) {
            return [];
        }
        $uploads = array_values(array_filter(is_array($uploads) ? $uploads : [$uploads]));
        if ($uploads === []) {
            return [];
        }

        $errors = [];
        if (count($uploads) > TicketFiles::MAX_FILES_PER_MESSAGE) {
            $errors[] = 'You can attach at most ' . TicketFiles::MAX_FILES_PER_MESSAGE . ' files per message.';
        }
        if ($already + count($uploads) > TicketFiles::MAX_FILES_PER_TICKET) {
            $errors[] = 'This ticket has too many attachments already.';
        }

        $ready = [];
        if ($errors === []) {
            foreach ($uploads as $file) {
                $name = $file instanceof UploadedFile ? TicketFiles::cleanName($file->getClientOriginalName()) : 'file';
                if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getRealPath() === false) {
                    $errors[] = $name . ': the upload failed (the file may be larger than the server allows).';
                    continue;
                }
                $size = $file->getSize();
                if ($size === false || $size > TicketFiles::MAX_BYTES) {
                    $errors[] = $name . ': the file is larger than ' . (TicketFiles::MAX_BYTES / 1048576) . ' MB.';
                    continue;
                }
                $content = (string) file_get_contents($file->getRealPath());
                $meta = TicketFiles::inspect($content, $file->getClientOriginalName());
                if (! $meta['ok']) {
                    $errors[] = (string) $meta['error'];
                    continue;
                }
                $ready[] = ['content' => $content, 'meta' => $meta];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['files' => $errors]);
        }

        return $ready;
    }

    /**
     * Save checked files. A disk problem must not undo the ticket/reply that was already created.
     *
     * @param list<array{content: string, meta: array<string, mixed>}> $files
     */
    private function saveFiles(int $ticket, ?int $messageId, int $userId, array $files): void
    {
        if ($files === []) {
            return;
        }
        try {
            $dir = $this->fileDir($ticket);
            if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
                throw new \RuntimeException('cannot create ' . $dir);
            }
            foreach ($files as $f) {
                $stored = TicketFiles::storageName((string) $f['meta']['ext']);
                if (file_put_contents($dir . '/' . $stored, $f['content']) === false) {
                    throw new \RuntimeException('cannot write attachment');
                }
                $this->tickets->addAttachment($ticket, $messageId, $userId, [
                    'name' => (string) $f['meta']['name'],
                    'kind' => (string) $f['meta']['kind'],
                    'size' => (int) $f['meta']['size'],
                    'stored' => $stored,
                ]);
            }
        } catch (Throwable $e) {
            try {
                logger()->warning('team-roles: saving a ticket attachment failed: ' . $e->getMessage());
            } catch (Throwable) {
                // logging must not fail either
            }
        }
    }

    /** @param list<string> $stored */
    private function removeFiles(int $ticket, array $stored): void
    {
        $dir = $this->fileDir($ticket);
        foreach ($stored as $name) {
            @unlink($dir . '/' . basename($name));
        }
        @rmdir($dir);
    }

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
            'attachments' => $this->tickets->ticketAttachments((int) $row['id']),
            'can' => TicketRules::ticketAbilities($role, $row, $userId),
        ];
    }

    private function iso(mixed $value): ?string
    {
        $time = is_string($value) ? strtotime($value) : false;

        return $time === false ? null : date(DATE_ATOM, $time);
    }
}
