<?php

namespace FyWolf\Arma3Manager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Filament\Actions\Action;
use Filament\Facades\Filament;
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
use FyWolf\Arma3Manager\Services\MissionService;
use FyWolf\Arma3Manager\Support\CapabilityResolver;
use FyWolf\Arma3Manager\Support\MissionRotation;
use FyWolf\Arma3Manager\Support\ResolvedProfile;
use Throwable;

/**
 * The mpmissions directory, and the rotation that points into it.
 *
 * Shown together because they fail together. A mission uploaded and never added
 * to the rotation does nothing; a rotation entry whose .pbo was deleted holds
 * the server in the lobby forever, and Arma logs no error for it — it simply
 * never starts. The "In rotation" column is the whole point of the page.
 *
 * Uploading is deliberately delegated to the panel's own file manager rather
 * than reimplemented here. Wings already streams a multi-part upload with
 * resumption and a progress bar; a second implementation would be worse at all
 * three and would be the only place in this plugin handling a large body.
 */
class MissionsPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-map-2';

    protected static string|\UnitEnum|null $navigationGroup = 'Arma 3';

    protected static ?string $slug = 'a3-missions';

    protected static ?int $navigationSort = 23;

    private ?ResolvedProfile $profileMemo = null;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        if (! $server instanceof Server) {
            return false;
        }

        $profile = app(CapabilityResolver::class)->for($server);

        return parent::canAccess()
            && $profile?->has(Capability::Missions)
            && user()?->can(SubuserPermission::FileRead, $server);
    }

    public static function getNavigationLabel(): string
    {
        return trans('arma3-manager::strings.nav.missions');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        abort_unless(user()?->can(SubuserPermission::FileRead, $this->server()), 403);

        $this->profileMemo = app(CapabilityResolver::class)->for($this->server());

        abort_unless($this->profileMemo?->has(Capability::Missions), 403);
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

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                $missions = app(MissionService::class);
                $server = $this->server();
                $profile = $this->profile();

                $rotation = array_map(
                    static fn (array $entry): string => strtolower($entry['template']),
                    $missions->rotation($server, $profile)->all(),
                );

                $records = [];

                foreach ($missions->list($server, $profile) as $mission) {
                    $template = MissionRotation::template($mission['name']);
                    $inRotation = in_array(strtolower($template), $rotation, true);

                    $records[$mission['name']] = [
                        'name' => $mission['name'],
                        'template' => $template,
                        'size' => $mission['size'],
                        'in_rotation' => $inRotation,
                        'rotation_label' => $inRotation ? 'In rotation' : 'Not scheduled',
                        'rotation_color' => $inRotation ? 'success' : 'gray',
                    ];
                }

                // Rotation entries with no file. Rendered as rows rather than
                // hidden, because this is the failure the page exists to make
                // visible: a rotation pointing at a mission nobody uploaded.
                foreach ($missions->orphanedRotationEntries($server, $profile) as $orphan) {
                    $records['missing:' . $orphan] = [
                        'name' => $orphan . ' (file missing)',
                        'template' => $orphan,
                        'size' => 0,
                        'in_rotation' => true,
                        'rotation_label' => 'Scheduled, not uploaded',
                        'rotation_color' => 'danger',
                    ];
                }

                return $records;
            })
            ->columns([
                TextColumn::make('name')->label('Mission')->weight('bold')->searchable(),
                TextColumn::make('template')->label('Template')->color('gray'),
                TextColumn::make('size')
                    ->label('Size')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? number_format($state / 1048576, 1) . ' MB' : '—'),
                TextColumn::make('rotation_label')
                    ->label('Rotation')
                    ->badge()
                    ->color(fn (array $record): string => $record['rotation_color']),
            ])
            ->paginated(false)
            ->emptyStateHeading('No missions uploaded')
            ->emptyStateDescription('Upload a .pbo into mpmissions through the file manager, then schedule it here.')
            ->recordActions([
                Action::make('schedule')
                    ->label(fn (array $record): string => $record['in_rotation'] ? 'Unschedule' : 'Schedule')
                    ->icon(fn (array $record): string => $record['in_rotation'] ? 'tabler-calendar-minus' : 'tabler-calendar-plus')
                    ->iconButton()
                    ->visible(fn (): bool => user()?->can(SubuserPermission::FileUpdate, $this->server()) ?? false)
                    ->schema(fn (array $record): array => $record['in_rotation'] ? [] : [
                        Select::make('difficulty')
                            ->label('Difficulty')
                            ->options([
                                'recruit' => 'Recruit',
                                'regular' => 'Regular',
                                'veteran' => 'Veteran',
                                'custom' => 'Custom (from the server profile)',
                            ])
                            ->default('regular')
                            ->required(),
                    ])
                    ->action(fn (array $record, array $data) => $this->toggleRotation($record, $data)),

                Action::make('delete')
                    ->label('Delete')
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->iconButton()
                    ->visible(fn (array $record): bool => $record['size'] > 0
                        && (user()?->can(SubuserPermission::FileDelete, $this->server()) ?? false))
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => 'Delete ' . $record['name'] . '?')
                    ->modalDescription('The archive is removed from the server. If it is in the rotation it is taken out of that too, since a rotation entry with no file holds the server in the lobby.')
                    ->action(fn (array $record) => $this->delete($record)),
            ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getDefaultHeaderActions(): array
    {
        return [
            Action::make('upload')
                ->label('Upload a mission')
                ->icon('tabler-upload')
                ->color('primary')
                ->url(fn () => ListFiles::getUrl(['path' => '/' . ($this->profile()->missionsDir() ?? 'mpmissions')]), true),
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $data
     */
    private function toggleRotation(array $record, array $data): void
    {
        $server = $this->server();

        if (! user()?->can(SubuserPermission::FileUpdate, $server)) {
            Notification::make()->title(trans('arma3-manager::strings.permission_denied'))->danger()->send();

            return;
        }

        try {
            $missions = app(MissionService::class);
            $profile = $this->profile();
            $template = (string) $record['template'];

            $entries = $missions->rotation($server, $profile)->all();

            if ($record['in_rotation']) {
                $entries = array_values(array_filter(
                    $entries,
                    static fn (array $entry): bool => strcasecmp($entry['template'], $template) !== 0,
                ));
            } else {
                $entries[] = ['template' => $template, 'difficulty' => (string) ($data['difficulty'] ?? 'regular')];
            }

            $missions->saveRotation($server, $profile, MissionRotation::fromArray($entries));

            Activity::event('server:arma3.mission-rotation')
                ->property(['mission' => $template, 'scheduled' => ! $record['in_rotation']])
                ->log();

            Notification::make()
                ->title($record['in_rotation'] ? 'Taken out of the rotation' : 'Added to the rotation')
                ->body('server.cfg is read at startup, so restart for it to take effect.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title('Could not update the rotation')->body($exception->getMessage())->danger()->send();
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function delete(array $record): void
    {
        $server = $this->server();

        if (! user()?->can(SubuserPermission::FileDelete, $server)) {
            Notification::make()->title(trans('arma3-manager::strings.permission_denied'))->danger()->send();

            return;
        }

        try {
            $missions = app(MissionService::class);
            $profile = $this->profile();

            $missions->delete($server, $profile, (string) $record['name']);

            // Taken out of the rotation in the same action. Leaving it there
            // would leave the server pointed at a mission that no longer
            // exists, which is the exact silent failure this page is about.
            if ($record['in_rotation'] && (user()?->can(SubuserPermission::FileUpdate, $server) ?? false)) {
                $template = (string) $record['template'];

                $entries = array_values(array_filter(
                    $missions->rotation($server, $profile)->all(),
                    static fn (array $entry): bool => strcasecmp($entry['template'], $template) !== 0,
                ));

                $missions->saveRotation($server, $profile, MissionRotation::fromArray($entries));
            }

            Activity::event('server:arma3.mission-delete')->property(['mission' => $record['name']])->log();

            Notification::make()->title('Mission deleted')->success()->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title('Could not delete the mission')->body($exception->getMessage())->danger()->send();
        }
    }
}
