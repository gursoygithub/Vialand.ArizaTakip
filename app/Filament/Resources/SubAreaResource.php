<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubAreaResource\Pages;
use App\Filament\Resources\SubAreaResource\RelationManagers;
use App\Models\SubArea;
use Filament\Forms;
use Filament\Forms\Components\Fieldset;
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

    //protected static bool $shouldRegisterNavigation = false;

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

    public static function getNavigationBadge(): ?string
    {
        if (auth()->user()?->hasRole('super_admin') || auth()->user()?->can('view_all_sub_areas')) {
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
                        Fieldset::make(__('ui.sub_area_information'))
                            ->columns(2)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label(__('ui.name'))
                                    ->placeholder(__('ui.sub_area_placeholder'))
                                    ->required()
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                    ]),
                                Forms\Components\Select::make('area_id')
                                    ->label(__('ui.area'))
                                    ->relationship('area', 'name')
                                    ->preload()
                                    ->searchable()
                                    ->required()
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                    ]),
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
                    ->icon('heroicon-o-map-pin')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('area.name')
                    ->label(__('ui.area'))
                    ->icon('heroicon-o-map')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->visible(fn () => auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_sub_areas'))
                    ->label(__('ui.created_by'))
                    ->icon('heroicon-o-user')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.created_at'))
                    ->icon('heroicon-o-calendar-days')
                    ->dateTime(),
                Tables\Columns\TextColumn::make('updatedBy.name')
                    ->label(__('ui.updated_by'))
                    ->icon('heroicon-o-user')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('ui.updated_at'))
                    ->icon('heroicon-o-calendar-days')
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
                    Tables\Actions\EditAction::make()
                        ->mutateFormDataUsing(function (array $data): array {
                            $data['updated_by'] = auth()->user()->id;
                            return $data;
                        }),
                    Tables\Actions\DeleteAction::make()
                        ->requiresConfirmation()
                        ->action(function (Model $record) {
                            $record->deleted_by = auth()->user()->id;
                            $record->deleted_at = now();
                            $record->save();
                            $record->delete();
                        }
                    ),
                ])
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
            //'create' => Pages\CreateSubArea::route('/create'),
            //'edit' => Pages\EditSubArea::route('/{record}/edit'),
            //'view' => Pages\ViewSubArea::route('/{record}')
        ];
    }

    public static function canDelete(Model $record): bool
    {
        return $record->tasks()->count() === 0 && (auth()->user()->hasRole('super_admin') || $record->created_by === auth()->user()->id);
    }
}
