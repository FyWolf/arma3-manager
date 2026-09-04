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

        // Only the Workshop entries have a download to count. Including a CDLC
        // or a hand-uploaded folder would make the total unreachable.
        $ids = array_values(array_filter(array_map(WorkshopId::fromModEntry(...), $order)));

        if ($ids === []) {
            return null;
        }

        ['downloaded' => $downloaded, 'downloading' => $downloading] = $this->disk();

        $done = count(array_intersect($ids, $downloaded));
        $active = count(array_intersect($ids, $downloading));
        $waiting = max(0, count($ids) - $done - $active);

        if ($done === count($ids)) {
            return 'All ' . $done . ' Workshop mod(s) downloaded. Restart the server to load them.';
        }

        $parts = [$done . ' of ' . count($ids) . ' downloaded'];

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
                $titles = app(SteamWorkshopClient::class)->items(array_filter(array_map(
                    WorkshopId::fromModEntry(...),
                    [...$order->all(), ...$serverOrder->all()],
                )));

                $records = [];
                $position = 1;

                // The egg's own account of the download, when its egg writes one.
                // Null on the stock Arma 3 egg, and every use of it below is
                // written to degrade to what the directory listings said.
                $status = $mods->status($server);

                $build = function (string $entry, string $scope, bool $serverOnly, int $position) use ($downloaded, $downloading, $titles, $status): array {
                    // The list is deliberately mixed — `myMod;vn;@123456789;` —
                    // so an entry is a Workshop item only when it is `@` plus
                    // digits. A CDLC code or a hand-uploaded folder has no
                    // download to report and must not be shown as "Waiting"
                    // forever, because nothing will ever fetch it.
                    $id = WorkshopId::fromModEntry($entry);

                    // `failed` is checked first and comes only from the egg. It
                    // is the state a directory listing can never produce: a mod
                    // SteamCMD gave up on leaves nothing behind, so on disk it is
                    // identical to one that has not been reached yet. Ranking it
                    // below "waiting" would bury the only row worth acting on.
                    $state = match (true) {
                        $id === null => 'local',
                        $id !== null && $status?->state($id) === 'failed' => 'failed',
                        in_array($id, $downloaded, true) => 'downloaded',
                        in_array($id, $downloading, true) => 'downloading',
                        default => 'waiting',
                    };

                    $percent = $id !== null && $state === 'downloading' ? $status?->percent($id) : null;

                    return [
                        'entry' => $entry,
                        'position' => $position,
                        // Steam first, then the name the egg scraped while
                        // downloading, then the raw entry. The middle one is what
                        // keeps the table readable during a Steam outage.
                        'name' => $id !== null
                            ? ($titles[$id]->title ?? $status?->name($id) ?? $entry)
                            : $entry,
                        'id' => $id,
                        'percent' => $percent,
                        'error' => $id !== null ? $status?->error($id) : null,
                        // The egg's field refuses capitals, spaces and folders
                        // starting with a number. Anything this plugin writes
                        // complies, but a hand-edited entry might not, and the
                        // failure is the whole list being rejected rather than
                        // that one entry.
                        'invalid' => preg_match('/^[a-z0-9_@.\-]+$/', $entry) !== 1
                            || preg_match('/^\d/', $entry) === 1,
                        'scope' => $scope,
                        // Which of the two lists this row came from, carried
                        // explicitly rather than re-derived from the entry.
                        // A mod may legally be in both, so "look it up again"
                        // finds the wrong one and acts on the wrong row.
                        'server_only' => $serverOnly,
                        'state' => $state,
                        'present' => $state === 'downloaded',
                        'status' => match ($state) {
                            'downloaded' => 'Downloaded',
                            // The percentage is only ever shown when the egg
                            // measured one. "Downloading" with no number is the
                            // honest display on the stock egg, and better than a
                            // 0% that reads as "started and got nowhere".
                            'downloading' => $percent !== null ? 'Downloading ' . $percent . '%' : 'Downloading',
                            'failed' => 'Failed',
                            'local' => 'Not from the Workshop',
                            default => 'Waiting',
                        },
                        'status_color' => match ($state) {
                            'downloaded' => 'success',
                            'downloading' => 'info',
                            'failed' => 'danger',
                            'local' => 'warning',
                            default => 'gray',
                        },
                        'status_icon' => match ($state) {
                            'downloaded' => 'tabler-circle-check',
                            'downloading' => 'tabler-progress',
                            'failed' => 'tabler-alert-circle',
                            'local' => 'tabler-folder',
                            default => 'tabler-clock',
                        },
                        'url' => $id !== null ? WorkshopId::url($id) : null,
                    ];
                };

                foreach ($order->all() as $entry) {
                    $records[$entry] = $build($entry, 'Client + server', false, $position++);
                }

                foreach ($serverOrder->all() as $entry) {
                    // Keyed on a prefix so a mod that is in both lists — which
                    // is legal and occasionally deliberate — renders as two
                    // rows rather than one overwriting the other.
                    $records['server:' . $entry] = $build($entry, 'Server only', true, $position++);
                }

                return $records;
            })
            ->columns([
                TextColumn::make('position')->label('#')->width('4rem'),
                TextColumn::make('name')
                    ->label('Mod')
                    ->weight('bold')
                    ->description(fn (array $record): string => $record['entry'])
                    ->icon(fn (array $record): ?string => $record['invalid'] ? 'tabler-alert-triangle' : null)
                    ->iconColor('danger')
                    ->tooltip(fn (array $record): ?string => $record['invalid']
                        ? 'This entry breaks the field\'s rules — no capital letters, no spaces, and it may not start with a number. The egg may reject the whole list.'
                        : null),
                TextColumn::make('scope')->label('Loaded by')->badge()->color('gray'),
                TextColumn::make('status')
                    ->label('Download')
                    ->badge()
                    ->icon(fn (array $record): string => $record['status_icon'])
                    ->color(fn (array $record): string => $record['status_color'])
                    // A failure's reason comes from the egg and is the most
                    // useful string on the page, so it wins over the generic
                    // explanation for its state.
                    ->tooltip(fn (array $record): string => $record['error'] ?? match ($record['state']) {
                        'downloaded' => 'On disk — either in the SteamCMD cache under Steam/steamapps/workshop/content, or as the @<id> folder the server loads.',
                        'downloading' => $record['percent'] !== null
                            ? 'SteamCMD is transferring this now. The percentage is the size on disk against the size Steam reported, so it moves in steps rather than smoothly.'
                            : 'SteamCMD is transferring this now. No percentage is available on this egg, so a large mod sits here for a while and then completes.',
                        'failed' => 'SteamCMD gave up on this mod. Starting the server again retries it.',
                        'local' => 'Not an @workshopID entry, so nothing downloads it. A Creator DLC ships with the game; a plain folder name has to be uploaded yourself.',
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
                    ->action(fn (array $record) => $this->move($record['entry'], -1, $record['server_only'])),

                Action::make('down')
                    ->label('Move down')
                    ->icon('tabler-arrow-down')
                    ->iconButton()
                    ->visible(fn (): bool => $this->canEdit())
                    ->action(fn (array $record) => $this->move($record['entry'], 1, $record['server_only'])),

                Action::make('scope')
                    ->label(fn (array $record): string => $record['server_only']
                        ? 'Load on clients too'
                        : 'Make server-only')
                    ->icon(fn (array $record): string => $record['server_only']
                        ? 'tabler-users'
                        : 'tabler-server')
                    ->color('gray')
                    ->iconButton()
                    ->visible(fn (): bool => $this->canEdit() && $this->hasServerModList())
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => $record['server_only']
                        ? 'Load ' . $record['name'] . ' on clients too?'
                        : 'Make ' . $record['name'] . ' server-only?')
                    // The two directions are not mirror images, and the
                    // dangerous one is worth spelling out. `-serverMod=` mods
                    // are not required of clients, so moving a *content* mod
                    // there is how you get every player kicked for a missing
                    // addon — the server loads it, nobody else does.
                    ->modalDescription(fn (array $record): string => $record['server_only']
                        ? 'Moves it to the main mod list, so clients must load it as well. This is what a content mod — a map, a weapons pack, ACE — needs in order to work.'
                        : 'Moves it to -serverMod=, which the server loads and clients do not. Correct for admin tools and server-side scripts. Wrong for anything that adds content: clients will not have it, and with verifySignatures on they will be kicked for a missing addon.')
                    ->modalSubmitActionLabel(fn (array $record): string => $record['server_only']
                        ? 'Load on clients too'
                        : 'Make server-only')
                    ->action(fn (array $record) => $this->setScope($record['entry'], ! $record['server_only'])),

                Action::make('view')
                    ->label('View on Steam')
                    ->icon('tabler-external-link')
                    ->color('gray')
                    ->iconButton()
                    ->visible(fn (array $record): bool => $record['url'] !== null)
                    ->url(fn (array $record): string => $record['url'], true),

                Action::make('reinstall')
                    ->label('Reinstall')
                    ->icon('tabler-refresh')
                    ->color('warning')
                    ->iconButton()
                    // Only a Workshop item has anything to re-download. A CDLC
                    // ships with the game and a hand-uploaded folder has no
                    // source to fetch it from again.
                    ->visible(fn (array $record): bool => $record['id'] !== null && $this->canReinstall())
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => 'Reinstall ' . $record['name'] . '?')
                    ->modalDescription('Deletes this mod\'s files and clears it from SteamCMD\'s installed record, so the next server start fetches it again from scratch. Use it when a mod is corrupt or stuck on an old version — clearing the record is what makes SteamCMD actually re-download rather than deciding it already has the mod. Nothing else is touched, and the mod stays in the load order.')
                    ->modalSubmitActionLabel('Delete and re-download')
                    ->action(fn (array $record) => $this->reinstall($record['id'], $record['name'])),

                Action::make('remove')
                    ->label('Remove')
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->iconButton()
                    ->visible(fn (): bool => $this->canEdit())
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => 'Remove ' . $record['name'] . '?')
                    ->modalDescription('Takes it out of the load order. The files stay on disk until you delete them from the file manager, so this is easy to undo.')
                    ->action(fn (array $record) => $this->remove($record['entry'], $record['server_only'])),
            ]);
    }

    /**
     * Whether this server's egg has a server-only mod list at all.
     *
     * A profile can legitimately have none — a headless client joins a mission
     * and hosts nothing, so `-serverMod=` is meaningless there and the egg does
     * not declare a variable for it. Offering the switch anyway would produce an
     * action that can only ever fail, with an error naming a variable the
     * customer cannot add.
     */
    private function hasServerModList(): bool
    {
        return app(ModService::class)->serverModVariables($this->profile()) !== [];
    }

    /**
     * Reinstalling deletes files and rewrites SteamCMD's record.
     *
     * File permissions, not `startup.update`: this changes nothing about the
     * load order, only what is on disk. A subuser trusted to delete files is
     * exactly the person who should be able to clear a corrupt mod.
     */
    private function canReinstall(): bool
    {
        return (user()?->can(SubuserPermission::FileDelete, $this->server()) ?? false)
            && (user()?->can(SubuserPermission::FileUpdate, $this->server()) ?? false);
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

            Action::make('download')
                ->label('Download now')
                ->icon('tabler-cloud-download')
                ->color('success')
                // Only on an egg that carries the sync daemon. On the stock egg
                // there is nothing watching for the request, so the button would
                // write a file into a directory nobody reads and report success.
                ->visible(fn (): bool => $this->canEdit()
                    && app(ModService::class)->supportsBackgroundSync($this->server()))
                ->requiresConfirmation()
                ->modalHeading('Download the missing mods now')
                ->modalDescription('Fetches everything in the load order that is not already on disk, in the background, while the server keeps running. Players are not disconnected and nothing restarts.

The mods are *loaded* at the next restart — Arma reads its mod list once, at startup — but that restart is then instant instead of waiting for the download.

If the server is stopped, this is picked up the next time it starts.')
                ->modalSubmitActionLabel('Start downloading')
                ->action(function (): void {
                    try {
                        $mods = app(ModService::class);
                        $server = $this->server();
                        $profile = $this->profile();

                        $requested = $mods->requestSync($server, $profile);

                        if ($requested === []) {
                            Notification::make()
                                ->title('Nothing to download')
                                ->body('Every Workshop mod in the load order is already on disk.')
                                ->success()
                                ->send();

                            return;
                        }

                        Activity::event('server:arma3.mod-download')
                            ->property(['mods' => $requested])
                            ->log();

                        Notification::make()
                            ->title(count($requested) . ' mod(s) queued for download')
                            ->body('The server picks this up within a few seconds and downloads in the background. This page shows each one arriving; you do not need to stay on it.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Could not ask for a download')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('files')
                ->label('File manager')
                ->icon('tabler-folder-open')
                ->color('gray')
                ->url(fn () => ListFiles::getUrl(['path' => '/' . $this->profile()->modsDir()]), true),
        ];
    }

    /**
     * Reorder within whichever list the row came from.
     *
     * `$serverOnly` is passed in rather than looked up. Reordering used to read
     * the client list unconditionally, so the arrows on a server-only row found
     * no index and returned — a button that did nothing, said nothing, and left
     * the customer clicking it.
     */
    private function move(string $entry, int $delta, bool $serverOnly = false): void
    {
        if (! $this->canEdit()) {
            Notification::make()->title(trans('arma3-manager::strings.permission_denied'))->danger()->send();

            return;
        }

        try {
            $mods = app(ModService::class);
            $server = $this->server();
            $profile = $this->profile();

            $order = $serverOnly
                ? $mods->serverLoadOrder($server, $profile)
                : $mods->loadOrder($server, $profile);

            $index = $order->indexOf($entry);

            if ($index === null) {
                return;
            }

            $mods->saveLoadOrder($server, $profile, $order->move($entry, $index + $delta), serverOnly: $serverOnly);

            Activity::event('server:arma3.mod-reorder')->property(['mod' => $entry])->log();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title('Could not reorder')->body($exception->getMessage())->danger()->send();
        }
    }

    /**
     * Remove from whichever list the row came from.
     *
     * Also passed in rather than searched for. The old "is it in the client
     * list? then remove it from there" test is wrong for a mod that is in
     * **both**, which the table deliberately renders as two rows: pressing
     * Remove on the server-only row removed the client entry instead, and the
     * row the customer clicked stayed exactly where it was.
     */
    private function remove(string $entry, bool $serverOnly = false): void
    {
        if (! $this->canEdit()) {
            Notification::make()->title(trans('arma3-manager::strings.permission_denied'))->danger()->send();

            return;
        }

        try {
            $mods = app(ModService::class);
            $server = $this->server();
            $profile = $this->profile();

            $order = $serverOnly
                ? $mods->serverLoadOrder($server, $profile)
                : $mods->loadOrder($server, $profile);

            $mods->saveLoadOrder($server, $profile, $order->remove($entry), serverOnly: $serverOnly);

            Activity::event('server:arma3.mod-remove')->property(['mod' => $entry])->log();

            Notification::make()->title('Removed from the load order')->success()->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title('Could not remove')->body($exception->getMessage())->danger()->send();
        }
    }

    /**
     * Delete a mod's files and SteamCMD's record of it, so it downloads again.
     *
     * The ACF outcome is reported rather than swallowed, and that is the point
     * of the whole notification. If the record could not be cleared, SteamCMD
     * will look at its own manifest on the next start, decide the mod is already
     * installed, and transfer nothing — so the mod stays missing and the button
     * will have appeared to work. A customer who is told that can go and delete
     * the file by hand; a customer who is not told will press Reinstall three
     * more times and open a ticket.
     */
    private function reinstall(string $id, string $name): void
    {
        if (! $this->canReinstall()) {
            Notification::make()->title(trans('arma3-manager::strings.permission_denied'))->danger()->send();

            return;
        }

        try {
            $result = app(ModService::class)->reinstall($this->server(), $this->profile(), $id);

            Activity::event('server:arma3.mod-reinstall')
                ->property(['mod' => $id, 'removed' => $result['removed'], 'acf' => $result['acf']])
                ->log();

            if (in_array($result['acf'], ['refused', 'failed'], true)) {
                Notification::make()
                    ->title('Files deleted, but SteamCMD\'s record was left alone')
                    ->body('The files for ' . $name . ' are gone, but its entry in appworkshop_' . (int) config('arma3-manager.workshop.app_id', 107410) . '.acf could not be edited safely, so SteamCMD may still think it has this mod and skip the download. Delete that file in the file manager to force a full re-check of every mod.')
                    ->warning()
                    ->persistent()
                    ->send();

                return;
            }

            if ($result['removed'] === [] && $result['acf'] !== 'updated') {
                Notification::make()
                    ->title('Nothing to delete')
                    ->body($name . ' has no files on this server, so it will be downloaded on the next start anyway.')
                    ->warning()
                    ->send();

                return;
            }

            Notification::make()
                ->title('Ready to re-download')
                ->body(count($result['removed']) . ' item(s) deleted' . ($result['acf'] === 'updated' ? ', and SteamCMD\'s record cleared' : '') . '. ' . $name . ' is fetched again the next time the server starts.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title('Could not reinstall')->body($exception->getMessage())->danger()->send();
        }
    }

    /**
     * Move one mod between the client list and the server-only list.
     *
     * ## Add first, remove second
     *
     * These are two separate startup variables, so this is two writes and there
     * is no transaction across them. The order is therefore chosen for how it
     * fails, not for tidiness:
     *
     *   add-then-remove  — a failure leaves the mod in **both** lists
     *   remove-then-add  — a failure leaves the mod in **neither**
     *
     * Being in both is legal, visible on this page as two rows, and fixed by
     * pressing the button again. Being in neither is a mod that silently
     * vanished from a load order the customer thought they were editing, and the
     * first symptom is a server that will not start or that kicks everyone.
     *
     * So the write that can lose data goes last, and if it throws, the state
     * left behind is the recoverable one.
     */
    private function setScope(string $entry, bool $toServerOnly): void
    {
        if (! $this->canEdit()) {
            Notification::make()->title(trans('arma3-manager::strings.permission_denied'))->danger()->send();

            return;
        }

        try {
            $mods = app(ModService::class);
            $server = $this->server();
            $profile = $this->profile();

            if ($mods->serverModVariables($profile) === []) {
                Notification::make()
                    ->title('This egg has no server-only mod list')
                    ->body('The profile declares no -serverMod= variable, so there is nowhere to move it to.')
                    ->danger()
                    ->send();

                return;
            }

            $source = $toServerOnly ? $mods->loadOrder($server, $profile) : $mods->serverLoadOrder($server, $profile);
            $target = $toServerOnly ? $mods->serverLoadOrder($server, $profile) : $mods->loadOrder($server, $profile);

            if (! $source->has($entry)) {
                // Someone else moved it, or the page is showing a stale render.
                Notification::make()->title('That mod is no longer in the list it was in')->warning()->send();

                return;
            }

            // `add()` is already a no-op when the entry is present, so a mod
            // that was deliberately in both lists keeps its position rather than
            // being appended a second time.
            $mods->saveLoadOrder($server, $profile, $target->add($entry), serverOnly: $toServerOnly);
            $mods->saveLoadOrder($server, $profile, $source->remove($entry), serverOnly: ! $toServerOnly);

            Activity::event('server:arma3.mod-scope')
                ->property(['mod' => $entry, 'server_only' => $toServerOnly])
                ->log();

            Notification::make()
                ->title($toServerOnly ? 'Now loaded server-side only' : 'Now loaded by clients too')
                ->body('Arma reads its mod list at startup, so this takes effect the next time the server starts.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title('Could not change how this mod loads')->body($exception->getMessage())->danger()->send();
        }
    }
}
