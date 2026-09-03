<?php

namespace FyWolf\Arma3Manager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Filament\Server\Pages\ServerFormPage;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use FyWolf\Arma3Manager\Enums\Capability;
use FyWolf\Arma3Manager\Services\ConfigService;
use FyWolf\Arma3Manager\Support\ArmaConfigFile;
use FyWolf\Arma3Manager\Support\CapabilityResolver;
use FyWolf\Arma3Manager\Support\ResolvedProfile;
use Throwable;

/**
 * Edit server.cfg and basic.cfg as a form.
 *
 * Extends the panel's own ServerFormPage rather than a plain Page. That base
 * class is what supplies `$this->form` (via InteractsWithForms), the `$data`
 * state path, and the Blade view that actually renders and submits a form —
 * a plain Page has `content()` and no form at all, which renders an empty page.
 *
 * Three rules govern what this does to the file.
 *
 * **Nothing is ever dropped.** Keys the schema does not know about — a setting
 * from a newer Arma build, a mission framework's own key, a hand-written typo —
 * appear in a collapsed "Other settings" section as plain text inputs. They are
 * visible and editable, and they round-trip byte-for-byte if untouched, because
 * ArmaConfigFile rewrites individual statements rather than rebuilding the file.
 *
 * **The `class Missions` block is never touched here.** It is edited on the
 * Missions page, which owns it, and rewriting it from a form that does not
 * model it would flatten the rotation to nothing. `ArmaConfigFile` carries
 * class blocks as opaque chunks precisely so this page cannot damage one.
 *
 * **Nothing is ever logged that shouldn't be.** `password` and `passwordAdmin`
 * live in this file, so the activity entry records which keys changed and never
 * their values.
 */
