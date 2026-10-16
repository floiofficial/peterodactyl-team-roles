<?php

declare(strict_types=1);

namespace TeamRoles;

use Illuminate\Routing\Router;
use Pterodactyl\Extensions\ExtensionProvider;
use TeamRoles\Http\Middleware\EnforceTeamPermissions;
use TeamRoles\Services\TeamService;

/**
 * Teamverwaltung - von floiofficial.
 *
 * Aendert KEINEN Core-Code: Die Rechtepruefung wird zur Laufzeit an die Middleware-Gruppen
 * des Panels gehaengt und verschwindet mit "p:extension:disable team-roles" wieder.
 */
class TeamRolesProvider extends ExtensionProvider
{
    /** Gruppen aus bootstrap/app.php des Panels. */
    public const GROUPS = ['admin-api', 'application-api', 'client-api'];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(TeamService::class, static fn () => TeamService::make());
    }

    public function boot(): void
    {
        // Die Absicherung ZUERST und so einfach wie moeglich: Faellt spaeter etwas aus,
        // soll sie trotzdem schon haengen.
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
