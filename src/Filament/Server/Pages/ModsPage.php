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
use FyWolf\Arma3Manager\Integrations\Workshop\SteamWorkshopClient;
use FyWolf\Arma3Manager\Services\ModService;
use FyWolf\Arma3Manager\Support\CapabilityResolver;
use FyWolf\Arma3Manager\Support\ModList;
use FyWolf\Arma3Manager\Support\ResolvedProfile;
use FyWolf\Arma3Manager\Support\WorkshopId;
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

    /**
     * What is on disk, read once per request.
     *
     * SteamCMD stages an item in `steamapps/workshop/downloads/<app>/<id>` while
     * it transfers and **moves** it to `content/<app>/<id>` when it completes,
     * so these two listings are the whole progress display. The table body and
     * the summary line both want them, and each is a round trip to the daemon.
     *
     * @var array{downloaded: array<int, string>, downloading: array<int, string>}|null
     */
    private ?array $diskMemo = null;

    /**
     * @return array{downloaded: array<int, string>, downloading: array<int, string>}
     */
    private function disk(): array
    {
        if ($this->diskMemo !== null) {
            return $this->diskMemo;
        }

        $mods = app(ModService::class);

        return $this->diskMemo = [
            'downloaded' => $mods->downloadedIds($this->server(), $this->profile()),
            'downloading' => $mods->downloadingIds($this->server(), $this->profile()),
        ];
    }

    /**
     * The line above the table: how far the download has got.
     *
     * Null when there is nothing in the load order, so an empty server gets its
     * empty state rather than "0 of 0 downloaded".
     *
     * `loadOrder()` is read again here rather than passed down — it is a server
     * variable, not a daemon call, so it costs nothing next to the listings the
     * memo above is protecting.
     */
    private function downloadSummary(): ?string
    {
        $order = app(ModService::class)->loadOrder($this->server(), $this->profile())->all();

        if ($order === []) {
            return null;
        }

        ['downloaded' => $downloaded, 'downloading' => $downloading] = $this->disk();

        $done = count(array_intersect($order, $downloaded));
        $active = count(array_intersect($order, $downloading));
        $waiting = max(0, count($order) - $done - $active);

        if ($done === count($order)) {
            return 'All ' . $done . ' mod(s) downloaded. Restart the server to load them.';
        }

        $parts = [$done . ' of ' . count($order) . ' downloaded'];

        if ($active > 0) {
            $parts[] = $active . ' downloading';
        }

        if ($waiting > 0) {
            $parts[] = $waiting . ' waiting';
        }

        return implode(' · ', $parts) . '. This page updates itself.';
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
                $serverOrder = $mods->serverLoadOrder($server, $profile);

                // Memoised, because the table body and the summary line above
                // it both need the same answer and each lookup is a round trip
                // to the daemon. Without this a five-second poll on a ninety-mod
                // list is four directory listings a tick instead of two.
                ['downloaded' => $downloaded, 'downloading' => $downloading] = $this->disk();

                // One batched lookup for every id on the page, so the table
                // shows "ACE3" rather than a column of numbers. Cached, and a
                // Steam outage degrades to showing the id — which is still the
                // thing the customer can paste into a workshop URL.
                $titles = app(SteamWorkshopClient::class)->items([
                    ...$order->all(),
                    ...$serverOrder->all(),
                ]);

                $records = [];
                $position = 1;

                $build = function (string $entry, string $scope, int $position) use ($downloaded, $downloading, $titles): array {
                    $state = match (true) {
                        in_array($entry, $downloaded, true) => 'downloaded',
                        in_array($entry, $downloading, true) => 'downloading',
                        default => 'waiting',
                    };

                    return [
                        'entry' => $entry,
                        'position' => $position,
                        'name' => $titles[$entry]->title ?? $entry,
                        'id' => $entry,
                        'scope' => $scope,
                        'state' => $state,
                        'present' => $state === 'downloaded',
                        'status' => match ($state) {
                            'downloaded' => 'Downloaded',
                            'downloading' => 'Downloading',
                            default => 'Waiting',
                        },
                        'status_color' => match ($state) {
                            'downloaded' => 'success',
                            'downloading' => 'info',
                            default => 'gray',
                        },
                        'status_icon' => match ($state) {
                            'downloaded' => 'tabler-circle-check',
                            'downloading' => 'tabler-progress',
                            default => 'tabler-clock',
                        },
                        'url' => WorkshopId::isValid($entry) ? WorkshopId::url($entry) : null,
                    ];
                };

                foreach ($order->all() as $entry) {
                    $records[$entry] = $build($entry, 'Client + server', $position++);
                }

                foreach ($serverOrder->all() as $entry) {
                    // Keyed on a prefix so a mod that is in both lists — which
                    // is legal and occasionally deliberate — renders as two
                    // rows rather than one overwriting the other.
                    $records['server:' . $entry] = $build($entry, 'Server only', $position++);
                }

                return $records;
            })
            ->columns([
                TextColumn::make('position')->label('#')->width('4rem'),
                TextColumn::make('name')
                    ->label('Mod')
                    ->weight('bold')
                    ->description(fn (array $record): string => 'Workshop id ' . $record['id']),
                TextColumn::make('scope')->label('Loaded by')->badge()->color('gray'),
                TextColumn::make('status')
                    ->label('Download')
                    ->badge()
                    ->icon(fn (array $record): string => $record['status_icon'])
                    ->color(fn (array $record): string => $record['status_color'])
                    ->tooltip(fn (array $record): string => match ($record['state']) {
                        'downloaded' => 'On disk, in steamapps/workshop/content.',
                        'downloading' => 'SteamCMD is transferring this now. There is no percentage available — its output does not reach the panel — so a large mod sits here for a while and then completes.',
                        default => 'Queued. SteamCMD fetches items one at a time, in order.',
                    }),
            ])
            // Deliberately not sortable and not searchable: see the class note.
            // Order is meaning here, and a sort control is an invitation to
            // destroy it.
            ->paginated(false)
            // The download runs on the server, not here, so the only way to
            // show it moving is to keep asking. Five seconds is two directory
            // listings per tick against a daemon that is already serving the
            // file manager; anything faster buys nothing, because SteamCMD
            // moves an item into place in one step.
            ->poll('5s')
            ->description(fn (): ?string => $this->downloadSummary())
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

                Action::make('view')
                    ->label('View on Steam')
                    ->icon('tabler-external-link')
                    ->color('gray')
                    ->iconButton()
                    ->visible(fn (array $record): bool => $record['url'] !== null)
                    ->url(fn (array $record): string => $record['url'], true),

                Action::make('remove')
                    ->label('Remove')
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->iconButton()
                    ->visible(fn (): bool => $this->canEdit())
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => 'Remove ' . $record['name'] . '?')
                    ->modalDescription('Takes it out of the load order. The files stay on disk until you delete them from the file manager, so this is easy to undo.')
                    ->action(fn (array $record) => $this->remove($record['entry'])),
            ]);
    }

    /**
     * getHeaderActions, not getDefaultHeaderActions — and which one is correct
     * depends entirely on the base class.
     *
     * `getDefaultHeaderActions()` exists only on the panel's
     * `CanCustomizeHeaderActions` trait, which `ServerFormPage` carries (see
     * `ConfigsPage`). A page extending Filament's own `Page` gets
     * `getHeaderActions()` from `InteractsWithHeaderActions` and has no
     * `getDefaultHeaderActions()` at all — so defining one here compiles
     * cleanly, is never called, and the page renders with no header buttons.
     *
     * That is the failure this comment exists to prevent, and it is invisible
     * to `php -l`, to the import check and to a reviewer who has just read the
     * ServerFormPage version. `tests/verify-page-hooks.php` fails the build on
     * it.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Write mod list')
                ->icon('tabler-refresh')
                ->color('primary')
                ->visible(fn (): bool => $this->canEdit())
                ->requiresConfirmation()
                ->modalHeading('Write the mod list to the server')
                ->modalDescription('Saves the load order to this server\'s startup variable and writes the manifest. The egg fetches anything new the next time the server starts, and this page shows it arriving.')
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
                                : count($missing) . ' mod(s) still need downloading. Start the server and they will be fetched — this page shows the progress.')
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
