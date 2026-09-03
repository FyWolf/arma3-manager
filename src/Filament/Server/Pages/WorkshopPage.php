<?php

namespace FyWolf\Arma3Manager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use FyWolf\Arma3Manager\Enums\Capability;
use FyWolf\Arma3Manager\Integrations\Workshop\SteamWorkshopClient;
use FyWolf\Arma3Manager\Services\ModService;
use FyWolf\Arma3Manager\Support\CapabilityResolver;
use FyWolf\Arma3Manager\Support\ResolvedProfile;
use FyWolf\Arma3Manager\Support\WorkshopId;
use Throwable;
use Livewire\Attributes\Url;

/**
 * Find Workshop mods and put them in the load order.
 *
 * ## Two ways in, and one of them always works
 *
 * Searching the Workshop needs a Steam Web API key. Resolving a *specific* item
 * does not — the unauthenticated `GetPublishedFileDetails` endpoint returns
 * everything needed, including the dependency graph. So without a key the
 * search box is not rendered and the paste box is, and the page says which it
 * is rather than looking broken.
 *
 * ## Nothing here downloads anything
 *
 * Arma 3 Workshop items cannot be fetched by an anonymous SteamCMD login, and
 * this panel deliberately holds no Steam credentials. Adding a mod writes it
 * into the load order and the manifest; the customer's own container fetches it
 * on the next reinstall, using the Steam account already on its egg. The
 * confirmation says so, because "Added" without that sentence reads as "the
 * files are here now" and they are not.
 */
class WorkshopPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-brand-steam';

    protected static string|\UnitEnum|null $navigationGroup = 'Arma 3';

    protected static ?string $slug = 'a3-workshop';

    protected static ?int $navigationSort = 22;

    #[Url]
    public string $search = '';

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
        return trans('arma3-manager::strings.nav.workshop');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        abort_unless(user()?->can(SubuserPermission::FileRead, $this->server()), 403);

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
                $client = app(SteamWorkshopClient::class);

                $term = trim($this->search);

                if ($term === '') {
                    return [];
                }

                // A pasted id or URL resolves without a key. Checked first, so
                // pasting a link works identically with and without one.
                $ids = WorkshopId::extractAll($term);

                $items = $ids !== []
                    ? array_values($client->items($ids))
                    : $client->search($term);

                $order = app(ModService::class)->loadOrder($this->server(), $this->profile());

                $records = [];

                foreach ($items as $item) {
                    $records[$item->id] = [
                        'id' => $item->id,
                        'title' => $item->title,
                        'preview' => $item->previewUrl,
                        'size' => $item->sizeForHumans(),
                        'requires' => count($item->children),
                        'installable' => $item->isInstallable(),
                        'in_order' => $order->has('@' . $item->title),
                        'url' => WorkshopId::url($item->id),
                    ];
                }

                return $records;
            })
            ->columns([
                ImageColumn::make('preview')->label('')->height(48)->width(48),
                TextColumn::make('title')
                    ->label('Mod')
                    ->weight('bold')
                    ->description(fn (array $record): string => 'Workshop id ' . $record['id']),
                TextColumn::make('size')->label('Size'),
                TextColumn::make('requires')
                    ->label('Requires')
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? '—' : $state . ' item(s)')
                    ->tooltip('Required items are added automatically, and before this one, because Arma loads mods in order.'),
            ])
            ->paginated(false)
            ->emptyStateHeading(fn (): string => trim($this->search) === ''
                ? 'Search, or paste a Workshop link'
                : 'Nothing found')
            ->emptyStateDescription(fn (): string => app(SteamWorkshopClient::class)->canSearch()
                ? 'Search by name, or paste one or more Workshop links or ids.'
                : trans('arma3-manager::strings.steam.no_key'))
            ->recordActions([
                Action::make('add')
                    ->label('Add')
                    ->icon('tabler-plus')
                    ->visible(fn (array $record): bool => $this->canEdit() && $record['installable'])
                    ->schema([
                        Toggle::make('with_dependencies')
                            ->label('Add required items too')
                            ->helperText('Adds anything this mod declares as required, ahead of it in the load order. Almost always what you want — ACE will not load without CBA_A3.')
                            ->default(true),
                    ])
                    ->action(fn (array $record, array $data) => $this->add([$record['id']], (bool) ($data['with_dependencies'] ?? true))),

                Action::make('view')
                    ->label('View on Steam')
                    ->icon('tabler-external-link')
                    ->color('gray')
                    ->iconButton()
                    ->url(fn (array $record): string => $record['url'], true),
            ]);
    }

    /**
     * getHeaderActions, not getDefaultHeaderActions — see the note on
     * `ModsPage`. This page extends Filament's `Page`, which has no
     * `getDefaultHeaderActions()`, so the wrong name is never called and the
     * header renders empty.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('find')
                ->label(fn (): string => app(SteamWorkshopClient::class)->canSearch() ? 'Search' : 'Paste a link or id')
                ->icon('tabler-search')
                ->color('primary')
                ->schema([
                    Textarea::make('query')
                        ->label(fn (): string => app(SteamWorkshopClient::class)->canSearch()
                            ? 'Search term, or one or more Workshop links'
                            : 'Workshop links or ids, one per line')
                        ->rows(3)
                        ->required()
                        ->helperText(fn (): ?string => app(SteamWorkshopClient::class)->canSearch()
                            ? null
                            : trans('arma3-manager::strings.steam.no_key')),
                ])
                ->action(function (array $data): void {
                    $this->search = trim((string) ($data['query'] ?? ''));
                    $this->resetTable();
                }),

            Action::make('bulk')
                ->label('Add several at once')
                ->icon('tabler-list-details')
                ->color('gray')
                ->visible(fn (): bool => $this->canEdit())
                ->schema([
                    Textarea::make('ids')
                        ->label('Workshop links or ids')
                        ->rows(6)
                        ->required()
                        ->helperText('One per line. A whole collection pasted out of a browser works too — anything that is not an id is ignored.'),
                    Toggle::make('with_dependencies')
                        ->label('Add required items too')
                        ->default(true),
                ])
                ->action(function (array $data): void {
                    $ids = WorkshopId::extractAll((string) ($data['ids'] ?? ''));

                    if ($ids === []) {
                        Notification::make()->title('No Workshop ids found in that')->warning()->send();

                        return;
                    }

                    $this->add($ids, (bool) ($data['with_dependencies'] ?? true));
                }),
        ];
    }

    /**
     * @param array<int, string> $ids
     */
    private function add(array $ids, bool $withDependencies): void
    {
        if (! $this->canEdit()) {
            Notification::make()->title(trans('arma3-manager::strings.permission_denied'))->danger()->send();

            return;
        }

        try {
            $client = app(SteamWorkshopClient::class);
            $mods = app(ModService::class);
            $server = $this->server();
            $profile = $this->profile();

            // Dependencies first, in load order. resolveDependencies() returns
            // the deepest layer first for exactly this reason: CBA_A3 has to be
            // in the list before ACE, not merely present in it.
            $resolved = $withDependencies ? $client->resolveDependencies($ids) : $ids;

            $items = $client->items($resolved);

            if ($items === []) {
                Notification::make()->title(trans('arma3-manager::strings.steam.not_found'))->danger()->send();

                return;
            }

            $order = $mods->loadOrder($server, $profile);
            $added = 0;
            $skipped = [];

            foreach ($items as $item) {
                if (! $item->isInstallable()) {
                    $skipped[] = $item->title;

                    continue;
                }

                // The folder name is derived from the title because that is all
                // the API offers, and it is the convention every Arma mod
                // follows. It is a guess, and the Mods page shows plainly
                // whether the folder turned up on disk — which is the check
                // that catches a wrong guess.
                $folder = '@' . preg_replace('/[^A-Za-z0-9_\-]+/', '_', trim($item->title));

                if (! $order->has($folder)) {
                    $order->add($folder);
                    $added++;
                }
            }

            $mods->saveLoadOrder($server, $profile, $order);

            Activity::event('server:arma3.mod-add')
                ->property(['count' => $added, 'ids' => array_keys($items)])
                ->log();

            Notification::make()
                ->title($added === 0 ? 'Already in the load order' : $added . ' mod(s) added')
                ->body('The files are not downloaded yet — reinstall the server so SteamCMD fetches them with your Steam account.'
                    . ($skipped === [] ? '' : ' Skipped ' . count($skipped) . ' item(s) removed from the Workshop.'))
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title('Could not add to the load order')->body($exception->getMessage())->danger()->send();
        }
    }
}
