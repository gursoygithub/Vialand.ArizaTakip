<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubcontractorResource\Pages;
use App\Filament\Resources\SubcontractorResource\RelationManagers;
use App\Models\Subcontractor;
use Filament\Forms;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SubcontractorResource extends Resource
{
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $model = Subcontractor::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = -300;

    public static function getModelLabel(): string
    {
        return __('ui.subcontractor_company');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.subcontractor_companies');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.subcontractor_management');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                \Filament\Forms\Components\Card::make()
                    ->schema([
                        Fieldset::make(__('ui.subcontractor_company_information'))
                            ->columns(1)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label(__('ui.name'))
                                    ->placeholder(__('ui.subcontractor_placeholder'))
                                    ->required()
                                    ->maxLength(255)
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
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('active')
                    ->label(__('ui.active'))
                    ->badge(),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->visible(fn () => auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_subcontractors'))
                    ->label(__('ui.created_by'))
                    ->icon('heroicon-o-user')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.created_at'))
                    ->icon('heroicon-o-calendar-days')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                //
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\EmployeesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubcontractors::route('/'),
            //'create' => Pages\CreateSubcontractor::route('/create'),
            'edit' => Pages\EditSubcontractor::route('/{record}/edit'),
            'view' => Pages\ViewSubcontractor::route('/{record}'),
        ];
    }
}
