<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubAreaResource\Pages;
use App\Filament\Resources\SubAreaResource\RelationManagers;
use App\Models\SubArea;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SubAreaResource extends Resource
{
    protected static ?string $model = SubArea::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('ui.sub_area');
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('ui.sub_areas');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.sub_areas');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.task_management');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
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
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->visible(fn () => auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_sub_areas'))
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
                //Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                //
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubAreas::route('/'),
            'create' => Pages\CreateSubArea::route('/create'),
            'edit' => Pages\EditSubArea::route('/{record}/edit'),
        ];
    }
}
