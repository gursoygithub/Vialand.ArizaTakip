<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            Actions\DeleteAction::make(),
//                ->hidden(fn ($record) =>
//                    $record->id === auth()->user()->id ||
//                    $record->cards()->count() > 0 ||
//                    $record->visitors()->count() > 0 ||
//                    $record->visitorCards()->count() > 0),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Card::make()
                    ->schema([
                        Infolists\Components\Fieldset::make(__('ui.user_info'))
                            ->schema([
                                Infolists\Components\TextEntry::make('tc_no')
                                    ->visible(fn () => auth()->user()->hasRole('super_admin') || auth()->user()->can('view_tc_no'))
                                    ->label(__('ui.tc_no'))
                                    ->icon('heroicon-o-identification')
                                    ->copyable()
                                    ->copyMessage(__('ui.copied_to_clipboard'))
                                    ->placeholder('-'),
                                Infolists\Components\TextEntry::make('name')
                                    ->label(__('ui.full_name'))
                                    ->badge()
                                    ->color('primary')
                                    ->icon('heroicon-o-user')
                                    ->placeholder('-'),
                                Infolists\Components\TextEntry::make('title')
                                    ->label(__('ui.title'))
                                    ->icon('heroicon-o-building-office')
                                    ->placeholder('-'),
                                Infolists\Components\TextEntry::make('profession')
                                    ->label(__('ui.profession'))
                                    ->icon('heroicon-o-briefcase')
                                    ->placeholder('-'),
                                Infolists\Components\TextEntry::make('email')
                                    ->label(__('ui.email'))
                                    ->icon('heroicon-o-envelope')
                                    ->copyable()
                                    ->copyMessage(__('ui.copied_to_clipboard'))
                                    ->placeholder('-'),
                                Infolists\Components\TextEntry::make('phone')
                                    ->label(__('ui.phone'))
                                    ->icon('heroicon-o-phone')
                                    ->copyable()
                                    ->copyMessage(__('ui.copied_to_clipboard'))
                                    ->placeholder('-'),
                                Infolists\Components\TextEntry::make('status')
                                    ->label(__('ui.status'))
                                    ->badge()
                                    ->placeholder('-'),
                            ])->columns(3),
                        Infolists\Components\Fieldset::make(__('ui.record_info'))
                            ->schema([
                                Infolists\Components\TextEntry::make('createdBy.name')
                                    ->label(__('ui.created_by')),
                                Infolists\Components\TextEntry::make('created_at')
                                    ->label(__('ui.created_at'))
                                    ->dateTime(),
                                Infolists\Components\TextEntry::make('updatedBy.name')
                                    ->visible(fn ($record) => filled($record->updated_by)) // Sadece güncelleyen varsa göster
                                    ->label(__('ui.last_updated_by')),
                                Infolists\Components\TextEntry::make('updated_at')
                                    ->visible(fn ($record) => filled($record->updated_by))
                                    ->label(__('ui.last_updated_at'))
                                    ->dateTime(),
                            ])->columns(4),
                    ]),
            ]);
    }
}
