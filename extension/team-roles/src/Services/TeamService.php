<?php

declare(strict_types=1);

namespace TeamRoles\Services;

use DomainException;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use TeamRoles\Support\Defaults;
use TeamRoles\Support\PermissionMap;

/**
 * Team management - by floiofficial.
 *
 * Everything about roles and members. The source of truth is the role in
 * ext_team_roles_members; the users.root_admin column is derived from it
 * (needed so moderators/administrators get into the admin area at all).
 */
final class TeamService
{
    public const ROLES = 'ext_team_roles_roles';
    public const MEMBERS = 'ext_team_roles_members';

    /** @var array<int, array{slug: string, name: string, rank: int, permissions: list<string>, staff: bool}> */
    private array $cache = [];

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public static function make(): self
    {
        return new self(app('db')->connection());
    }

    // ------------------------------------------------------------------ Lesen

    /** Role of a user (rank, rights). Creates missing data on first access. */
    public function roleFor(object $user): array
    {
        $id = (int) $user->id;
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        $role = $this->lookup($id);
        if ($role === null) {
            $this->bootstrap();
            $role = $this->lookup($id);
        }

        // Unbekannt => normaler Benutzer ohne Rechte (fail-closed).
        return $this->cache[$id] = $role ?? $this->fallbackRole();
    }

    public function rankOfUserId(int $userId): int
    {
        $role = $this->lookup($userId);
        if ($role !== null) {
            return $role['rank'];
        }

        // No entry: a root admin without a role counts as Owner to be safe.
        $isRoot = (int) $this->db->table('users')->where('id', $userId)->value('root_admin') === 1;
        $slug = $isRoot ? Defaults::OWNER : Defaults::USER;

        return (int) ($this->db->table(self::ROLES)->where('slug', $slug)->value('rank') ?? ($isRoot ? 100 : 10));
    }

    /** @return list<array<string, mixed>> */
    public function rolesList(): array
    {
        $this->ensureRoles();

        $counts = [];
        foreach ($this->db->table(self::MEMBERS)->selectRaw('role_slug, COUNT(*) as c')->groupBy('role_slug')->get() as $row) {
            $counts[$row->role_slug] = (int) $row->c;
        }

        $out = [];
        foreach ($this->db->table(self::ROLES)->orderByDesc('rank')->get() as $row) {
            $owner = $row->slug === Defaults::OWNER;
            $permissions = $owner ? PermissionMap::allKeys() : $this->decode($row->permissions);
            $out[] = [
                'slug' => $row->slug,
                'name' => $row->name,
                'rank' => (int) $row->rank,
                'locked' => $owner,
                'permissions' => $permissions,
                'staff' => $owner || PermissionMap::grantsAdminAccess($permissions),
                'members' => $counts[$row->slug] ?? 0,
            ];
        }

        return $out;
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, int>} */
    public function members(string $search, string $role, int $page, int $perPage): array
    {
        $query = $this->db->table('users as u')
            ->leftJoin(self::MEMBERS . ' as m', 'm.user_id', '=', 'u.id');

        if ($search !== '') {
            $like = '%' . addcslashes($search, '\\%_') . '%';
            $query->where(function ($q) use ($like) {
                $q->where('u.username', 'like', $like)->orWhere('u.email', 'like', $like);
            });
        }
        if ($role !== '') {
            $query->where('m.role_slug', $role);
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('u.id')->forPage($page, $perPage)
            ->get(['u.id', 'u.username', 'u.email', 'm.role_slug as role']);

        $data = [];
        foreach ($rows as $row) {
            $data[] = ['id' => (int) $row->id, 'username' => $row->username, 'email' => $row->email, 'role' => $row->role ?? Defaults::USER];
        }

        return ['data' => $data, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]];
    }

    /** @return array<string, mixed>|null */
    public function member(int $userId): ?array
    {
        $user = $this->db->table('users')->where('id', $userId)->first(['id', 'username', 'email', 'root_admin']);
        if ($user === null) {
            return null;
        }

        $role = $this->lookup($userId) ?? $this->fallbackRole();

        return [
            'id' => (int) $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $role['slug'],
            'permissions' => $role['slug'] === Defaults::OWNER ? PermissionMap::allKeys() : $role['permissions'],
        ];
    }

    public function countOwners(): int
    {
        return (int) $this->db->table(self::MEMBERS)->where('role_slug', Defaults::OWNER)->count();
    }

    // --------------------------------------------------------------- Schreiben

    /** @param list<string> $permissions */
    public function setRolePermissions(string $slug, array $permissions): void
    {
        $this->ensureRoles();

        if ($slug === Defaults::OWNER) {
            throw new InvalidArgumentException('The Owner\'s permissions cannot be changed.');
        }
        if (! $this->db->table(self::ROLES)->where('slug', $slug)->exists()) {
            throw new InvalidArgumentException('Unknown role.');
        }

        $clean = [];
        foreach ($permissions as $permission) {
            if (! is_string($permission) || ! PermissionMap::isGrantable($permission)) {
                throw new InvalidArgumentException('Unknown permission: ' . (is_string($permission) ? $permission : gettype($permission)));
            }
            $clean[$permission] = true;
        }
        $clean = array_keys($clean);
        sort($clean);

        $this->db->table(self::ROLES)->where('slug', $slug)->update([
            'permissions' => json_encode($clean, JSON_THROW_ON_ERROR),
            'updated_at' => $this->now(),
        ]);

        $this->cache = [];
        $this->reconcile();
    }

