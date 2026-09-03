<?php

namespace FyWolf\Arma3Manager\Filament\Admin\Resources\ModSets\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use FyWolf\Arma3Manager\Filament\Admin\Resources\ModSets\ModSetResource;

class ManageModSets extends ManageRecords
{
    protected static string $resource = ModSetResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
