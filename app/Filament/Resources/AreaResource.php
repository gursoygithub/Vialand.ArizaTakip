<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AreaResource\Pages;
use App\Filament\Resources\AreaResource\RelationManagers;
use App\Models\Area;
use Filament\Forms;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class AreaResource extends Resource
{
    protected static ?string $model = Area::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    public static function getModelLabel(): string
    {
        return __('ui.area');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.areas');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.task_management');
    }

    public static function getNavigationBadge(): ?string
    {
        if (auth()->user()?->hasRole('super_admin') || auth()->user()?->can('view_all_areas')) {
            return static::getModel()::count();
        }

        return static::getModel()::where('created_by', auth()->id())->count();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                \Filament\Forms\Components\Card::make()
                    ->schema([
                        Fieldset::make(__('ui.area_information'))
                            ->columns(1)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label(__('ui.name'))
                                    ->placeholder(__('ui.area_placeholder'))
                                    ->required()
                                    ->maxLength(255),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->paginated([5, 10, 25, 50])
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ui.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sub_areas_count')
                    ->label(__('ui.sub_areas'))
                    ->badge()
                    ->color('primary')
                    ->counts('subAreas')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('ui.status'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->visible(fn () => auth()->user()->hasRole('super_admin'))
                    ->label(__('ui.created_by'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.created_at'))
                    ->dateTime(),
                Tables\Columns\TextColumn::make('updatedBy.name')
                    ->label(__('ui.updated_by'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('ui.updated_at'))
                    ->getStateUsing(fn ($record) => $record->updated_by ? $record->updated_at : null)
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make()
                        ->requiresConfirmation()
                        ->visible(fn ($record) => $record->subAreas()->count() === 0)
                        ->action(function ($record) {
                            $record->deleted_by = Auth::id();
                            $record->save();
                            $record->delete();
                        }),
                ])
            ])
            ->bulkActions([
                //
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\SubAreasRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAreas::route('/'),
            'create' => Pages\CreateArea::route('/create'),
            'edit' => Pages\EditArea::route('/{record}/edit'),
            'view' => Pages\ViewArea::route('/{record}')
        ];
    }
}
