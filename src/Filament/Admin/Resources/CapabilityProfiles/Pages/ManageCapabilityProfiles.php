<?php

namespace FyWolf\Arma3Manager\Filament\Admin\Resources\CapabilityProfiles\Pages;

use App\Models\Egg;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use FyWolf\Arma3Manager\Filament\Admin\Resources\CapabilityProfiles\CapabilityProfileResource;
use FyWolf\Arma3Manager\Models\CapabilityProfile;
use FyWolf\Arma3Manager\Support\CapabilityResolver;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The profile list, plus the two things an operator actually needs.
 *
 * **Map unmapped eggs** is the same logic as `arma3-manager:sync-profiles` with
 * a button, because the command exists to be run after importing eggs and
 * nobody reads a README at the moment they import an egg.
 *
 * **Export** exists because uninstalling this plugin rolls its migrations back,
 * which drops every mapping an administrator made. `PluginService::uninstallPlugin`
 * calls `$migrator->reset()` and there is no way for a plugin to opt out, so the
 * only defence is a file the operator took first.
 */
class ManageCapabilityProfiles extends ManageRecords
{
    protected static string $resource = CapabilityProfileResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            Action::make('sync')
                ->label('Map unmapped eggs')
                ->icon('tabler-wand')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Map Arma 3 eggs to profiles')
                ->modalDescription('Looks at every egg with no profile and maps the ones that look like Arma 3, judged on tags, the egg name and the Steam app id in its variables. An egg somebody already mapped by hand is never touched.')
                ->action(function (): void {
                    try {
                        $resolver = app(CapabilityResolver::class);
                        $mapped = 0;

                        foreach (Egg::query()->with('variables')->get() as $egg) {
                            $already = CapabilityProfile::query()
                                ->whereHas('eggs', fn ($query) => $query->whereKey($egg->id))
                                ->exists();

                            if ($already) {
                                continue;
                            }

                            $flavour = $resolver->detectFlavourForEgg($egg);

                            if (! $flavour) {
                                continue;
                            }

                            $profile = CapabilityProfile::query()->where('flavour', $flavour->value)->first();

                            if ($profile) {
                                $profile->eggs()->syncWithoutDetaching([$egg->id]);
                                $mapped++;
                            }
                        }

                        $resolver->flush();

                        Notification::make()
                            ->title($mapped === 0 ? 'Nothing to map' : "Mapped {$mapped} egg(s)")
                            ->body($mapped === 0
                                ? 'Every Arma 3 egg already has a profile, or no egg looked like Arma 3.'
                                : 'Review the mapping below — a detected profile is a guess and can be changed.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()->title('Could not map eggs')->body($exception->getMessage())->danger()->send();
                    }
                }),

            Action::make('export')
                ->label('Export mappings')
                ->icon('tabler-download')
                ->color('gray')
                ->modalHeading('Export the egg mapping')
                ->modalDescription('Uninstalling this plugin rolls its migrations back and drops every mapping below. Keep this file if you intend to reinstall.')
                ->action(function (): StreamedResponse {
                    $payload = CapabilityProfile::query()
                        ->with('eggs:id,name,uuid')
                        ->get()
                        ->map(fn (CapabilityProfile $profile): array => [
                            'name' => $profile->name,
                            'flavour' => $profile->flavour,
                            'capabilities' => $profile->capabilities,
                            'mods_dir' => $profile->mods_dir,
                            'servermods_dir' => $profile->servermods_dir,
                            'missions_dir' => $profile->missions_dir,
                            'profiles_dir' => $profile->profiles_dir,
                            'server_binary' => $profile->server_binary,
                            'config_files' => $profile->config_files,
                            'mod_list_variables' => $profile->mod_list_variables,
                            'servermod_list_variables' => $profile->servermod_list_variables,
                            'parameter_variables' => $profile->parameter_variables,
                            'headless_variables' => $profile->headless_variables,
                            // Eggs are exported by uuid as well as name: a
                            // reinstall onto a rebuilt panel has different ids,
                            // and a name alone is not unique.
                            'eggs' => $profile->eggs->map(fn (Egg $egg): array => [
                                'uuid' => $egg->uuid,
                                'name' => $egg->name,
                            ])->all(),
                        ])
                        ->all();

                    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                    return response()->streamDownload(
                        fn () => print($json),
                        'arma3-manager-profiles.json',
                        ['Content-Type' => 'application/json'],
                    );
                }),
        ];
    }
}
