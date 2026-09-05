<?php

namespace FyWolf\Arma3Manager\Providers;

use App\Models\ApiKey;
use App\Models\Egg;
use App\Models\Role;
use FyWolf\Arma3Manager\Console\Commands\DiagnoseServerCommand;
use FyWolf\Arma3Manager\Console\Commands\SyncProfilesCommand;
use FyWolf\Arma3Manager\Integrations\Workshop\SteamWorkshopClient;
use FyWolf\Arma3Manager\Models\CapabilityProfile;
use FyWolf\Arma3Manager\Models\EggCapabilityProfile;
use FyWolf\Arma3Manager\Support\CapabilityResolver;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\ServiceProvider;

/**
 * The plugin's only service provider.
 *
 * `src/Providers/` MUST stay flat. `Plugin::getProviders()` runs a *recursive*
 * `File::allFiles()` and builds each class name by concatenating the relative
 * pathname onto the namespace, so a nested `src/Providers/Foo/Bar.php` becomes
 * the class string `FyWolf\Arma3Manager\Providers\Foo/Bar` — a forward slash
 * inside a class name. `class_exists()` returns false and the panel skips it
 * without a word. Anything that is not a ServiceProvider lives elsewhere:
 * API clients in `src/Integrations`, helpers in `src/Support`.
 */
class Arma3ManagerProvider extends ServiceProvider
{
    /**
     * This plugin's own application-ACL resource.
     *
     * Separate from the billing bridge's `billing` and from minecraft-manager's
     * `minecraft`: granting a service the ability to hand out Arma mod sets is
     * a different decision from letting it provision servers, and the two
     * should be revocable independently.
     */
    public const RESOURCE_NAME = 'arma3';

    public function register(): void
    {
        ApiKey::registerCustomResourceName(self::RESOURCE_NAME);

        // Makes these first-class model permissions, so an admin role can be
        // granted profile or catalogue management without full panel access.
        Role::registerCustomDefaultPermissions('a3_capability_profile');
        Role::registerCustomModelIcon('a3_capability_profile', 'tabler-target-arrow');

        // Singleton so the per-egg memo survives across the several components
        // that each ask "what can this server do?" while rendering one page.
        $this->app->singleton(CapabilityResolver::class);

        // Stateless, but a singleton so the per-request item cache inside it is
        // shared: rendering a forty-mod load order asks for the same ids from
        // several columns.
        $this->app->singleton(SteamWorkshopClient::class);
    }

    public function boot(): void
    {
        $this->registerEggRelation();
        $this->registerActivityStrings();
        $this->registerCommands();
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncProfilesCommand::class,
                DiagnoseServerCommand::class,
            ]);
        }
    }

    // There are deliberately no routes and no scheduled tasks. Both existed only
    // for mod sets — an API for a billing service to grant a curated set, and an
    // hourly reaper for installs abandoned by a `queue:restart`. The feature was
    // never used and is gone, and an endpoint or a cron entry left behind for it
    // is a surface to keep working for nobody.

    /**
     * Graft the profile relation onto the panel's own Egg model.
     *
     * The same technique player-counter uses for `gameQuery`. Note the relation
     * name is namespaced enough not to collide with minecraft-manager's
     * `mcCapabilityProfile` — two plugins resolving the same relation name onto
     * Egg would silently fight, last one booted winning.
     */
    private function registerEggRelation(): void
    {
        Egg::resolveRelationUsing('a3CapabilityProfile', fn (Egg $egg) => $egg->hasOneThrough(
            CapabilityProfile::class,
            EggCapabilityProfile::class,
            'egg_id',
            'id',
            'id',
            'a3_capability_profile_id',
        ));
    }

    /**
     * Teach the activity feed how to render this plugin's events.
     *
     * `ActivityLog` renders an entry with
     * `trans_choice('activity.' . str($event)->replace(':', '.'), …)` — a lookup
     * in the *root* translation namespace. A plugin's `lang/` directory is
     * mounted as the namespaced `arma3-manager::`, which that lookup never
     * consults, so a `lang/en/activity.php` file here would be ignored and every
     * event would render as its raw key. `Lang::addLines()` injects into the
     * root namespace at runtime, which is the only thing that works.
     */
    private function registerActivityStrings(): void
    {
        Lang::addLines([
            'activity.server.arma3.mod-add' => 'Added <b>:count</b> mod(s) to the load order',
            'activity.server.arma3.mod-remove' => 'Removed <b>:mod</b> from the load order',
            'activity.server.arma3.mod-reorder' => 'Moved <b>:mod</b> in the load order',
            'activity.server.arma3.mod-sync' => 'Wrote the mod list to the server (:missing still to download)',
            'activity.server.arma3.mission-rotation' => 'Changed the mission rotation for <b>:mission</b>',
            'activity.server.arma3.mission-delete' => 'Deleted the mission <b>:mission</b>',
            'activity.server.arma3.config-edit' => 'Edited <b>:file</b> (:changed)',
            'activity.server.arma3.config-create' => 'Created <b>:file</b>',
            'activity.server.arma3.config-locked-rejected' => 'Attempted to change locked setting(s) <b>:keys</b> in <b>:file</b>',
            'activity.server.arma3.preset-import' => 'Imported the launcher preset <b>:preset</b> (:mods mods)',
            'activity.server.arma3.preset-export' => 'Exported the launcher preset <b>:preset</b>',
            'activity.server.arma3.preset-apply' => 'Made <b>:preset</b> the active preset (:mods mods)',
            'activity.server.arma3.preset-delete' => 'Deleted the saved preset <b>:preset</b>',
            'activity.server.arma3.parameters-edit' => 'Changed the startup parameters (:changed)',
            'activity.server.arma3.modset-install' => 'Installed the mod set <b>:set</b> (:items items)',
            'activity.server.arma3.modset-failed' => 'Failed to install the mod set <b>:set</b>: :error',
            'activity.server.arma3.modset-grant' => 'Granted the mod set <b>:set</b>',
            'activity.server.arma3.modset-revoke' => 'Withdrew the mod set <b>:set</b>',
        ], 'en');
    }
}
