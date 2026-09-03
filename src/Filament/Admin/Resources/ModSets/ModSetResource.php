<?php

namespace FyWolf\Arma3Manager\Filament\Admin\Resources\ModSets;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FyWolf\Arma3Manager\Filament\Admin\Resources\ModSets\Pages\ManageModSets;
use FyWolf\Arma3Manager\Models\ModSet;

/**
 * The curated catalogue.
 *
 * A set is a support answer written down once: "ACE, CBA and TFAR, in this
 * order". The order is the valuable half — a customer cannot be expected to
 * know CBA_A3 has to load before ACE, and a set that gets it wrong is a server
 * that does not boot. The repeater below is therefore reorderable and is
 * deliberately not sorted for display.
 */
class ModSetResource extends Resource
{
    protected static ?string $model = ModSet::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-packages';

    protected static ?string $slug = 'arma3-mod-sets';

    public static function getNavigationLabel(): string
    {
        return 'Arma 3 Mod Sets';
    }

    public static function getModelLabel(): string
    {
        return 'Arma 3 mod set';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Arma 3 mod sets';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label('Set')->searchable()->weight('bold'),
                TextColumn::make('key')->label('Key')->badge()->color('gray')->searchable(),

                TextColumn::make('mods')
                    ->label('Mods')
                    ->formatStateUsing(fn ($state): string => (is_array($state) ? count($state) : 0) . ' item(s)'),

                IconColumn::make('is_public')
                    ->label('Public')
                    ->boolean()
                    ->tooltip('Public sets are installable by any customer. Anything else has to be granted by billing.'),

                IconColumn::make('is_enabled')->label('Enabled')->boolean(),

                TextColumn::make('grants_count')
                    ->label('Granted to')
                    ->counts('grants')
                    ->badge()
                    ->color('gray'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No mod sets yet')
            ->emptyStateDescription('Create one to give customers a one-click install of a modset you support.');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The set')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(191),

                    TextInput::make('key')
                        ->required()
                        ->maxLength(191)
                        ->unique(ignoreRecord: true)
                        ->helperText('Stable identifier the billing service uses to grant this set. Changing it after anything has been sold breaks those grants.'),

                    Textarea::make('description')->rows(3)->columnSpanFull(),

                    Toggle::make('is_public')
                        ->label('Installable by anyone')
                        ->helperText('Off means only servers the billing service has granted it to. Off is the default — a set you are still assembling must not be installable the moment you save it.'),

                    Toggle::make('is_enabled')->label('Enabled')->default(true),

                    TextInput::make('sort')->label('Sort order')->numeric()->default(0),
                ]),

            Section::make('Mods, in load order')
                ->description('Arma merges addons in the order given, so a mod that patches another must come after it. Drag to reorder — this order is what a customer\'s server ends up running.')
                ->schema([
                    Repeater::make('mods')
                        ->label('')
                        ->reorderable()
                        ->orderColumn()
                        ->addActionLabel('Add a mod')
                        ->schema([
                            TextInput::make('id')
                                ->label('Workshop id')
                                ->required()
                                ->rule('regex:/^\d{4,20}$/')
                                ->helperText('Digits only, from the workshop URL.'),

                            TextInput::make('folder')
                                ->label('Folder')
                                ->required()
                                ->placeholder('@CBA_A3')
                                ->helperText('The @folder the server loads. This is what goes into -mod=, and it has to match what SteamCMD leaves on disk.'),

                            TextInput::make('name')->label('Label')->placeholder('Community Base Addons'),
                        ])
                        ->columns(3)
                        ->columnSpanFull(),
                ]),

            Section::make('Server-only mods')
                ->description('Loaded through -serverMod= — the server has them and clients neither have nor need them. Usually admin tools and logging.')
                ->collapsed()
                ->schema([
                    Repeater::make('server_mods')
                        ->label('')
                        ->reorderable()
                        ->addActionLabel('Add a server-only mod')
                        ->schema([
                            TextInput::make('id')->label('Workshop id')->rule('regex:/^\d{4,20}$/'),
                            TextInput::make('folder')->label('Folder')->required()->placeholder('@serverTool'),
                            TextInput::make('name')->label('Label'),
                        ])
                        ->columns(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => ManageModSets::route('/'),
        ];
    }
}
