<?php

namespace App\Filament\Resources\SubAreaResource\Pages;

use App\Filament\Resources\SubAreaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSubAreas extends ListRecords
{
    protected static string $resource = SubAreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
