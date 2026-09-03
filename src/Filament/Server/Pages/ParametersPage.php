<?php

namespace FyWolf\Arma3Manager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Filament\Server\Pages\ServerFormPage;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use FyWolf\Arma3Manager\Enums\Capability;
use FyWolf\Arma3Manager\Services\ModService;
use FyWolf\Arma3Manager\Support\CapabilityResolver;
use FyWolf\Arma3Manager\Support\ResolvedProfile;
use FyWolf\Arma3Manager\Support\ServerVariables;
use FyWolf\Arma3Manager\Support\StartupParameters;
use Throwable;

/**
 * Extra command-line flags, headless clients and Creator DLC.
 *
 * ## Managed flags are shown and never written
 *
 * `-port`, `-config`, `-mod` and the rest belong to the panel's allocation and
 * to the other pages here. They are rendered read-only so a customer can see
 * what the server is actually started with, and are stripped on save.
 *
 * That stripping is the point of the page rather than a detail. A free-text
 * startup box lets a customer append their own `-mod=@whatever`, which Arma
 * honours over anything set earlier — silently replacing the load order the
 * Mods page manages, with no error and no clue where it went.
 *
 * ## Creator DLC are toggles, not workshop items
 *
 * A CDLC loads through `-mod=` like any other addon, but it is *owned* rather
 * than downloaded: it arrives with the game files for an account that has it,
 * and no amount of SteamCMD will fetch it otherwise. Offering one in the
 * Workshop browser would queue a download that can never succeed, so they live
 * here as toggles that add or remove the short code from the load order.
 */