    public function setMemberRole(int $userId, string $slug): void
    {
        $this->ensureRoles();

        if (! $this->db->table(self::ROLES)->where('slug', $slug)->exists()) {
            throw new InvalidArgumentException('Unknown role.');
        }
        if (! $this->db->table('users')->where('id', $userId)->exists()) {
            throw new InvalidArgumentException('User not found.');
        }

        $current = $this->db->table(self::MEMBERS)->where('user_id', $userId)->value('role_slug');
        if ($current === Defaults::OWNER && $slug !== Defaults::OWNER && $this->countOwners() <= 1) {
            throw new DomainException('There must always be at least one Owner.');
        }

        $now = $this->now();
        if ($current === null) {
            $this->db->table(self::MEMBERS)->insert(['user_id' => $userId, 'role_slug' => $slug, 'created_at' => $now, 'updated_at' => $now]);
        } else {
            $this->db->table(self::MEMBERS)->where('user_id', $userId)->update(['role_slug' => $slug, 'updated_at' => $now]);
        }

        $this->cache = [];
        $this->reconcile();
    }

    // ---------------------------------------------------------------- Abgleich

    /** Create the default roles (only if missing) and reconcile all users. */
    public function bootstrap(): void
    {
        $this->ensureRoles();
        $this->reconcile();
    }

    /**
     * 1. Waisen-Eintraege entfernen
     * 2. Users without a role: root admin => Owner (previous behaviour stays), otherwise User
     * 3. users.root_admin aus der Rolle ableiten (staff = Owner or a role with at least one non-ticket permission)
     */
    public function reconcile(): void
    {
        $this->db->transaction(function (): void {
            $now = $this->now();

            $this->db->table(self::MEMBERS)
                ->whereNotIn('user_id', fn ($q) => $q->select('id')->from('users'))
                ->delete();

            $missing = $this->db->table('users as u')
                ->leftJoin(self::MEMBERS . ' as m', 'm.user_id', '=', 'u.id')
                ->whereNull('m.id')
                ->selectRaw('u.id, CASE WHEN u.root_admin = 1 THEN ? ELSE ? END, ?, ?', [Defaults::OWNER, Defaults::USER, $now, $now]);
            $this->db->table(self::MEMBERS)->insertUsing(['user_id', 'role_slug', 'created_at', 'updated_at'], $missing);

            $staff = $this->staffSlugs();

            $this->db->table('users')
                ->whereIn('id', fn ($q) => $q->select('user_id')->from(self::MEMBERS)->whereIn('role_slug', $staff))
                ->where('root_admin', '!=', 1)
                ->update(['root_admin' => 1]);

            $this->db->table('users')
                ->whereIn('id', fn ($q) => $q->select('user_id')->from(self::MEMBERS)->whereNotIn('role_slug', $staff))
                ->where('root_admin', '!=', 0)
                ->update(['root_admin' => 0]);
        });

        $this->cache = [];
    }

    // ------------------------------------------------------------------ intern

    /** @return list<string> */
    private function staffSlugs(): array
    {
        $slugs = [Defaults::OWNER];
        foreach ($this->db->table(self::ROLES)->get(['slug', 'permissions']) as $row) {
            if ($row->slug !== Defaults::OWNER && PermissionMap::grantsAdminAccess($this->decode($row->permissions))) {
                $slugs[] = $row->slug;
            }
        }

        return $slugs;
    }

    private function ensureRoles(): void
    {
        $existing = $this->db->table(self::ROLES)->pluck('slug')->all();
        $now = $this->now();
        foreach (Defaults::roles() as $role) {
            if (in_array($role['slug'], $existing, true)) {
                continue;
            }
            $this->db->table(self::ROLES)->insert([
                'slug' => $role['slug'],
                'name' => $role['name'],
                'rank' => $role['rank'],
                'permissions' => json_encode($role['permissions'], JSON_THROW_ON_ERROR),
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** @return array{slug: string, name: string, rank: int, permissions: list<string>, staff: bool}|null */
    private function lookup(int $userId): ?array
    {
        $row = $this->db->table(self::MEMBERS . ' as m')
            ->join(self::ROLES . ' as r', 'r.slug', '=', 'm.role_slug')
            ->where('m.user_id', $userId)
            ->first(['r.slug', 'r.name', 'r.rank', 'r.permissions']);

        if ($row === null) {
            return null;
        }

        $owner = $row->slug === Defaults::OWNER;
        $permissions = $this->decode($row->permissions);

        return [
            'slug' => (string) $row->slug,
            'name' => (string) $row->name,
            'rank' => (int) $row->rank,
            'permissions' => $permissions,
            'staff' => $owner || PermissionMap::grantsAdminAccess($permissions),
        ];
    }

    private function fallbackRole(): array
    {
        return ['slug' => Defaults::USER, 'name' => 'User / Customer', 'rank' => 10, 'permissions' => [], 'staff' => false];
    }

    /** @return list<string> */
    private function decode(mixed $json): array
    {
        $value = is_string($json) ? json_decode($json, true) : $json;

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
