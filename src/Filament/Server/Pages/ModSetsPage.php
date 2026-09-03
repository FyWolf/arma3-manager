<?php

namespace FyWolf\Arma3Manager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use FyWolf\Arma3Manager\Enums\Capability;
use FyWolf\Arma3Manager\Models\ModSet;
use FyWolf\Arma3Manager\Services\ModSetService;
use FyWolf\Arma3Manager\Support\CapabilityResolver;
use FyWolf\Arma3Manager\Support\ResolvedProfile;
use Throwable;

/**
 * Curated mod sets a customer can install in one action.
 *
 * ## Entitlement is read here and never written here
 *
 * Which sets a server may install comes from the catalogue's public flag plus
 * whatever the billing service granted through this plugin's API. This page has
 * no way to grant anything, and the API has no way to install anything — which
 * is the right split, because installing is a destructive act on a running
 * server and buying is not.
 *
 * ## The progress row is the point
 *
 * An install resolves dependencies against Steam and then hands over to the
 * server's own container for the download, so it is not instant and it is not
 * something the panel can finish by itself. Showing "Waiting for SteamCMD" is
 * the difference between a customer waiting and a customer opening a ticket.
 */
class ModSetsPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-packages';

    protected static string|\UnitEnum|null $navigationGroup = 'Arma 3';

    protected static ?string $slug = 'a3-mod-sets';

    protected static ?int $navigationSort = 27;

    private ?ResolvedProfile $profileMemo = null;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        if (! $server instanceof Server) {
            return false;
        }

        $profile = app(CapabilityResolver::class)->for($server);

        return parent::canAccess()
            && $profile?->has(Capability::ModSets)
            && user()?->can(SubuserPermission::FileRead, $server);
    }

    public static function getNavigationLabel(): string
    {
        return trans('arma3-manager::strings.nav.modsets');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        abort_unless(user()?->can(SubuserPermission::FileRead, $this->server()), 403);

        $this->profileMemo = app(CapabilityResolver::class)->for($this->server());

        abort_unless($this->profileMemo?->has(Capability::ModSets), 403);
    }

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    private function canInstall(): bool
    {
        // Installing rewrites the startup variable and the manifest, so it
        // needs both permissions — the same pair the Mods page requires, since
        // it is the same write by another route.
        return (user()?->can(SubuserPermission::StartupUpdate, $this->server()) ?? false)
            && (user()?->can(SubuserPermission::FileUpdate, $this->server()) ?? false);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                $sets = app(ModSetService::class);
                $server = $this->server();

                $active = $sets->activeInstall($server);

                $records = [];

                foreach ($sets->installable($server) as $set) {
                    $running = $active && $active->mod_set_id === $set->id;

                    $records[$set->key] = [
                        'key' => $set->key,
                        'name' => $set->name,
                        'description' => $set->description,
                        'mods' => count($set->mods ?? []),
                        'state' => $running ? $active->state->getLabel() : 'Available',
                        'state_color' => $running ? $active->state->getColor() : 'gray',
                        'running' => (bool) $running,
                        'busy' => $active !== null,
                    ];
                }

                return $records;
            })
            ->columns([
                TextColumn::make('name')
                    ->label('Mod set')
                    ->weight('bold')
                    ->description(fn (array $record): ?string => $record['description']),

                TextColumn::make('mods')
                    ->label('Mods')
                    ->formatStateUsing(fn (int $state): string => $state . ' item(s)'),

                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->color(fn (array $record): string => $record['state_color']),
            ])
            ->poll('5s')
            ->paginated(false)
            ->emptyStateHeading('No mod sets available')
            ->emptyStateDescription('Your host publishes curated modsets here. Nothing has been made available for this server yet.')
            ->recordActions([
                Action::make('install')
                    ->label('Install')
                    ->icon('tabler-download')
                    // Hidden while anything is running on this server, rather
                    // than shown and refused: the guard in ModSetService is the
                    // real enforcement, and a button that always errors is
                    // worse than no button.
                    ->visible(fn (array $record): bool => $this->canInstall() && ! $record['busy'])
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => 'Install ' . $record['name'] . '?')
                    ->modalDescription('Adds every mod in the set to the load order, dependencies first and in the right order. The files are not downloaded by the panel — reinstall the server afterwards so SteamCMD fetches them with your own Steam account. Nothing already in your load order is removed.')
                    ->action(fn (array $record) => $this->install((string) $record['key'])),
            ]);
    }

    private function install(string $key): void
    {
        if (! $this->canInstall()) {
            Notification::make()->title(trans('arma3-manager::strings.permission_denied'))->danger()->send();

            return;
        }

        $server = $this->server();
        $set = ModSet::query()->installableBy($server)->where('key', $key)->first();

        if (! $set) {
            Notification::make()->title('That mod set is not available for this server')->danger()->send();

            return;
        }

        try {
            app(ModSetService::class)->start($server, $set, user());

            Notification::make()
                ->title('Install queued')
                ->body('Resolving dependencies now. This page updates by itself.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            // A refusal here is usually the one-install-at-a-time guard, which
            // is a sentence the customer can act on rather than an error.
            Notification::make()->title('Could not start the install')->body($exception->getMessage())->warning()->send();
        }
    }
}
