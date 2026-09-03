<?php

namespace FyWolf\Arma3Manager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
use FyWolf\Arma3Manager\Support\LauncherPreset;
use FyWolf\Arma3Manager\Support\ModList;
use FyWolf\Arma3Manager\Support\ResolvedProfile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Arma 3 Launcher presets, in and out.
 *
 * This is the feature that makes a forty-mod server setup take a minute instead
 * of an afternoon. Every Arma unit already has a preset file; importing it is
 * the difference between "send us your mod list" and "drop the file you already
 * have".
 *
 * Export matters as much in the other direction. A player who wants to join
 * needs the *same* mods in the *same* order, and a headless client that
 * disagrees with the server is refused at connect with a signature error. The
 * exported file loads straight into the official launcher.
 */
class PresetsPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-file-import';

    protected static string|\UnitEnum|null $navigationGroup = 'Arma 3';

    protected static ?string $slug = 'a3-presets';

    protected static ?int $navigationSort = 25;

    private ?ResolvedProfile $profileMemo = null;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        if (! $server instanceof Server) {
            return false;
        }

        $profile = app(CapabilityResolver::class)->for($server);

        return parent::canAccess()
            && $profile?->has(Capability::Presets)
            && user()?->can(SubuserPermission::FileRead, $server);
    }

    public static function getNavigationLabel(): string
    {
        return trans('arma3-manager::strings.nav.presets');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        abort_unless(user()?->can(SubuserPermission::FileRead, $this->server()), 403);

        $this->profileMemo = app(CapabilityResolver::class)->for($this->server());

        abort_unless($this->profileMemo?->has(Capability::Presets), 403);
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

    /**
     * The current load order, which is what an export would contain.
     *
     * Shown rather than described: "export your preset" with nothing on screen
     * gives the customer no way to check they are exporting what they think.
     */
    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                $order = app(ModService::class)->loadOrder($this->server(), $this->profile());

                $records = [];
                $position = 1;

                foreach ($order->all() as $entry) {
                    $records[$entry] = [
                        'entry' => $entry,
                        'position' => $position++,
                        'folder' => ModList::folder($entry),
                    ];
                }

                return $records;
            })
            ->columns([
                TextColumn::make('position')->label('#')->width('4rem'),
                TextColumn::make('folder')->label('Mod')->weight('bold'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nothing in the load order yet')
            ->emptyStateDescription('Import a preset to fill it, or add mods from the Workshop page.');
    }

    /**
     * @return array<int, Action>
     */
    protected function getDefaultHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Import a preset')
                ->icon('tabler-file-import')
                ->color('primary')
                ->visible(fn (): bool => $this->canEdit())
                ->modalHeading('Import an Arma 3 Launcher preset')
                ->modalDescription('Export one from the launcher with Mods → Preset → Export, open it in a text editor and paste the whole file here.')
                ->schema([
                    Textarea::make('html')
                        ->label('Preset file contents')
                        ->rows(10)
                        ->required(),
                    Toggle::make('replace')
                        ->label('Replace the current load order')
                        ->helperText('Off adds the preset\'s mods to what is already there. On makes the load order exactly the preset — which is what you want when matching a unit\'s modset, and destructive otherwise.')
                        ->default(false),
                    Toggle::make('with_dependencies')
                        ->label('Add required items too')
                        ->helperText('A preset exported from the launcher usually already lists them, so this rarely adds anything — but it costs nothing and catches a hand-edited file.')
                        ->default(true),
                ])
                ->action(fn (array $data) => $this->import($data)),

            Action::make('export')
                ->label('Export a preset')
                ->icon('tabler-file-export')
                ->color('gray')
                ->schema([
                    TextInput::make('name')
                        ->label('Preset name')
                        ->default(fn (): string => $this->server()->name)
                        ->required()
                        ->maxLength(120),
                ])
                ->action(fn (array $data): StreamedResponse => $this->export((string) ($data['name'] ?? 'Preset'))),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function import(array $data): void
    {
        if (! $this->canEdit()) {
            Notification::make()->title(trans('arma3-manager::strings.permission_denied'))->danger()->send();

            return;
        }

        try {
            $preset = LauncherPreset::parse((string) ($data['html'] ?? ''));
            $ids = $preset->ids();

            if ($ids === []) {
                Notification::make()
                    ->title('No mods found in that file')
                    ->body('It does not look like a launcher preset. The file needs the <table> of ModContainer rows the launcher writes.')
                    ->warning()
                    ->send();

                return;
            }

            $client = app(SteamWorkshopClient::class);
            $mods = app(ModService::class);
            $server = $this->server();
            $profile = $this->profile();

            $resolved = ! empty($data['with_dependencies'])
                ? $client->resolveDependencies($ids)
                : $ids;

            $items = $client->items($resolved);

            $order = ! empty($data['replace'])
                ? ModList::fromArray([])
                : $mods->loadOrder($server, $profile);

            $before = $order->count();
            $unknown = 0;

            foreach ($resolved as $id) {
                $item = $items[$id] ?? null;

                if ($item === null) {
                    // Steam did not return it: deleted, private, or the id was
                    // wrong. Counted and reported rather than added as a folder
                    // that will never exist.
                    $unknown++;

                    continue;
                }

                $order->add('@' . preg_replace('/[^A-Za-z0-9_\-]+/', '_', trim($item->title)));
            }

            $mods->saveLoadOrder($server, $profile, $order);

            Activity::event('server:arma3.preset-import')
                ->property([
                    'preset' => $preset->name,
                    'mods' => count($ids),
                    'replaced' => ! empty($data['replace']),
                ])
                ->log();

            $added = max(0, $order->count() - $before);

            Notification::make()
                ->title('Imported "' . $preset->name . '"')
                ->body(trim(
                    $added . ' mod(s) now in the load order.'
                    . ($unknown > 0 ? " {$unknown} item(s) could not be resolved on Steam and were skipped." : '')
                    . ($preset->dlc !== [] ? ' ' . count($preset->dlc) . ' Creator DLC in the preset were not imported — enable those on the Parameters page.' : '')
                    . ' The files still need downloading: reinstall the server so SteamCMD fetches them.'
                ))
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title('Could not import the preset')->body($exception->getMessage())->danger()->send();
        }
    }

    private function export(string $name): StreamedResponse
    {
        $order = app(ModService::class)->loadOrder($this->server(), $this->profile());

        // The export carries ids where they are known and names otherwise. A
        // load order built by hand or by SFTP has folders with no workshop id
        // behind them, and LauncherPreset::render drops those rather than
        // writing a row the launcher would reject.
        $client = app(SteamWorkshopClient::class);
        $mods = [];

        foreach ($order->all() as $entry) {
            $folder = ltrim(ModList::folder($entry), '@');

            $mods[] = ['id' => '', 'name' => $folder];
        }

        // Resolve names back to ids where the search endpoint can do it. Best
        // effort: without an API key this yields nothing and the export still
        // renders, just with fewer rows.
        if ($client->canSearch()) {
            foreach ($mods as $index => $mod) {
                $hits = $client->search($mod['name'], perPage: 1);

                if ($hits !== []) {
                    $mods[$index]['id'] = $hits[0]->id;
                }
            }
        }

        $html = LauncherPreset::render($name, array_values(array_filter(
            $mods,
            static fn (array $mod): bool => $mod['id'] !== '',
        )));

        Activity::event('server:arma3.preset-export')->property(['preset' => $name])->log();

        $filename = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $name) . '.html';

        return response()->streamDownload(
            fn () => print($html),
            $filename,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}