class ParametersPage extends ServerFormPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-terminal-2';

    protected static string|\UnitEnum|null $navigationGroup = 'Arma 3';

    protected static ?string $slug = 'a3-parameters';

    protected static ?int $navigationSort = 26;

    private ?ResolvedProfile $profileMemo = null;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        if (! $server instanceof Server) {
            return false;
        }

        $profile = app(CapabilityResolver::class)->for($server);

        return parent::canAccess()
            && $profile?->has(Capability::Parameters)
            && user()?->can(SubuserPermission::StartupRead, $server);
    }

    public static function getNavigationLabel(): string
    {
        return trans('arma3-manager::strings.nav.parameters');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(user()?->can(SubuserPermission::StartupRead, $this->getRecord()), 403);

        $this->profileMemo = app(CapabilityResolver::class)->for($this->getRecord());

        abort_unless($this->profileMemo?->has(Capability::Parameters), 403);
    }

    private function profile(): ResolvedProfile
    {
        return $this->profileMemo ??= app(CapabilityResolver::class)->for($this->getRecord());
    }

    private function canEdit(): bool
    {
        return user()?->can(SubuserPermission::StartupUpdate, $this->getRecord()) ?? false;
    }

    /**
     * @return array<int, string>
     */
    private function parameterVariables(): array
    {
        return $this->profile()->parameterVariables !== []
            ? $this->profile()->parameterVariables
            : ['STARTUP_PARAMS', 'EXTRA_FLAGS'];
    }

    /**
     * @return array<int, string>
     */
    private function headlessVariables(): array
    {
        return $this->profile()->headlessVariables;
    }

    protected function fillForm(): void
    {
        $server = $this->getRecord();

        $flags = StartupParameters::parse(ServerVariables::read($server, $this->parameterVariables()));

        $data = [];

        foreach ((array) config('arma3-manager.parameters.known_flags', []) as $name => $spec) {
            $value = $flags->get($name);

            $data['flags'][$name] = match ($spec['type'] ?? 'string') {
                'bool' => $value === true,
                'int' => is_string($value) ? (int) $value : null,
                default => is_string($value) ? $value : '',
            };
        }

        // Whatever the customer typed that is neither known nor managed. Kept
        // and shown rather than silently dropped on the next save.
        $extra = [];

        foreach ($flags->customisable() as $name => $value) {
            if (! array_key_exists($name, (array) config('arma3-manager.parameters.known_flags', []))) {
                $extra[] = $value === true ? "-$name" : "-$name=$value";
            }
        }

        $data['extra'] = implode(' ', $extra);

        if ($this->headlessVariables() !== []) {
            $data['headless'] = (int) (ServerVariables::read($server, $this->headlessVariables()) ?: 0);
        }

        $order = app(ModService::class)->loadOrder($server, $this->profile());

        foreach ((array) config('arma3-manager.parameters.creator_dlc', []) as $code => $label) {
            $data['dlc'][$code] = $order->has($code);
        }

        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        $canEdit = $this->canEdit();
        $components = [];

        $known = [];

        foreach ((array) config('arma3-manager.parameters.known_flags', []) as $name => $spec) {
            $field = match ($spec['type'] ?? 'string') {
                'bool' => Toggle::make("flags.$name"),
                'int' => TextInput::make("flags.$name")
                    ->numeric()
                    ->minValue($spec['min'] ?? null)
                    ->maxValue($spec['max'] ?? null),
                default => TextInput::make("flags.$name"),
            };

            $field = $field->label('-' . $name);

            if (! empty($spec['helper'])) {
                $field = $field->helperText($spec['helper']);
            }

            $known[] = $canEdit ? $field : $field->disabled();
        }

        if ($known !== []) {
            $components[] = Section::make('Startup flags')->columns(2)->schema($known)->collapsible();
        }

        if ($this->headlessVariables() !== []) {
            $headless = TextInput::make('headless')
                ->label('Headless clients')
                ->numeric()
                ->minValue(0)
                ->maxValue(8)
                ->helperText('Extra local clients started alongside the server to run AI. Each one needs the same mods as the server or it is refused at connect, and each one costs a CPU core.');

            $components[] = Section::make('Headless clients')
                ->schema([$canEdit ? $headless : $headless->disabled()])
                ->collapsible();
        }

        $dlc = [];

        foreach ((array) config('arma3-manager.parameters.creator_dlc', []) as $code => $label) {
            $toggle = Toggle::make("dlc.$code")
                ->label($label)
                ->helperText('Recorded in the manifest as `' . $code . '`. It is deliberately not added to the mod list, which holds Workshop ids that SteamCMD downloads — a CDLC is owned, not downloaded, so an id list is the wrong place for it.');

            $dlc[] = $canEdit ? $toggle : $toggle->disabled();
        }

        if ($dlc !== []) {
            $components[] = Section::make('Creator DLC')->columns(2)->schema($dlc)->collapsed();
        }

        $extra = TextInput::make('extra')
            ->label('Additional flags')
            ->helperText('Anything else, written as it would appear on the command line — for example `-noSound -limitFPS=60`. Flags the panel manages are ignored here.');

        $components[] = Section::make('Additional flags')
            ->schema([
                $canEdit ? $extra : $extra->disabled(),
                Placeholder::make('managed_note')
                    ->label('Managed by the panel')
                    ->content(implode(', ', array_map(
                        static fn (string $flag): string => '-' . $flag,
                        StartupParameters::managed(),
                    )) . ' — these are set from your allocation and from the Mods, Missions and Configuration pages, and cannot be overridden here.'),
            ])
            ->collapsible();

        return $schema->components($components)->statePath('data');
    }

    public function save(): void
    {
        $server = $this->getRecord();

        abort_unless($this->canEdit(), 403);

        try {
            $current = ServerVariables::read($server, $this->parameterVariables());

            if ($current === null) {
                Notification::make()
                    ->title('Nowhere to save')
                    ->body(trans('arma3-manager::strings.variable_missing', [
                        'names' => implode(' / ', $this->parameterVariables()),
                    ]))
                    ->danger()
                    ->send();

                return;
            }

            $state = $this->form->getState();

            // Start from what the customer typed in "additional flags", then
            // layer the known ones over it. Managed flags are stripped once at
            // the end rather than filtered in three places.
            $flags = StartupParameters::parse((string) ($state['extra'] ?? ''));

            foreach ((array) config('arma3-manager.parameters.known_flags', []) as $name => $spec) {
                $value = $state['flags'][$name] ?? null;

                $flags->set($name, match ($spec['type'] ?? 'string') {
                    'bool' => (bool) $value,
                    'int' => blank($value) ? false : (string) (int) $value,
                    default => blank($value) ? false : (string) $value,
                });
            }

            $rendered = $flags->withoutManaged()->render();

            ServerVariables::write($server, $this->parameterVariables(), $rendered);

            if ($this->headlessVariables() !== [] && array_key_exists('headless', $state)) {
                ServerVariables::write($server, $this->headlessVariables(), (string) (int) $state['headless']);
            }

            $dlc = $this->selectedCreatorDlc($state['dlc'] ?? []);

            if ($this->profile()->has(Capability::Mods)) {
                app(ModService::class)->writeManifest($server, $this->profile());
            }

            Activity::event('server:arma3.parameters-edit')
                ->property(['changed' => $rendered === '' ? 'cleared' : $rendered])
                ->log();

            $this->fillForm();

            Notification::make()
                ->title('Parameters saved')
                ->body(trim(
                    'Arma reads its command line only at startup, so restart the server to apply them.'
                    . ($dlc === []
                        ? ''
                        : ' Creator DLC are not downloadable, so they are not written into the mod list —'
                          . ' add `-mod=' . implode(';', $dlc) . ';` to your startup parameters if your egg'
                          . ' does not read the manifest.')
                ))
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title('Could not save the parameters')->body($exception->getMessage())->danger()->send();
        }
    }

    /**
     * The Creator DLC the customer has switched on.
     *
     * ## They are deliberately kept out of the mod list
     *
     * The mod list is **Workshop ids**, read by the egg's install script and
     * fed to `workshop_download_item`. A CDLC is not a Workshop item: it is
     * owned, ships with the game files, and has a short code (`gm`, `vn`) that
     * is not an id at all. Writing one into that list — which an earlier version
     * did — hands SteamCMD `gm` as though it were an id, and depending on the
     * script that either logs a failure or takes the whole install down with it.
     *
     * So the selection is recorded in the manifest and nowhere else, and the
     * page tells the customer the exact flag to add. That is less convenient
     * than doing it for them, and it is the honest position until an egg is
     * known to read the manifest: guessing that Arma merges a second `-mod=`
     * parameter rather than replacing the first is exactly the kind of
     * assumption that has already cost this plugin three releases.
     *
     * @param array<string, mixed> $selection
     *
     * @return array<int, string>
     */
    private function selectedCreatorDlc(array $selection): array
    {
        $codes = array_keys((array) config('arma3-manager.parameters.creator_dlc', []));

        return array_values(array_filter(
            $codes,
            static fn (string $code): bool => (bool) ($selection[$code] ?? false),
        ));
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
                ->disabled(fn (): bool => ! $this->canEdit()),
        ];
    }

    /**
     * The download list as one line, for display only.
     *
     * Deliberately not labelled `-mod=`: these are Workshop ids for the install
     * script, and the `-mod=` folder list is built after download by the script
     * itself, which is the only place the real folder names are known.
     */
    public function currentModLine(): string
    {
        $order = app(ModService::class)->loadOrder($this->getRecord(), $this->profile());

        return $order->isEmpty() ? '(no mods)' : $order->renderPlain();
    }
}
