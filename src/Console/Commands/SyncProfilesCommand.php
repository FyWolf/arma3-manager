<?php

namespace FyWolf\Arma3Manager\Console\Commands;

use App\Models\Egg;
use FyWolf\Arma3Manager\Models\CapabilityProfile;
use FyWolf\Arma3Manager\Support\CapabilityResolver;
use Illuminate\Console\Command;

/**
 * Map Arma eggs imported after the plugin was installed.
 *
 * The install-time seeder can only see the eggs that exist when it runs, and on
 * Pelican eggs are imported from the `pelican-eggs` organisation *after* setup
 * — so on a fresh panel the seeder frequently maps nothing at all. This is the
 * command the README tells you to run after importing eggs, and the admin
 * screen's "Unmapped eggs" tab is the same query with buttons.
 */
class SyncProfilesCommand extends Command
{
    protected $signature = 'arma3-manager:sync-profiles {--dry-run : List what would be mapped and change nothing}';

    protected $description = 'Map Arma 3 eggs to capability profiles.';

    public function handle(CapabilityResolver $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $mapped = 0;
        $skipped = 0;

        foreach (Egg::query()->with('variables')->get() as $egg) {
            // Never touch an egg somebody already decided about. An explicit
            // mapping is a decision and this command is a guess.
            $existing = CapabilityProfile::query()
                ->whereHas('eggs', fn ($query) => $query->whereKey($egg->id))
                ->exists();

            if ($existing) {
                $skipped++;

                continue;
            }

            $flavour = $resolver->detectFlavourForEgg($egg);

            if (! $flavour) {
                continue;
            }

            $profile = CapabilityProfile::query()->where('flavour', $flavour->value)->first();

            if (! $profile) {
                $this->warn("No profile exists for {$flavour->value}; run the seeder first.");

                continue;
            }

            $this->line("  {$egg->name} -> {$profile->name}");

            if (! $dryRun) {
                $profile->eggs()->syncWithoutDetaching([$egg->id]);
            }

            $mapped++;
        }

        $resolver->flush();

        $this->info($dryRun
            ? "Would map {$mapped} egg(s). {$skipped} already mapped."
            : "Mapped {$mapped} egg(s). {$skipped} already mapped.");

        return self::SUCCESS;
    }
}
