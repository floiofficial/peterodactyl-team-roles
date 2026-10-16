<?php

declare(strict_types=1);

namespace TeamRoles;

use Illuminate\Routing\Router;
use Pterodactyl\Extensions\ExtensionProvider;
use TeamRoles\Http\Middleware\EnforceTeamPermissions;
use TeamRoles\Services\TeamService;
use TeamRoles\Services\TicketMailer;
use TeamRoles\Services\TicketService;

/**
 * Team management - by floiofficial.
 *
 * Does NOT change core code: the permission check is attached to the panel's middleware groups
 * at runtime and disappears again with "p:extension:disable team-roles".
 */
class TeamRolesProvider extends ExtensionProvider
{
    /** Groups from the panel's bootstrap/app.php. */
    public const GROUPS = ['admin-api', 'application-api', 'client-api'];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(TeamService::class, static fn () => TeamService::make());
        $this->app->singleton(TicketService::class, static fn () => TicketService::make());
        $this->app->singleton(TicketMailer::class, static fn () => TicketMailer::make());
    }

    public function boot(): void
    {
        // Protection FIRST and as simple as possible: if something fails later,
        // it should already be attached.
        $this->protect();
        $this->app->booted(fn () => $this->protect());

        $this->registerApiRoutes();
        $this->loadExtensionMigrations();
    }

    private function protect(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        foreach (self::GROUPS as $group) {
            if (! in_array(EnforceTeamPermissions::class, $router->getMiddlewareGroups()[$group] ?? [], true)) {
                $router->pushMiddlewareToGroup($group, EnforceTeamPermissions::class);
            }
        }
    }
}
