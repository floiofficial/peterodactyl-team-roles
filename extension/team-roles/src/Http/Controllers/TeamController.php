<?php

declare(strict_types=1);

namespace TeamRoles\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Router;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use TeamRoles\Http\Middleware\EnforceTeamPermissions;
use TeamRoles\Services\TeamService;
use TeamRoles\Support\Defaults;
use TeamRoles\Support\PermissionMap;
use TeamRoles\TeamRolesProvider;

/**
 * Teamverwaltung - von floiofficial.
 *
 * Alle Verwaltungs-Endpunkte sind OWNER-ONLY - zusaetzlich zur Middleware wird hier
 * nochmal selbst geprueft (doppelt haelt besser).
 */
final class TeamController extends Controller
{
    public function __construct(private readonly TeamService $team)
    {
    }

    /** GET /api/client/extensions/team-roles/me (jeder angemeldete Nutzer) */
    public function me(Request $request): JsonResponse
    {
        $role = $this->team->roleFor($request->user());

        return new JsonResponse([
            'role' => $role['slug'],
            'name' => $role['name'],
            'staff' => $role['staff'],
            'permissions' => $role['slug'] === Defaults::OWNER ? ['*'] : $role['permissions'],
        ]);
    }

    public function health(Request $request, Router $router): JsonResponse
    {
        $this->ownerOnly($request);
        $this->team->bootstrap();

        $groups = [];
        foreach (TeamRolesProvider::GROUPS as $group) {
            $groups[$group] = in_array(EnforceTeamPermissions::class, $router->getMiddlewareGroups()[$group] ?? [], true);
        }

        return new JsonResponse([
            'protected' => ! in_array(false, $groups, true),
            'groups' => $groups,
            'owners' => $this->team->countOwners(),
        ]);
    }

    public function roles(Request $request): JsonResponse
    {
        $this->ownerOnly($request);
        $this->team->bootstrap();

        return new JsonResponse(['roles' => $this->team->rolesList(), 'catalog' => PermissionMap::catalogForUi()]);
    }

    public function updateRole(Request $request, string $role): JsonResponse
    {
        $this->ownerOnly($request);
        $data = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['string']]);

        try {
            $this->team->setRolePermissions($role, $data['permissions']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['permissions' => [$e->getMessage()]]);
        }

        return new JsonResponse(['roles' => $this->team->rolesList(), 'catalog' => PermissionMap::catalogForUi()]);
    }

    public function members(Request $request): JsonResponse
    {
        $this->ownerOnly($request);
        $this->team->bootstrap();

        $page = max(1, (int) $request->query('page', 1));
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 100);
        $role = (string) $request->query('role', '');

        return new JsonResponse($this->team->members($search, $role, $page, 25));
    }

    public function member(Request $request, int $userId): JsonResponse
    {
        $this->ownerOnly($request);
        $this->team->bootstrap();

        $member = $this->team->member($userId);
        abort_if($member === null, 404, 'Benutzer nicht gefunden.');

        return new JsonResponse($member + ['roles' => $this->team->rolesList()]);
    }

    public function updateMember(Request $request, int $userId): JsonResponse
    {
        $this->ownerOnly($request);
        $data = $request->validate(['role' => ['required', 'string', 'max:32']]);

        try {
            $this->team->setMemberRole($userId, $data['role']);
        } catch (InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['role' => [$e->getMessage()]]);
        }

        return new JsonResponse($this->team->member($userId) + ['roles' => $this->team->rolesList()]);
    }

    private function ownerOnly(Request $request): void
    {
        $role = $this->team->roleFor($request->user());
        abort_unless($role['slug'] === Defaults::OWNER, 403, 'Nur der Owner darf die Teamverwaltung nutzen.');
    }
}
