<?php

namespace FyWolf\Arma3Manager\Filament\Admin\Resources\CapabilityProfiles;

use App\Models\Egg;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FyWolf\Arma3Manager\Enums\Capability;
use FyWolf\Arma3Manager\Enums\ServerFlavour;
use FyWolf\Arma3Manager\Filament\Admin\Resources\CapabilityProfiles\Pages\ManageCapabilityProfiles;
use FyWolf\Arma3Manager\Models\CapabilityProfile;

/**
 * Which eggs get which pages, and where their files live.
 *
 * The whole gating model in one screen. An egg with no profile — explicit or
 * inherited or detected — shows none of this plugin's pages at all, which is
 * the correct outcome for a custom egg nobody has described.
 */
class CapabilityProfileResource extends Resource
{
    protected static ?string $model = CapabilityProfile::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-target-arrow';

    protected static ?string $slug = 'arma3-profiles';

    public static function getNavigationLabel(): string
    {
        return 'Arma 3 Profiles';
    }

    public static function getModelLabel(): string
    {
        return 'Arma 3 profile';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Arma 3 profiles';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Profile')->searchable(),

                TextColumn::make('flavour')
                    ->label('Kind')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? (ServerFlavour::tryFrom($state)?->getLabel() ?? $state) : '—'),

                TextColumn::make('capabilities')
                    ->label('Grants')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Capability::tryFrom($state)?->getLabel() ?? $state),

                TextColumn::make('mods_dir')
                    ->label('Mods in')
                    ->placeholder('no mod management')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('eggs.name')
                    ->label('Eggs')
                    ->badge()
                    ->icon('tabler-eggs')
                    ->placeholder('not mapped to any egg')
                    ->limitList(4)
                    ->expandableLimitedList(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No profiles yet')
            ->emptyStateDescription('Run the seeder, or create one by hand for a community build with its own directory layout.');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What this profile is')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(191)->unique(ignoreRecord: true),

                    Select::make('flavour')
                        ->label('Kind')
                        ->options(collect(ServerFlavour::cases())
                            ->mapWithKeys(fn (ServerFlavour $flavour) => [$flavour->value => $flavour->getLabel()])
                            ->all())
                        ->helperText('Only used as a default and for egg detection. The capabilities below are what actually decides what a server sees.')
                        ->live(),

                    CheckboxList::make('capabilities')
                        ->label('Pages this grants')
                        ->options(collect(Capability::cases())
                            ->mapWithKeys(fn (Capability $capability) => [$capability->value => $capability->getLabel()])
                            ->all())
                        ->descriptions(collect(Capability::cases())
                            ->mapWithKeys(fn (Capability $capability) => [$capability->value => $capability->getDescription()])
                            ->all())
                        ->columns(2)
                        ->columnSpanFull()
                        ->required(),
                ]),

            Section::make('Where the files are')
                ->description('Paths relative to the server root. Leave one blank to switch that feature off for this profile — a headless client has no mpmissions, and offering it one is worse than offering nothing.')
                ->columns(2)
                ->schema([
                    TextInput::make('mods_dir')->label('Mods directory')->placeholder('mods'),
                    TextInput::make('servermods_dir')->label('Server-only mods directory')->placeholder('servermods'),
                    TextInput::make('missions_dir')->label('Missions directory')->placeholder('mpmissions'),
                    TextInput::make('profiles_dir')->label('Server profiles directory')->placeholder('profiles'),
                    TextInput::make('server_binary')->label('Server binary')->placeholder('arma3server_x64'),

                    TagsInput::make('config_files')
                        ->label('Configuration files')
                        ->placeholder('server.cfg')
                        ->helperText('In the order the Configuration page offers them. Empty hides that page.')
                        ->columnSpanFull(),
                ]),

            Section::make('Startup variables')
                ->description('Ordered candidates — the first one that exists on a server wins. Eggs disagree about these names, and writing to a variable the egg never declared fails completely silently: the panel records the mod list and the game never sees it.')
                ->columns(2)
                ->schema([
                    TagsInput::make('mod_list_variables')->label('Mod list')->placeholder('MODS'),
                    TagsInput::make('servermod_list_variables')->label('Server-only mod list')->placeholder('SERVERMODS'),
                    TagsInput::make('parameter_variables')->label('Extra parameters')->placeholder('STARTUP_PARAMS'),
                    TagsInput::make('headless_variables')->label('Headless client count')->placeholder('HC_NUM'),
                ]),

            Section::make('Eggs')
                ->schema([
                    Select::make('eggs')
                        ->label('Mapped eggs')
                        ->relationship('eggs', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->helperText('An egg maps to at most one profile. A child egg with no mapping of its own inherits its parent\'s.')
                        ->options(fn () => Egg::query()->orderBy('name')->pluck('name', 'id')->all()),
                ]),
        ]);
    }

    /**
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => ManageCapabilityProfiles::route('/'),
        ];
    }
}
