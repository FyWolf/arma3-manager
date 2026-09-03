<?php

namespace FyWolf\Arma3Manager\Filament\Admin\Pages;

use App\Models\Egg;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use FyWolf\Arma3Manager\Enums\Capability;
use FyWolf\Arma3Manager\Models\CapabilityProfile;
use FyWolf\Arma3Manager\Models\EggCapabilityProfile;
use FyWolf\Arma3Manager\Support\CapabilityResolver;
use FyWolf\Arma3Manager\Support\ResolvedProfile;
use Livewire\Attributes\Url;
use Throwable;

/**
 * Which eggs get the Arma 3 pages, and why.
 *
 * ## The screen that was missing
 *
 * `CapabilityProfileResource` lists profiles and the eggs **explicitly** mapped
 * to them. That is one of the four outcomes the resolver can produce, and it is
 * the only one that was visible anywhere:
 *
 * - **explicit** — an admin mapped it. Already visible on the profile.
 * - **inherited** — the parent egg (`config_from`) is mapped. Looked unmapped.
 * - **detected** — guessed from tags, name and the Steam app id, and never
 *   written to the database on purpose. Looked unmapped.
 * - **nothing** — no pages at all, no navigation entries, no errors.
 *
 * The last one is what costs support time, because it has no symptom: the pages
 * are simply not there. "The mods page has disappeared" was answered by reading
 * `CapabilityResolver` and guessing. Now it is a row.
 *
 * ## Why it is a Page and not a tab on the profile resource
 *
 * `ManageRecords::getTabs()` filters the resource's own records, and the records
 * here are **eggs**, not profiles. A tab would have to fake that, and the fake
 * would be a second query with its own idea of what "mapped" means — which is
 * the exact drift this screen exists to expose.
 *
 * ## Two eggs, three servers
 *
 * The server count is not decoration. Changing an egg's profile changes which
 * pages every server on it gets, immediately and without a deploy. Seeing "14
 * servers" before pinning a guess is the difference between a decision and a
 * surprise.
 */
