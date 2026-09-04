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
use FyWolf\Arma3Manager\Models\Preset;
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
     * The presets saved for this server.
     *
     * Importing used to merge a preset into the load order and throw the file
     * away, so this page showed the load order and nothing else — which is the
     * Mods page's job, and left a customer with no way to keep two modsets and
     * switch between them. A unit that plays two campaigns has two presets.
     */
    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                $order = app(ModService::class)->loadOrder($this->server(), $this->profile());

                $records = [];

                foreach (Preset::query()->where('server_id', $this->server()->id)->orderBy('name')->get() as $preset) {
                    $active = $preset->matches($order);

                    $records[(string) $preset->id] = [
                        'id' => $preset->id,
                        'name' => $preset->name,
                        'entries' => count($preset->entries ?? []),
                        'workshop' => $preset->workshopCount(),
                        'active' => $active,
                        'status' => match (true) {
                            $active => 'Active',
                            // An empty load order after applying is not the
                            // customer editing it — it is the write not having
                            // landed, and saying "changed" sends them looking
                            // for an edit they did not make.
                            $preset->applied_at !== null && $order->isEmpty() => 'Applied, but nothing is loaded',
                            $preset->applied_at !== null => 'Applied, then changed',
                            default => 'Not applied',
                        },
                        'status_color' => match (true) {
                            $active => 'success',
                            $preset->applied_at !== null => 'warning',
                            default => 'gray',
                        },
                        'applied_at' => $preset->applied_at?->diffForHumans(),
                    ];
                }

                return $records;
            })
            ->columns([
                TextColumn::make('name')
                    ->label('Preset')
                    ->weight('bold')
                    ->description(fn (array $record): string => $record['entries'] . ' entr(ies), '
                        . $record['workshop'] . ' from the Workshop'),

                TextColumn::make('status')
                    ->label('State')
                    ->badge()
                    ->color(fn (array $record): string => $record['status_color'])
                    ->tooltip(fn (array $record): string => match ($record['status']) {
                        'Active' => 'This preset is exactly what the server is set to load.',
                        'Applied, but nothing is loaded' => 'This was applied ' . ($record['applied_at'] ?? 'earlier') . ' but the mod list on this server is empty, which means the write did not reach the egg. Run `php artisan arma3-manager:diagnose <server>` on the panel to see where the chain breaks.',
                        'Applied, then changed' => 'This was applied ' . ($record['applied_at'] ?? 'earlier') . ', and the load order has been edited since. Apply it again to go back to it.',
                        default => 'Saved but never applied. Applying it replaces the load order.',
                    }),
            ])
            ->paginated(false)
            ->emptyStateHeading('No presets saved yet')
            ->emptyStateDescription('Import the .html file the Arma 3 Launcher exports and it is kept here, so you can switch between modsets later.')
            ->recordActions([
                Action::make('apply')
                    ->label('Make active')
                    ->icon('tabler-circle-check')
                    ->visible(fn (array $record): bool => $this->canEdit() && ! $record['active'])
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => 'Make "' . $record['name'] . '" the active preset?')
                    ->modalDescription('Replaces the whole load order with this preset. Anything currently loaded and not in it is removed — the files stay on disk, so applying another preset puts them back without downloading again.')
                    ->action(fn (array $record) => $this->apply((int) $record['id'])),

                Action::make('delete')
                    ->label('Delete')
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->iconButton()
                    ->visible(fn (): bool => $this->canEdit())
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => 'Delete the preset "' . $record['name'] . '"?')
                    ->modalDescription('Only the saved preset goes. The load order and the downloaded files are untouched.')
                    ->action(fn (array $record) => $this->forget((int) $record['id'])),
            ]);
    }

    /**
     * Write a saved preset over the load order.
     */
    private function apply(int $id): void
    {
        if (! $this->canEdit()) {
            Notification::make()->title(trans('arma3-manager::strings.permission_denied'))->danger()->send();

            return;
        }

        $preset = Preset::query()->where('server_id', $this->server()->id)->find($id);

        if (! $preset) {
            Notification::make()->title('That preset no longer exists')->danger()->send();

            return;
        }

        try {
            app(ModService::class)->saveLoadOrder($this->server(), $this->profile(), $preset->modList());

            $preset->forceFill(['applied_at' => now()])->save();

            Activity::event('server:arma3.preset-apply')
                ->property(['preset' => $preset->name, 'mods' => count($preset->entries ?? [])])
                ->log();

            Notification::make()
                ->title('"' . $preset->name . '" is now the active preset')
                ->body('Start the server to fetch anything new — the Mods page shows each one arriving.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title('Could not apply the preset')->body($exception->getMessage())->danger()->send();
        }
    }

    private function forget(int $id): void
    {
        if (! $this->canEdit()) {
            Notification::make()->title(trans('arma3-manager::strings.permission_denied'))->danger()->send();

            return;
        }

        $preset = Preset::query()->where('server_id', $this->server()->id)->find($id);

        if ($preset) {
            $name = $preset->name;
            $preset->delete();

            Activity::event('server:arma3.preset-delete')->property(['preset' => $name])->log();

            Notification::make()->title('Preset deleted')->success()->send();
        }
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
                ->label('Export what is loaded')
                ->icon('tabler-file-export')
                ->color('gray')
                ->modalDescription('Writes the server\'s current load order as a launcher preset, so a player can load the same mods. Exports what is actually loaded right now, which may differ from any saved preset.')
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

            // What this preset *is*, kept separately from what the load order
            // becomes. With "replace" off the two differ — the load order is
            // the merge, the preset is only its own mods — and saving the merge
            // under the preset's name would mean re-applying it later pulled in
            // whatever happened to be loaded on the day it was imported.
            $presetEntries = [];

            foreach ($resolved as $id) {
                $item = $items[$id] ?? null;

                if ($item === null) {
                    // Steam did not return it: deleted, private, or the id was
                    // wrong. Counted and reported rather than added as something
                    // that can never be fetched.
                    $unknown++;

                    continue;
                }

                // `@` + the id — see WorkshopPage for why the prefix is what
                // makes it download.
                $entry = WorkshopId::modEntry($item->id);

                $presetEntries[] = $entry;
                $order->add($entry);
            }

            $mods->saveLoadOrder($server, $profile, $order);

            // Kept, so the customer can switch back to it later. Keyed on the
            // name per server, so exporting the same preset from the launcher
            // twice updates one row instead of growing near-duplicates.
            $saved = Preset::updateOrCreate(
                ['server_id' => $server->id, 'name' => $preset->name],
                ['entries' => $presetEntries, 'applied_at' => now()],
            );

            Activity::event('server:arma3.preset-import')
                ->property([
                    'preset' => $preset->name,
                    'mods' => count($ids),
                    'replaced' => ! empty($data['replace']),
                ])
                ->log();

            $added = max(0, $order->count() - $before);

            Notification::make()
                ->title('Imported "' . $saved->name . '"')
                ->body(trim(
                    'Saved as a preset and made active. ' . $added . ' mod(s) now in the load order.'
                    . ($unknown > 0 ? " {$unknown} item(s) could not be resolved on Steam and were skipped." : '')
                    . ($preset->dlc !== [] ? ' ' . count($preset->dlc) . ' Creator DLC in the preset were not imported — enable those on the Parameters page.' : '')
                    . ' Start the server to fetch them — the Mods page shows each one arriving.'
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
        // Only the Workshop entries. A CDLC code or a hand-uploaded folder has
        // no Steam id and no place in a launcher preset, so it is left out
        // rather than exported as a row the launcher would reject.
        $ids = array_values(array_filter(array_map(
            WorkshopId::fromModEntry(...),
            $order->all(),
        )));
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
