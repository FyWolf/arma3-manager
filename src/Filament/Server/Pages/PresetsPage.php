<?php

namespace FyWolf\Arma3Manager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
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
use FyWolf\Arma3Manager\Support\InvalidPresetException;
use FyWolf\Arma3Manager\Support\LauncherPreset;
use FyWolf\Arma3Manager\Support\ModList;
use FyWolf\Arma3Manager\Support\ResolvedProfile;
use FyWolf\Arma3Manager\Support\WorkshopId;
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
                $titles = app(SteamWorkshopClient::class)->items($order->all());

                $records = [];
                $position = 1;

                foreach ($order->all() as $entry) {
                    $records[$entry] = [
                        'entry' => $entry,
                        'position' => $position++,
                        // The title when Steam knows it, the id otherwise. The
                        // load order is ids, and a column of bare numbers is not
                        // something anyone can check against their launcher.
                        'name' => $titles[$entry]->title ?? $entry,
                    ];
                }

                return $records;
            })
            ->columns([
                TextColumn::make('position')->label('#')->width('4rem'),
                TextColumn::make('name')
                    ->label('Mod')
                    ->weight('bold')
                    ->description(fn (array $record): string => 'Workshop id ' . $record['entry']),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nothing in the load order yet')
            ->emptyStateDescription('Import a preset to fill it, or add mods from the Workshop page.');
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
            Action::make('import')
                ->label('Import a preset')
                ->icon('tabler-file-import')
                ->color('primary')
                ->visible(fn (): bool => $this->canEdit())
                ->modalHeading('Import an Arma 3 Launcher preset')
                ->modalDescription('Export one from the launcher with Mods → Preset → Export, then upload the .html file it writes.')
                ->schema([
                    FileUpload::make('file')
                        ->label('Preset file')
                        // There is deliberately no acceptedFileTypes() here.
                        //
                        // Filament turns it into a Laravel `mimetypes:` rule,
                        // which for a Livewire upload resolves through
                        // TemporaryUploadedFile::getMimeType() -> Storage::mimeType()
                        // -> libmagic **on the server**. A real launcher export
                        // is a UTF-8 BOM, then an XML prolog, then an HTML body,
                        // and what libmagic makes of that depends on its version
                        // and magic database: Windows PHP says text/xml, and at
                        // least one Linux panel says something outside a list
                        // that already held text/xml, application/xml,
                        // text/html, application/xhtml+xml and text/plain.
                        //
                        // Guessing the next value is not a fix, it is the same
                        // bug waiting for a different server — and the failure
                        // is a framework message naming MIME types the customer
                        // has no way to check. LauncherPreset::fromFile() reads
                        // the bytes and is the authority; every refusal it gives
                        // is a sentence written to be shown to a customer.
                        //
                        // The cost is that the file picker no longer pre-filters
                        // to .html, which is worth it.
                        ->maxSize((int) ceil(LauncherPreset::MAX_BYTES / 1024))
                        // Never written to the panel's disk. The file is read
                        // once, scanned for workshop ids and dropped — there is
                        // no reason to keep a customer's upload afterwards, and
                        // anything kept is something that has to be secured.
                        ->storeFiles(false)
                        ->helperText('The .html file the Arma 3 Launcher writes (Mods → Preset → Export). Any file is accepted here and checked by reading it, so if it is not a preset you will be told why. It is read once and never stored.'),

                    Textarea::make('html')
                        ->label('…or paste the file contents')
                        ->rows(6)
                        ->helperText('If uploading is awkward, open the file in a text editor and paste it here instead.'),
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

        // One try around both the read and the parse: `uploadedContents()`
        // throws the same exception for an oversized file, and leaving it
        // outside would turn the one refusal that arrives before parsing into
        // an unhandled 500.
        try {
            $contents = $this->uploadedContents($data['file'] ?? null) ?? (string) ($data['html'] ?? '');

            if (trim($contents) === '') {
                Notification::make()
                    ->title('Nothing to import')
                    ->body('Upload the .html file the launcher exported, or paste its contents.')
                    ->warning()
                    ->send();

                return;
            }

            // Every refusal reason is written for the customer, so it is shown
            // as given rather than flattened into "invalid file".
            $preset = LauncherPreset::fromFile($contents);
        } catch (InvalidPresetException $exception) {
            Notification::make()
                ->title('That file was not imported')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        try {
            $ids = $preset->ids();

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
                    // wrong. Counted and reported rather than added as something
                    // that can never be fetched.
                    $unknown++;

                    continue;
                }

                // The id itself. See WorkshopPage for why a name is useless
                // here — the install script downloads by id or not at all.
                $order->add($item->id);
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

    /**
     * The bytes of an uploaded preset, or null when nothing was uploaded.
     *
     * `->storeFiles(false)` leaves Livewire's `TemporaryUploadedFile` in the
     * form state rather than a path, which is what keeps the upload off this
     * panel's disk. The shape is awkward — Filament keys it by a generated uuid
     * even for a single file — so both an array and a bare object are handled.
     *
     * The size is checked before the contents are read. `maxSize()` on the
     * field already refuses an oversized upload, but that runs on a value the
     * request supplies; this reads what is actually on disk, and it is the
     * check standing between a large file and `file_get_contents` in memory.
     *
     * @param mixed $state
     */
    private function uploadedContents(mixed $state): ?string
    {
        $file = is_array($state) ? (reset($state) ?: null) : $state;

        if (! is_object($file) || ! method_exists($file, 'get')) {
            return null;
        }

        if (method_exists($file, 'getSize') && (int) $file->getSize() > LauncherPreset::MAX_BYTES) {
            throw new InvalidPresetException('That file is larger than this page accepts.');
        }

        try {
            return (string) $file->get();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function export(string $name): StreamedResponse
    {
        $order = app(ModService::class)->loadOrder($this->server(), $this->profile());

        // Straightforward now that the load order *is* ids. This used to guess
        // a name from the folder and then search Steam to find the id back
        // again — a round trip that needed an API key, produced fewer rows than
        // the server actually runs, and could match the wrong mod outright.
        $ids = array_values(array_filter($order->all(), WorkshopId::isValid(...)));
        $items = app(SteamWorkshopClient::class)->items($ids);

        $mods = [];

        foreach ($ids as $id) {
            $mods[] = [
                'id' => $id,
                // The title if Steam knows it, the id otherwise. A preset row
                // needs a label, and an id is a worse one than a name but an
                // honest one — better than dropping the mod from the export.
                'name' => $items[$id]->title ?? $id,
            ];
        }

        $html = LauncherPreset::render($name, $mods);

        Activity::event('server:arma3.preset-export')->property(['preset' => $name])->log();

        $filename = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $name) . '.html';

        return response()->streamDownload(
            fn () => print($html),
            $filename,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}