class EggCoveragePage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-eggs';

    protected static ?string $slug = 'arma3-eggs';

    protected static ?int $navigationSort = 2;

    /**
     * Whether to list every egg on the panel, or only the ones this plugin has
     * an opinion about.
     *
     * Defaults to false, and the default is the useful one: a panel that also
     * hosts Minecraft, Rust and ARK has a long egg list, and burying the three
     * Arma rows in it makes the screen worse than not having it. The toggle is
     * there because "why does this egg get nothing" is sometimes asked about an
     * egg the heuristic does not recognise as Arma at all — which is itself the
     * answer, and needs the egg to be on screen to say so.
     */
    #[Url]
    public bool $showAll = false;

    public static function getNavigationLabel(): string
    {
        return 'Arma 3 Eggs';
    }

    public function getTitle(): string
    {
        return 'Arma 3 egg coverage';
    }

    public function getSubheading(): ?string
    {
        return 'What each egg resolves to, and therefore which pages its servers see.';
    }

    /**
     * Gated on the profile permission rather than a new one.
     *
     * This screen shows the same facts the profile resource does and offers the
     * same edits, so a second permission would only create a state where
     * somebody can change a mapping but not see its effect.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', CapabilityProfile::class) ?? false;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('egg')
                    ->label('Egg')
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (array $record): ?string => $record['parent']
                        ? 'Inherits configuration from ' . $record['parent']
                        : null),

                TextColumn::make('resolves_to')
                    ->label('Resolves to')
                    ->placeholder('nothing')
                    ->badge()
                    ->color(fn (array $record): string => $record['resolves_to'] === null ? 'gray' : 'success'),

                TextColumn::make('source_label')
                    ->label('How')
                    ->badge()
                    ->color(fn (array $record): string => $record['source_color'])
                    ->tooltip(fn (array $record): ?string => $record['source_hint']),

                TextColumn::make('pages')
                    ->label('Pages granted')
                    ->badge()
                    ->placeholder('none')
                    ->limitList(4)
                    ->expandableLimitedList(),

                TextColumn::make('servers')
                    ->label('Servers')
                    ->badge()
                    ->color(fn (array $record): string => $record['servers'] > 0 ? 'warning' : 'gray')
                    ->tooltip('How many servers this change would affect.'),
            ])
            ->paginated(false)
            ->emptyStateHeading(fn (): string => $this->showAll
                ? 'No eggs on this panel'
                : 'No Arma 3 eggs found')
            ->emptyStateDescription(fn (): string => $this->showAll
                ? 'Import an egg first.'
                : 'Nothing here looks like Arma 3. Use "Show every egg" if you expected one of yours to be listed — it will say why it was not matched.')
            ->recordActions([
                Action::make('pin')
                    ->label('Pin this guess')
                    ->icon('tabler-pin')
                    ->iconButton()
                    ->tooltip('Write the detected profile down so it can be changed, and so it stops depending on the heuristic.')
                    ->visible(fn (array $record): bool => $record['source'] === ResolvedProfile::SOURCE_HEURISTIC)
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => 'Pin ' . $record['egg'] . ' to ' . $record['resolves_to'] . '?')
                    ->modalDescription('Nothing changes for the servers on this egg today — it already resolves this way. Pinning records the decision, so a later change to the detection rules cannot silently move it.')
                    ->action(fn (array $record) => $this->pin($record)),

                Action::make('assign')
                    ->label('Set profile')
                    ->icon('tabler-edit')
                    ->iconButton()
                    ->schema([
                        Select::make('profile')
                            ->label('Profile')
                            ->options(fn (): array => CapabilityProfile::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->placeholder('No profile — hide every page on this egg')
                            ->default(fn (array $record) => $record['profile_id'])
                            ->helperText('Clearing it does not restore the guess: an egg with no mapping falls back to detection, and this egg may or may not be detected.'),
                    ])
                    ->action(fn (array $record, array $data) => $this->assign($record, $data)),
            ])
            ->headerActions([]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggle_all')
                ->label(fn (): string => $this->showAll ? 'Show only Arma eggs' : 'Show every egg')
                ->icon('tabler-list-search')
                ->color('gray')
                ->action(function (): void {
                    $this->showAll = ! $this->showAll;
                    $this->resetTable();
                }),

            Action::make('pin_all')
                ->label('Pin every guess')
                ->icon('tabler-wand')
                ->color('gray')
                ->badge(fn (): ?int => $this->detectedCount() ?: null)
                ->visible(fn (): bool => $this->detectedCount() > 0)
                ->requiresConfirmation()
                ->modalHeading('Pin every detected egg')
                ->modalDescription('Writes down what the heuristic currently guesses for each egg below. Nothing changes for any server today; it stops those eggs depending on detection rules that may change later.')
                ->action(fn () => $this->pinAll()),
        ];
    }

    /**
     * One row per egg, with what it resolves to and how.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        $resolver = app(CapabilityResolver::class);

        // Counted in one grouped query rather than per row. A panel with two
        // hundred eggs would otherwise issue two hundred counts to render a
        // column nobody sorts on.
        $serverCounts = Server::query()
            ->selectRaw('egg_id, COUNT(*) as aggregate')
            ->groupBy('egg_id')
            ->pluck('aggregate', 'egg_id');

        $rows = [];

        foreach (Egg::query()->with('variables')->orderBy('name')->get() as $egg) {
            $profile = $resolver->forEgg($egg);
            $isArma = $resolver->isArmaEgg($egg);

            // An egg that is neither Arma-shaped nor mapped has nothing to say
            // here and is hidden unless asked for. An egg that IS mapped is
            // always shown, however unlikely it looks — somebody chose that.
            if (! $this->showAll && ! $isArma && $profile === null) {
                continue;
            }

            $rows[(string) $egg->id] = [
                'egg_id' => $egg->id,
                'egg' => $egg->name,
                'parent' => $egg->config_from ? ($egg->configFrom?->name) : null,
                'resolves_to' => $profile?->name,
                'profile_id' => $profile?->profileId,
                'source' => $profile?->source,
                'source_label' => $this->sourceLabel($profile, $isArma),
                'source_color' => $this->sourceColor($profile),
                'source_hint' => $this->sourceHint($profile, $isArma),
                'pages' => $profile
                    ? array_map(fn (Capability $capability): string => $capability->getLabel(), $profile->capabilities)
                    : [],
                'servers' => (int) ($serverCounts[$egg->id] ?? 0),
            ];
        }

        return $rows;
    }

    private function sourceLabel(?ResolvedProfile $profile, bool $isArma): string
    {
        return match (true) {
            $profile === null && $isArma => 'Not matched',
            $profile === null => 'Not Arma',
            $profile->source === ResolvedProfile::SOURCE_EXPLICIT => 'Explicit',
            $profile->source === ResolvedProfile::SOURCE_INHERITED => 'Inherited',
            default => 'Detected',
        };
    }

    private function sourceColor(?ResolvedProfile $profile): string
    {
        return match (true) {
            $profile === null => 'gray',
            $profile->source === ResolvedProfile::SOURCE_EXPLICIT => 'success',
            $profile->source === ResolvedProfile::SOURCE_INHERITED => 'info',
            default => 'warning',
        };
    }

    /**
     * The sentence that answers "why does this egg get nothing".
     */
    private function sourceHint(?ResolvedProfile $profile, bool $isArma): string
    {
        if ($profile === null && $isArma) {
            return 'This looks like an Arma 3 egg, but no profile was mapped and detection produced nothing — either detection is switched off in the plugin settings, or no built-in profile matched. Set a profile to give it pages.';
        }

        if ($profile === null) {
            return 'Nothing about this egg says Arma 3 — no matching tag, no Arma in the name, and no Arma app id in its variables. Its servers see none of this plugin. Set a profile if that is wrong.';
        }

        return match ($profile->source) {
            ResolvedProfile::SOURCE_EXPLICIT => 'An administrator mapped this egg to this profile.',
            ResolvedProfile::SOURCE_INHERITED => 'Inherited from the parent egg ' . ($profile->sourceEggName ?? '—') . '. Mapping this egg directly would override that.',
            default => 'Guessed from this egg\'s tags, name and variables. Nothing is written down, so a change to the detection rules could move it. Pin it to fix the decision.',
        };
    }

    private function detectedCount(): int
    {
        return count(array_filter(
            $this->rows(),
            static fn (array $row): bool => $row['source'] === ResolvedProfile::SOURCE_HEURISTIC,
        ));
    }

    /**
     * @param array<string, mixed> $record
     */
    private function pin(array $record): void
    {
        $profile = CapabilityProfile::query()->where('name', $record['resolves_to'])->first();

        if (! $profile) {
            Notification::make()
                ->title('No profile to pin to')
                ->body('The detected profile "' . $record['resolves_to'] . '" has no row in the database. Run the seeder, or create it by hand.')
                ->danger()
                ->send();

            return;
        }

        $this->attach((int) $record['egg_id'], $profile->id);

        Notification::make()->title('Pinned ' . $record['egg'] . ' to ' . $profile->name)->success()->send();
    }

    private function pinAll(): void
    {
        $pinned = 0;

        foreach ($this->rows() as $row) {
            if ($row['source'] !== ResolvedProfile::SOURCE_HEURISTIC) {
                continue;
            }

            $profile = CapabilityProfile::query()->where('name', $row['resolves_to'])->first();

            if ($profile) {
                $this->attach((int) $row['egg_id'], $profile->id);
                $pinned++;
            }
        }

        Notification::make()
            ->title($pinned === 0 ? 'Nothing to pin' : 'Pinned ' . $pinned . ' egg(s)')
            ->success()
            ->send();
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $data
     */
    private function assign(array $record, array $data): void
    {
        $profileId = $data['profile'] ?? null;

        try {
            if (blank($profileId)) {
                EggCapabilityProfile::query()->where('egg_id', $record['egg_id'])->delete();

                $this->flush();

                Notification::make()
                    ->title('Mapping cleared')
                    ->body($record['egg'] . ' now falls back to inheritance and detection, which may give it no pages at all.')
                    ->success()
                    ->send();

                return;
            }

            $profile = CapabilityProfile::query()->find($profileId);

            if (! $profile) {
                Notification::make()->title('That profile no longer exists')->danger()->send();

                return;
            }

            $this->attach((int) $record['egg_id'], $profile->id);

            Notification::make()
                ->title($record['egg'] . ' now uses ' . $profile->name)
                ->body($record['servers'] > 0
                    ? $record['servers'] . ' server(s) on this egg are affected immediately — no restart needed, the pages appear on the next page load.'
                    : 'No servers are on this egg yet.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title('Could not change the mapping')->body($exception->getMessage())->danger()->send();
        }
    }

    /**
     * Replace any existing mapping for this egg.
     *
     * Delete-then-create rather than `updateOrCreate`, because the pivot has no
     * surrogate key and a unique index on `egg_id` alone — one profile per egg
     * is the invariant, and this is the shape that keeps it.
     */
    private function attach(int $eggId, int $profileId): void
    {
        EggCapabilityProfile::query()->where('egg_id', $eggId)->delete();

        EggCapabilityProfile::query()->create([
            'egg_id' => $eggId,
            'a3_capability_profile_id' => $profileId,
        ]);

        $this->flush();
    }

    /**
     * The resolver memoises per egg for the life of the request, and this page
     * both reads and writes mappings — so without this the table re-renders
     * with the answer from before the edit.
     */
    private function flush(): void
    {
        app(CapabilityResolver::class)->flush();

        $this->resetTable();
    }
}
