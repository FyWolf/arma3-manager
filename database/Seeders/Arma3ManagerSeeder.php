<?php

namespace Database\Seeders;

use App\Models\Egg;
use FyWolf\Arma3Manager\Models\CapabilityProfile;
use FyWolf\Arma3Manager\Support\CapabilityResolver;
use Illuminate\Database\Seeder;

/**
 * Create the built-in profiles and map whatever Arma eggs already exist.
 *
 * The mapping half frequently does nothing on a fresh panel, and that is
 * expected rather than a bug: Pelican imports eggs from the `pelican-eggs`
 * organisation *after* setup, so at install time there is often no Arma egg to
 * map. `arma3-manager:sync-profiles` is the same logic runnable afterwards, and
 * the README says to run it.
 *
 * ## Why this is in `Database\Seeders` and not this plugin's namespace
 *
 * The panel resolves a plugin's seeder by class name inside its own
 * `Database\Seeders` namespace, exactly as minecraft-manager does. A seeder
 * namespaced under the plugin compiles fine, is never found, and the install
 * finishes reporting success with no profiles created — so the plugin appears
 * installed and every page stays hidden.
 *
 * Profiles are created with `updateOrCreate` on `name`, so re-running this after
 * an upgrade refreshes the built-in directory layout without duplicating rows —
 * and without touching an egg mapping, which lives in the pivot.
 */
class Arma3ManagerSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = [];

        foreach ((array) config('arma3-manager.profiles', []) as $key => $defaults) {
            if (! is_array($defaults)) {
                continue;
            }

            $profiles[$key] = CapabilityProfile::updateOrCreate(
                ['name' => $defaults['name'] ?? $key],
                [
                    'flavour' => $defaults['flavour'] ?? $key,
                    'capabilities' => $defaults['capabilities'] ?? [],
                    'mods_dir' => $defaults['mods_dir'] ?? 'mods',
                    'servermods_dir' => $defaults['servermods_dir'] ?? null,
                    'missions_dir' => $defaults['missions_dir'] ?? null,
                    'profiles_dir' => $defaults['profiles_dir'] ?? 'profiles',
                    'server_binary' => $defaults['server_binary'] ?? null,
                    'config_files' => $defaults['config_files'] ?? [],
                    'mod_list_variables' => $defaults['mod_list_variables'] ?? [],
                    'servermod_list_variables' => $defaults['servermod_list_variables'] ?? [],
                    'parameter_variables' => $defaults['parameter_variables'] ?? [],
                    'headless_variables' => $defaults['headless_variables'] ?? [],
                ],
            );
        }

        if ($profiles === []) {
            return;
        }

        $resolver = app(CapabilityResolver::class);

        foreach (Egg::query()->with('variables')->get() as $egg) {
            // Never touch an egg somebody already decided about. An explicit
            // mapping is a decision and this seeder is a guess.
            $mapped = CapabilityProfile::query()
                ->whereHas('eggs', fn ($query) => $query->whereKey($egg->id))
                ->exists();

            if ($mapped) {
                continue;
            }

            $flavour = $resolver->detectFlavourForEgg($egg);

            if ($flavour && isset($profiles[$flavour->value])) {
                $profiles[$flavour->value]->eggs()->syncWithoutDetaching([$egg->id]);
            }
        }

        $resolver->flush();
    }
}
