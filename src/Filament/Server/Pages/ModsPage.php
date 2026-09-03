<?php

namespace FyWolf\Arma3Manager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
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
use FyWolf\Arma3Manager\Services\ModService;
use FyWolf\Arma3Manager\Support\CapabilityResolver;
use FyWolf\Arma3Manager\Support\ModList;
use FyWolf\Arma3Manager\Support\ResolvedProfile;
use Throwable;

/**
 * The `-mod=` load order, and how much of it is actually on disk.
 *
 * ## The order is the feature
 *
 * Arma merges addons in the order given, so a mod that patches another has to
 * come after it — ACE after CBA_A3, always. The table is therefore explicitly
 * *not* sortable by name: offering a sort control on a list whose order is
 * semantic invites a customer to reorder it for readability and break their
 * server. Position is a column, and the only way to change it is the move
 * actions.
 *
 * ## Missing is the number that matters
 *
 * A mod in the load order with no folder on disk is the failure mode with no
 * symptom anyone can read: the server either refuses to start or starts and
 * kicks every client for a missing addon, and the log line names a class rather
 * than a mod. The header banner leads with that count for exactly that reason.
 */
class ModsPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-package';

    protected static string|\UnitEnum|null $navigationGroup = 'Arma 3';

    protected static ?string $slug = 'a3-mods';

    protected static ?int $navigationSort = 21;

    private ?ResolvedProfile $profileMemo = null;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        if (! $server instanceof Server) {
            return false;
        }

        $profile = app(CapabilityResolver::class)->for($server);

        return parent::canAccess()
            && $profile?->has(Capability::Mods)
            && user()?->can(SubuserPermission::FileRead, $server);
    }

    public static function getNavigationLabel(): string
    {
        return trans('arma3-manager::strings.nav.mods');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        abort_unless(user()?->can(SubuserPermission::FileRead, $this->server()), 403);

        // Filament runs mount() before it enforces canAccess(), so this page is
        // reachable by a server whose egg resolves to no profile. Fail as a 403
        // rather than letting a null profile surface as a TypeError later.
        $this->profileMemo = app(CapabilityResolver::class)->for($this->server());

        abort_unless($this->profileMemo?->has(Capability::Mods), 403);
    }

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    private function profile(): ResolvedProfile
    {
        return $this->profileMemo ??= app(CapabilityResolver::class)->for($this->server());
    }

    private function canEdit(): bool
    {
        // Editing the load order rewrites a startup variable, which is a
        // startup permission and not a file permission — the file half only
        // covers the manifest this also writes.
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
                $mods = app(ModService::class);
                $server = $this->server();
                $profile = $this->profile();

                $order = $mods->loadOrder($server, $profile);
                $installed = $mods->installedFolders($server, $profile);
                $serverOrder = $mods->serverLoadOrder($server, $profile);

                $records = [];
                $position = 1;

                foreach ($order->all() as $entry) {
                    $records[$entry] = [
                        'entry' => $entry,
                        'position' => $position++,
                        'folder' => ModList::folder($entry),
                        'scope' => 'Client + server',
                        'present' => $installed->has($entry),
                        'status' => $installed->has($entry) ? 'On disk' : 'Not downloaded',
                        'status_color' => $installed->has($entry) ? 'success' : 'danger',
                    ];
                }

                foreach ($serverOrder->all() as $entry) {
                    // Keyed on a prefix so a mod that is in both lists — which
                    // is legal and occasionally deliberate — renders as two
                    // rows rather than one overwriting the other.
                    $records['server:' . $entry] = [
                        'entry' => $entry,
                        'position' => $position++,
                        'folder' => ModList::folder($entry),
                        'scope' => 'Server only',
                        'present' => $installed->has($entry),
                        'status' => $installed->has($entry) ? 'On disk' : 'Not downloaded',
                        'status_color' => $installed->has($entry) ? 'success' : 'danger',
                    ];
                }

                return $records;
            })
            ->columns([
                TextColumn::make('position')->label('#')->width('4rem'),
                TextColumn::make('folder')->label('Mod')->weight('bold')->description(fn (array $record): string => $record['entry']),
                TextColumn::make('scope')->label('Loaded by')->badge()->color('gray'),
                TextColumn::make('status')
                    ->label('Files')
                    ->badge()
                    ->color(fn (array $record): string => $record['status_color']),
            ])
            // Deliberately not sortable and not searchable: see the class note.
            // Order is meaning here, and a sort control is an invitation to
            // destroy it.
            ->paginated(false)
            ->emptyStateHeading('No mods in the load order')
            ->emptyStateDescription('Add one from the Workshop page, import a launcher preset, or install a mod set.')
            ->recordActions([
                Action::make('up')
                    ->label('Move up')
                    ->icon('tabler-arrow-up')
                    ->iconButton()
                    ->visible(fn (): bool => $this->canEdit())
                    ->action(fn (array $record) => $this->move($record['entry'], -1)),

                Action::make('down')
                    ->label('Move down')
                    ->icon('tabler-arrow-down')
                    ->iconButton()
                    ->visible(fn (): bool => $this->canEdit())
                    ->action(fn (array $record) => $this->move($record['entry'], 1)),

                Action::make('remove')
                    ->label('Remove')
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->iconButton()
                    ->visible(fn (): bool => $this->canEdit())
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => 'Remove ' . $record['folder'] . '?')
                    ->modalDescription('Takes it out of the load order. The files stay on disk until you delete them from the file manager, so this is easy to undo.')
                    ->action(fn (array $record) => $this->remove($record['entry'])),
            ]);
    }

    /**
     * getDefaultHeaderActions, not getHeaderActions.
     *
     * Page carries CanCustomizeHeaderActions, whose getHeaderActions() merges
     * actions other plugins registered via registerCustomHeaderActions() around
     * this method. Overriding getHeaderActions() directly compiles fine and
     * silently discards every one of them.
     *
     * @return array<int, Action>
     */
    protected function getDefaultHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Write mod list')
                ->icon('tabler-refresh')
                ->color('primary')
                ->visible(fn (): bool => $this->canEdit())
                ->requiresConfirmation()
                ->modalHeading('Write the mod list to the server')
                ->modalDescription('Saves the load order to this server\'s startup variable and writes the manifest. Arma reads both only at startup, so restart the server — or reinstall it, if the mods still need downloading.')
                ->action(function (): void {
                    try {
                        $mods = app(ModService::class);
                        $server = $this->server();
                        $profile = $this->profile();

                        $mods->saveLoadOrder($server, $profile, $mods->loadOrder($server, $profile));

                        $missing = $mods->missing($server, $profile);

                        Activity::event('server:arma3.mod-sync')
                            ->property(['missing' => count($missing)])
                            ->log();

                        Notification::make()
                            ->title('Mod list written')
                            ->body($missing === []
                                ? 'Every mod in the load order is already on disk. Restart to apply.'
                                : count($missing) . ' mod(s) still need downloading — reinstall the server so SteamCMD fetches them.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()->title('Could not write the mod list')->body($exception->getMessage())->danger()->send();
                    }
                }),

            Action::make('files')
                ->label('File manager')
                ->icon('tabler-folder-open')
                ->color('gray')
                ->url(fn () => ListFiles::getUrl(['path' => '/' . $this->profile()->modsDir()]), true),
        ];
    }

    private function move(string $entry, int $delta): void
    {
        if (! $this->canEdit()) {
            Notification::make()->title(trans('arma3-manager::strings.permission_denied'))->danger()->send();

            return;
        }

        try {
            $mods = app(ModService::class);
            $server = $this->server();
            $profile = $this->profile();

            $order = $mods->loadOrder($server, $profile);
            $index = $order->indexOf($entry);

            if ($index === null) {
                return;
            }

            $mods->saveLoadOrder($server, $profile, $order->move($entry, $index + $delta));

            Activity::event('server:arma3.mod-reorder')->property(['mod' => $entry])->log();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title('Could not reorder')->body($exception->getMessage())->danger()->send();
        }
    }

    private function remove(string $entry): void
    {
        if (! $this->canEdit()) {
            Notification::make()->title(trans('arma3-manager::strings.permission_denied'))->danger()->send();

            return;
        }

        try {
            $mods = app(ModService::class);
            $server = $this->server();
            $profile = $this->profile();

            $order = $mods->loadOrder($server, $profile);

            if ($order->has($entry)) {
                $mods->saveLoadOrder($server, $profile, $order->remove($entry));
            } else {
                $serverOrder = $mods->serverLoadOrder($server, $profile);
                $mods->saveLoadOrder($server, $profile, $serverOrder->remove($entry), serverOnly: true);
            }

            Activity::event('server:arma3.mod-remove')->property(['mod' => $entry])->log();

            Notification::make()->title('Removed from the load order')->success()->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title('Could not remove')->body($exception->getMessage())->danger()->send();
        }
    }
}