class ConfigsPage extends ServerFormPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-adjustments';

    protected static string|\UnitEnum|null $navigationGroup = 'Arma 3';

    protected static ?string $slug = 'a3-config';

    protected static ?int $navigationSort = 24;

    /** Which file is being edited. A Livewire property, so it survives a save. */
    public ?string $file = null;

    /** @var array<string, string> */
    public array $unknown = [];

    public bool $fileMissing = false;

    private ?ResolvedProfile $profileMemo = null;

    private ?ArmaConfigFile $configMemo = null;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        if (! $server instanceof Server) {
            return false;
        }

        $profile = app(CapabilityResolver::class)->for($server);

        return parent::canAccess()
            && $profile?->has(Capability::Configs)
            && $profile->configFiles() !== []
            && user()?->can(SubuserPermission::FileReadContent, $server);
    }

    public static function getNavigationLabel(): string
    {
        return trans('arma3-manager::strings.nav.configs');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(user()?->can(SubuserPermission::FileReadContent, $this->getRecord()), 403);

        // Filament runs mount() before it enforces canAccess(), so this page is
        // reachable by a server whose egg resolves to no profile. Fail as a 403
        // rather than letting a null profile surface as a TypeError later.
        $this->profileMemo = app(CapabilityResolver::class)->for($this->getRecord());

        abort_unless($this->profileMemo?->has(Capability::Configs), 403);

        $files = $this->profileMemo->configFiles();

        abort_if($files === [], 403);

        // Only accept a file the profile actually names. Without this the
        // property is a path the customer controls and this page becomes an
        // arbitrary file reader for anything the daemon will serve.
        if ($this->file === null || ! in_array($this->file, $files, true)) {
            $this->file = $files[0];
        }
    }

    private function profile(): ResolvedProfile
    {
        return $this->profileMemo ??= app(CapabilityResolver::class)->for($this->getRecord());
    }

    private function currentFile(): string
    {
        $files = $this->profile()->configFiles();

        return in_array((string) $this->file, $files, true) ? (string) $this->file : ($files[0] ?? 'server.cfg');
    }

    private function isRunning(): bool
    {
        return ! $this->getRecord()->retrieveStatus()->isOffline();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function schemaDefinition(): array
    {
        return $this->profile()->schemaFor($this->currentFile());
    }

    /**
     * Read the config into the form.
     *
     * Overrides the parent, which fills the form with the Server model's own
     * attributes — meaningless here, since none of these fields are columns.
     */
    protected function fillForm(): void
    {
        $config = app(ConfigService::class)->read($this->getRecord(), $this->currentFile());

        if (! $config) {
            $this->fileMissing = true;
            $this->unknown = [];
            $this->form->fill([]);

            return;
        }

        $this->configMemo = $config;
        $this->fileMissing = false;

        $scalars = $config->all();
        $arrays = $config->arrays();
        $definition = $this->schemaDefinition();

        $data = [];

        foreach ($definition as $key => $spec) {
            $type = $spec['type'] ?? 'string';

            if ($type === 'array') {
                $data[$key] = $arrays[$key] ?? [];

                continue;
            }

            $raw = $scalars[$key] ?? null;

            $data[$key] = match ($type) {
                // Arma writes 0/1 integers, never true/false. A `true` written
                // into server.cfg parses as 0 and silently disables whatever it
                // was meant to enable.
                'bool01' => $raw === null ? false : in_array(trim($raw), ['1', 'true'], true),
                'int' => $raw === null || $raw === '' ? null : (int) $raw,
                'float' => $raw === null || $raw === '' ? null : (float) $raw,
                default => $raw ?? (string) ($spec['default'] ?? ''),
            };

            // A password is never echoed back to the browser, so a blank
            // submission has to mean "unchanged" — see save().
            if (! empty($spec['sensitive'])) {
                $data[$key] = null;
            }
        }

        // Everything present in the file and absent from the schema. Arrays are
        // excluded: they are edited by the fields that know they want one, and
        // rendering one as a text input would flatten it on save.
        $this->unknown = array_diff_key($scalars, $definition);

        foreach ($this->unknown as $key => $value) {
            $data['unknown'][$key] = $value;
        }

        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        $definition = $this->schemaDefinition();

        $groups = [
            'identity' => 'Identity and slots',
            'security' => 'Security and signatures',
            'mission' => 'Mission',
            'voting' => 'Voting',
            'network' => 'Network',
            'logging' => 'Logging and callbacks',
            'bandwidth' => 'Bandwidth',
            'simulation' => 'Simulation',
        ];

        $components = [];

        if ($this->fileMissing) {
            return $schema->components([
                Section::make($this->currentFile() . ' does not exist yet')
                    ->description('Arma does not create its configuration files by itself, and this server\'s egg has not created one either.')
                    ->schema([
                        Placeholder::make('missing_hint')
                            ->label('')
                            ->content('Use "Create it" above to write a minimal, valid file, then edit it here.'),
                    ]),
            ])->statePath('data');
        }

        if ($this->isRunning()) {
            $components[] = Section::make('The server is running')
                ->description(trans('arma3-manager::strings.server_running.warning'))
                ->schema([]);
        }

        foreach ($groups as $group => $label) {
            $fields = [];

            foreach ($definition as $key => $spec) {
                if (($spec['group'] ?? null) === $group) {
                    $fields[] = $this->buildField($key, $spec);
                }
            }

            if ($fields !== []) {
                $components[] = Section::make($label)->columns(2)->schema($fields)->collapsible();
            }
        }

        if ($this->unknown !== []) {
            $components[] = Section::make('Other settings')
                ->description('Present in the file but not described by this plugin — a newer Arma build, or a mission framework\'s own setting. Edited here as plain text and otherwise left exactly as found.')
                ->collapsed()
                ->columns(2)
                ->schema(array_map(
                    fn (string $key) => TextInput::make('unknown.' . $key)->label($key),
                    array_keys($this->unknown),
                ));
        }

        return $schema->components($components)->statePath('data');
    }

    /**
     * Whether a setting is off-limits to the customer.
     *
     * Two sources: the per-key `managed_by_panel` flag (things the panel itself
     * owns), and the admin-configurable lock list (things provisioning owns,
     * like maxPlayers on a host that sells slots).
     *
     * Consulted by BOTH the form and save(). The form use is a courtesy; the
     * save use is the actual enforcement.
     *
     * @param array<string, mixed> $spec
     */
    private function isLocked(string $key, array $spec = []): bool
    {
        if (! empty($spec['managed_by_panel'])) {
            return true;
        }

        return in_array($key, (array) config('arma3-manager.configs.locked_properties', []), true);
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function lockReason(string $key, array $spec = []): string
    {
        return ! empty($spec['managed_by_panel'])
            ? 'Managed by the panel — change it through the server\'s allocation.'
            : (string) config('arma3-manager.configs.locked_reason', 'Locked by your host.');
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function buildField(string $key, array $spec)
    {
        $locked = $this->isLocked($key, $spec);
        $canEdit = user()?->can(SubuserPermission::FileUpdate, $this->getRecord()) ?? false;

        $field = match ($spec['type'] ?? 'string') {
            'bool01' => Toggle::make($key),

            'int' => TextInput::make($key)
                ->numeric()
                ->minValue($spec['min'] ?? null)
                ->maxValue($spec['max'] ?? null),

            'float' => TextInput::make($key)->numeric(),

            'enum' => Select::make($key)
                ->options(array_combine($spec['options'] ?? [], $spec['options'] ?? []))
                ->selectablePlaceholder(false),

            'array' => TagsInput::make($key)->reorderable(),

            default => TextInput::make($key),
        };

        $field = $field->label($key);

        if (! empty($spec['sensitive'])) {
            $field = $field
                ->password()
                ->revealable()
                ->autocomplete(false)
                ->placeholder('unchanged')
                ->helperText('Leave blank to keep the current value.');
        }

        if ($locked) {
            // Shown, not hidden: a greyed field with a reason is far less
            // confusing than a setting that has silently disappeared.
            $field = $field
                ->disabled()
                ->hintIcon('tabler-lock')
                ->helperText($this->lockReason($key, $spec));
        } elseif (! $canEdit) {
            $field = $field->disabled();
        }

        if (! empty($spec['helper']) && ! $locked) {
            $field = $field->helperText($spec['helper']);
        }

        return $field;
    }

    /**
     * The view submits to this (`wire:submit="save"`).
     */
    public function save(): void
    {
        $server = $this->getRecord();

        abort_unless(user()?->can(SubuserPermission::FileUpdate, $server), 403);

        $configs = app(ConfigService::class);
        $file = $this->currentFile();
        $config = $this->configMemo ?? $configs->read($server, $file);

        if (! $config) {
            Notification::make()->title('Could not read ' . $file)->danger()->send();

            return;
        }

        $state = $this->form->getState();
        $definition = $this->schemaDefinition();

        $candidate = [];
        $rejected = [];

        foreach ($definition as $key => $spec) {
            $type = $spec['type'] ?? 'string';
            $value = $state[$key] ?? null;

            // Blank on a write-only field means "leave it alone".
            if (! empty($spec['sensitive']) && blank($value)) {
                continue;
            }

            $submitted = match ($type) {
                'bool01' => $value ? '1' : '0',
                'int' => (string) (int) $value,
                'float' => rtrim(rtrim(sprintf('%.4F', (float) $value), '0'), '.'),
                'array' => array_values(array_map('strval', (array) $value)),
                default => (string) $value,
            };

            // The real enforcement. Disabling the field in the form only stops
            // an honest browser — Livewire state arrives from the client and can
            // say anything, so a locked key must be dropped here as well.
            //
            // Only counted as an attempt when the submitted value actually
            // differs from what is on disk; otherwise every save of the page
            // would report a rejection for every locked field.
            if ($this->isLocked($key, $spec)) {
                if ($config->changedKeys([$key => $submitted]) !== []) {
                    $rejected[] = $key;
                }

                continue;
            }

            $candidate[$key] = $submitted;
        }

        foreach ((array) ($state['unknown'] ?? []) as $key => $value) {
            $key = (string) $key;

            // A locked key the schema does not describe still arrives through
            // the passthrough section, so it has to be filtered here too —
            // otherwise locking anything outside the typed schema would do
            // nothing at all.
            if ($this->isLocked($key)) {
                if ($config->changedKeys([$key => (string) $value]) !== []) {
                    $rejected[] = $key;
                }

                continue;
            }

            $candidate[$key] = (string) $value;
        }

        // A locked value arriving changed means the form state was edited past
        // the disabled attribute. Worth recording — it is the only signal a host
        // gets that someone is probing the limits of their plan.
        if ($rejected !== []) {
            Activity::event('server:arma3.config-locked-rejected')
                ->property(['file' => $file, 'keys' => implode(', ', array_unique($rejected))])
                ->log();
        }

        $changed = $config->changedKeys($candidate);

        if ($changed === []) {
            Notification::make()
                ->title('Nothing to save')
                ->body($rejected === []
                    ? 'No values changed.'
                    : implode(', ', array_unique($rejected)) . ' cannot be changed here. ' . $this->lockReason($rejected[0]))
                ->send();

            return;
        }

        try {
            $configs->write($server, $file, $config->merge($candidate));

            Activity::event('server:arma3.config-edit')
                ->property([
                    'file' => $file,
                    // Names only. This file contains password and passwordAdmin.
                    'changed' => implode(', ', $changed),
                    'changed_keys' => $changed,
                ])
                ->log();

            $this->configMemo = null;
            $this->fillForm();

            Notification::make()
                ->title($file . ' saved')
                ->body(count($changed) . ' setting(s) changed' . ($this->isRunning() ? ' — Arma reads this file only at startup, so restart to apply.' : '.'))
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title('Could not write ' . $file)->body($exception->getMessage())->danger()->send();
        }
    }

    public function switchFile(string $file): void
    {
        if (in_array($file, $this->profile()->configFiles(), true)) {
            $this->file = $file;
            $this->configMemo = null;
            $this->fillForm();
        }
    }

    /**
     * Public, not protected: InteractsWithFormActions declares this public, and
     * PHP forbids narrowing an inherited method's visibility. Doing so is a
     * fatal at class-load time, which in a panel means boot, which means the
     * whole panel — not just this page.
     *
     * @return array<int, Action>
     */
    public function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save changes')
                ->icon('tabler-device-floppy')
                ->submit('save')
                ->keyBindings(['mod+s'])
                ->disabled(fn (): bool => $this->fileMissing
                    || ! user()?->can(SubuserPermission::FileUpdate, $this->getRecord())),
        ];
    }

    /**
     * getDefaultHeaderActions, not getHeaderActions — see ModsPage.
     *
     * @return array<int, Action>
     */
    protected function getDefaultHeaderActions(): array
    {
        $actions = [];

        foreach ($this->profile()->configFiles() as $file) {
            $actions[] = Action::make('file_' . md5($file))
                ->label($file)
                ->icon('tabler-file-text')
                ->color(fn (): string => $this->currentFile() === $file ? 'primary' : 'gray')
                ->action(fn () => $this->switchFile($file));
        }

        $actions[] = Action::make('create')
            ->label('Create it')
            ->icon('tabler-file-plus')
            ->color('primary')
            ->visible(fn (): bool => $this->fileMissing
                && (user()?->can(SubuserPermission::FileCreate, $this->getRecord()) ?? false))
            ->requiresConfirmation()
            ->modalHeading('Create ' . $this->currentFile())
            ->modalDescription('Writes a minimal, valid file with only the settings that have no safe default. Nothing else is assumed on your behalf.')
            ->action(function (): void {
                try {
                    app(ConfigService::class)->scaffold($this->getRecord(), $this->currentFile());

                    Activity::event('server:arma3.config-create')->property(['file' => $this->currentFile()])->log();

                    $this->configMemo = null;
                    $this->fillForm();

                    Notification::make()->title($this->currentFile() . ' created')->success()->send();
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()->title('Could not create the file')->body($exception->getMessage())->danger()->send();
                }
            });

        $actions[] = Action::make('files')
            ->label('File manager')
            ->icon('tabler-folder-open')
            ->color('gray')
            ->url(fn () => ListFiles::getUrl(['path' => '/']), true);

        return $actions;
    }
}
