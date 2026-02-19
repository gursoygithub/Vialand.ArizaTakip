<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use Filament\Actions;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Card::make(__('ui.employee'))
                    ->icon('heroicon-o-user-circle')
                    ->description(__('ui.employee_details'))
                    ->columns(3)
                    ->compact()
                    ->schema([
                        TextEntry::make('name')
                            ->label(__('ui.name'))
                            ->helperText(fn($record): ?string => $record->title ?? '-')
                            ->icon('heroicon-o-user')
                            ->size('lg')
                            ->weight('bold')
                            ->copyable()
                            ->copyMessage(__('ui.copied')),

                        TextEntry::make('email')
                            ->label(__('ui.email'))
                            ->icon('heroicon-o-envelope')
                            ->copyable()
                            ->copyMessage(__('ui.copied')),

                        TextEntry::make('phone')
                            ->label(__('ui.phone'))
                            ->icon('heroicon-o-phone')
                            ->placeholder('—')
                            ->copyable()
                            ->copyMessage(__('ui.copied')),
                    ]),
            ]);
        }
}
