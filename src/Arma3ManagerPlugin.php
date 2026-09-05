<?php

namespace FyWolf\Arma3Manager;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Arma 3 server management, gated per egg.
 *
 * Six server-panel pages — Mods, Workshop, Missions, Configuration, Presets and
 * Parameters — none of which render unless the server's egg resolves to a
 * capability profile that grants them. A dedicated server gets all six; a
 * headless client gets mods, presets and parameters, and no Missions page for a
 * container that hosts no mission. An egg that resolves to nothing shows nothing
 * at all, which is the correct outcome for a custom egg we know nothing about.
 *
 * ## The Steam credential this plugin does not have
 *
 * Arma 3 Workshop items cannot be downloaded by an anonymous SteamCMD login —
 * the account has to own Arma 3. Rather than store one Steam account in the
 * panel and share it across every customer, downloads are performed by each
 * *server's own* container using the STEAM_USER / STEAM_PASS already on its
 * egg. This plugin therefore reads Workshop metadata (which needs nothing) and
 * writes the mod list; the container fetches the files.
 *
 * The one optional credential below is a Steam **Web API** key, which buys the
 * search box and nothing else. Without it a customer pastes a workshop link
 * instead, and every other feature is unaffected.
 */
class Arma3ManagerPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'arma3-manager';
    }

    public function register(Panel $panel): void
    {
        // 'server' -> 'Server', 'admin' -> 'Admin'. One line serves every panel;
        // the directory layout does the routing and missing directories are
        // harmless.
        $id = str($panel->getId())->title();

        $panel->discoverResources(
            plugin_path($this->getId(), "src/Filament/$id/Resources"),
            "FyWolf\\Arma3Manager\\Filament\\$id\\Resources",
        );

        $panel->discoverPages(
            plugin_path($this->getId(), "src/Filament/$id/Pages"),
            "FyWolf\\Arma3Manager\\Filament\\$id\\Pages",
        );

        $panel->discoverWidgets(
            plugin_path($this->getId(), "src/Filament/$id/Widgets"),
            "FyWolf\\Arma3Manager\\Filament\\$id\\Widgets",
        );
    }

    public function boot(Panel $panel): void {}

    /**
     * @return array<int, mixed>
     */
    public function getSettingsForm(): array
    {
        return [
            Section::make('Steam Web API')
                ->description('Optional. A key adds the Workshop search box; without one, customers add mods by pasting a workshop link or id and everything else works exactly the same.')
                ->schema([
                    TextInput::make('steam_api_key')
                        ->label('Web API key')
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->helperText('Request one at steamcommunity.com/dev/apikey. Leave blank to keep the current key. This is NOT a Steam login — no password is ever stored here, and mods are downloaded by each server using its own egg credentials.')
                        ->default(fn () => config('arma3-manager.workshop.api_key')),

                    Toggle::make('steam_clear_key')
                        ->label('Remove the stored key')
                        ->helperText('Saving with a blank field keeps the existing key, so this is the only way to delete one.')
                        ->default(false),

                    Actions::make([
                        Action::make('test_steam')
                            ->label('Test key')
                            ->icon('tabler-plug-connected')
                            ->action(function (Get $get): void {
                                // Test the key currently typed into the form,
                                // falling back to the stored one, so an admin can
                                // verify before committing.
                                $key = $get('steam_api_key') ?: config('arma3-manager.workshop.api_key');

                                if (blank($key)) {
                                    Notification::make()->title('No key to test')->warning()->send();

                                    return;
                                }

                                try {
                                    $response = Http::acceptJson()
                                        ->connectTimeout(4)
                                        ->timeout(8)
                                        ->get((string) config('arma3-manager.workshop.query_url'), [
                                            'key' => $key,
                                            'appid' => (int) config('arma3-manager.workshop.app_id', 107410),
                                            'search_text' => 'CBA_A3',
                                            'numperpage' => 1,
                                            'query_type' => 0,
                                        ]);

                                    if ($response->successful()) {
                                        Notification::make()
                                            ->title('Steam accepted the key')
                                            ->body('Workshop search is available.')
                                            ->success()
                                            ->send();

                                        return;
                                    }

                                    Notification::make()
                                        ->title('Steam rejected the key')
                                        ->body('HTTP ' . $response->status() . ($response->status() === 403 ? ' — the key is invalid or has been revoked.' : ''))
                                        ->danger()
                                        ->send();
                                } catch (Throwable $e) {
                                    Notification::make()->title('Could not reach Steam')->body($e->getMessage())->danger()->send();
                                }
                            }),
                    ]),
                ]),

            Section::make('Egg detection')
                ->description('How a server decides which pages it gets.')
                ->schema([
                    Toggle::make('heuristics_enabled')
                        ->label('Detect unmapped eggs automatically')
                        ->helperText('When an egg has no explicit profile, guess one from its tags, its name and the Steam app id in its variables. Turn this off to show the plugin only on eggs an admin has mapped by hand.')
                        ->default(fn () => (bool) config('arma3-manager.heuristics.enabled', true)),

                    Placeholder::make('profiles_hint')
                        ->label('Per-egg mapping')
                        ->content('Admin → Arma 3 Profiles. Run `php artisan arma3-manager:sync-profiles` after importing new eggs.'),
                ]),

            Section::make('Locked settings')
                ->description('Settings customers can see but not change on the Configuration page.')
                ->schema([
                    TagsInput::make('locked_properties')
                        ->label('Locked server.cfg keys')
                        ->placeholder('maxPlayers')
                        ->helperText('The usual case is maxPlayers, since on a host that sells slots the player limit belongs to the order rather than to the config file. Locked keys stay visible but disabled, and are refused server-side — not merely greyed out in the browser.')
                        ->default(fn () => (array) config('arma3-manager.configs.locked_properties', [])),

                    TextInput::make('locked_reason')
                        ->label('Reason shown to the customer')
                        ->placeholder('Set by your plan — contact support to change it.')
                        ->default(fn () => config('arma3-manager.configs.locked_reason')),
                ]),
        ];
    }

    /**
     * Current values for the settings slide-over.
     *
     * Keys MUST be the form field names, not config keys — the panel passes this
     * straight to `->fillForm()`, which replaces the schema's `->default()`
     * values rather than merging with them. Returning `config('arma3-manager')`
     * wholesale compiles fine, fills nothing, and renders an empty form that
     * saves blanks over a working configuration.
     *
     * Older panel builds do not call this at all (their `HasPluginSettings`
     * declares only `getSettingsForm()` and `saveSettings()`), which is why every
     * field above also carries its own `->default()`. Both paths are correct.
     *
     * @return array<string, mixed>
     */
    public function getSettingsFormData(): array
    {
        return [
            // Never echo the stored key back into the form — the field is
            // write-only, and a blank submission is understood as "unchanged".
            'steam_api_key' => null,
            'steam_clear_key' => false,
            'heuristics_enabled' => (bool) config('arma3-manager.heuristics.enabled', true),
            'locked_properties' => (array) config('arma3-manager.configs.locked_properties', []),
            'locked_reason' => config('arma3-manager.configs.locked_reason'),
        ];
    }

    /**
     * @param array<mixed, mixed> $data
     */
    public function saveSettings(array $data): void
    {
        // Arma config keys are alphanumeric and never contain commas, so a
        // comma separated list round-trips safely through .env. Written even
        // when empty, since clearing the list is a legitimate change.
        $locked = collect((array) ($data['locked_properties'] ?? []))
            ->map(fn ($key) => trim((string) $key))
            ->filter()
            ->unique()
            ->implode(',');

        $env = [
            'A3M_HEURISTICS' => ! empty($data['heuristics_enabled']) ? 'true' : 'false',
            'A3M_LOCKED_PROPERTIES' => $locked,
            'A3M_LOCKED_REASON' => trim((string) ($data['locked_reason'] ?? '')) ?: 'Set by your plan — contact support to change it.',
        ];

        if (! empty($data['steam_clear_key'])) {
            $env['STEAM_WEB_API_KEY'] = '';
        } elseif (filled($data['steam_api_key'] ?? null)) {
            // Only written when something was actually typed, so re-saving the
            // form for an unrelated reason cannot blank a working key.
            $env['STEAM_WEB_API_KEY'] = trim((string) $data['steam_api_key']);
        }

        $this->writeToEnvironment($env);

        // Note: the panel wraps this whole method in `try { … } catch (Exception) {}`
        // (Plugin::saveSettings), so a throw here would produce no feedback at
        // all — the slide-over would just close. Anything the admin needs to
        // know has to be sent as a notification.
        Notification::make()->title('Arma 3 Manager settings saved')->success()->send();
    }

    public static function make(): static
    {
        return app(static::class);
    }
}
