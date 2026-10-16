<?php

declare(strict_types=1);

namespace TeamRoles\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use TeamRoles\Services\TeamService;
use TeamRoles\Support\Defaults;
use TeamRoles\Support\Policy;
use Throwable;

/**
 * Team management - by floiofficial.
 *
 * Attached behind the middleware groups admin-api, application-api and client-api
 * (so it runs AFTER sign-in and AFTER the core check "is admin").
 *
 * - Normale Kunden (root_admin = 0): sofort durchgelassen, null zusaetzliche Datenbankabfragen.
 * - Owner: alles erlaubt.
 * - All other staff roles: only what the role allows.
 */
final class EnforceTeamPermissions
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if ($user === null) {
            abort(403, 'Authentication required.');
        }

        // Not an admin => this extension does not apply (the core decides).
        if (! (bool) ($user->root_admin ?? false)) {
            return $next($request);
        }

        $parsed = Policy::parse($request->path());
        if ($parsed === null) {
            return $next($request);
        }

        try {
            $team = TeamService::make();
            $role = $team->roleFor($user);
        } catch (Throwable $e) {
            report($e);
            abort(503, 'Team management is unavailable - access blocked for security reasons.');
        }

        $area = $parsed['area'];
        $rest = $parsed['rest'];
        $method = $request->method();

        if ($role['slug'] === Defaults::OWNER) {
            return $this->finish($request, $next, $team, $area, $method, $rest);
        }

        if ($area === 'client') {
            $decision = Policy::decideClient($role, $method, $rest, (string) $request->query('type', ''));
            if ($decision['permission'] !== null) {
                $own = $decision['serverScope'] && $this->ownsOrSharesServer($request, $user);
                if (! $own && ! in_array($decision['permission'], $role['permissions'], true)) {
                    abort(403, 'You are missing the permission "' . $decision['permission'] . '".');
                }
            }

            return $next($request);
        }

        $decision = Policy::decideAdmin($role, $method, $rest);
        if (! $decision['allow']) {
            abort(403, $decision['reason']);
        }

        if ($decision['rankCheck']) {
            $this->guardRank($request, $team, $role, (int) $user->id, $rest);
        }
        if ($decision['stripRoot']) {
            $this->stripAdminFlag($request);
        }

        return $this->finish($request, $next, $team, $area, $method, $rest);
    }

    /** After successful user changes, reconcile the roles with root_admin again. */
    private function finish(Request $request, Closure $next, TeamService $team, string $area, string $method, string $rest): mixed
    {
        $response = $next($request);

        if (Policy::writesUsers($area, $method, $rest) && method_exists($response, 'isSuccessful') && $response->isSuccessful()) {
            try {
                $team->reconcile();
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $response;
    }

    private function guardRank(Request $request, TeamService $team, array $role, int $actorId, string $rest): void
    {
        $targetId = $this->resolveTargetId($request, $rest);
        if ($targetId === null) {
            abort(403, 'The target user could not be determined unambiguously.');
        }
        if ($targetId === $actorId) {
            return;
        }
        if ($team->rankOfUserId($targetId) >= $role['rank']) {
            abort(403, 'You may not change users with the same or a higher rank.');
        }
    }

    private function resolveTargetId(Request $request, string $rest): ?int
    {
        foreach (['user', 'userId', 'user_id'] as $name) {
            $value = $request->route($name);
            if (is_object($value) && isset($value->id)) {
                return (int) $value->id;
            }
            if (is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }
            if (is_int($value)) {
                return $value;
            }
        }

        return Policy::targetUserId($rest);
    }

    /** Non-owners may never set the admin flag - the field is discarded. */
    private function stripAdminFlag(Request $request): void
    {
        $bag = $request->isJson() ? $request->json() : $request->request;
        foreach (['root_admin', 'rootAdmin', 'root-admin'] as $field) {
            $bag->remove($field);
        }
    }

    private function ownsOrSharesServer(Request $request, object $user): bool
    {
        $server = $request->route('server');
        if (! is_object($server) || ! isset($server->id)) {
            return false;
        }
        if (isset($server->owner_id) && (int) $server->owner_id === (int) $user->id) {
            return true;
        }

        try {
            return DB::table('subusers')->where('server_id', $server->id)->where('user_id', $user->id)->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
