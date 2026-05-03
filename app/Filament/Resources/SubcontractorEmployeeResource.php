<?php

namespace App\Filament\Resources;

use App\Enums\ActiveStatusEnum;
use App\Filament\Resources\SubcontractorEmployeeResource\Pages;
use App\Filament\Resources\SubcontractorEmployeeResource\RelationManagers;
use App\Models\Subcontractor;
use App\Models\SubcontractorEmployee;
use Filament\Forms;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SubcontractorEmployeeResource extends Resource
{
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $model = SubcontractorEmployee::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    //protected static ?int $navigationSort = -200;

    public static function getModelLabel(): string
    {
        return __('ui.subcontractor_employee');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.subcontractor_employees');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.panel_management');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                \Filament\Forms\Components\Card::make()
                    ->schema([
                        Fieldset::make(__('ui.subcontractor_employee_information'))
                            ->columns(2)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label(__('ui.name'))
                                    ->placeholder(__('ui.subcontractor_employee_placeholder'))
                                    ->required()
                                    ->maxLength(255)
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                    ]),
                                Forms\Components\Select::make('subcontractor_id')
                                    ->label(__('ui.subcontractor_company'))
                                    ->options(Subcontractor::query()
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                    )
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                    ]),
                                Forms\Components\ToggleButtons::make('active')
                                    ->label(__('ui.status'))
                                    ->hiddenOn('create')
                                    ->columns(1)
                                    ->options([
                                        ActiveStatusEnum::ACTIVE->value => ActiveStatusEnum::ACTIVE->getLabel(),
                                        ActiveStatusEnum::INACTIVE->value => ActiveStatusEnum::INACTIVE->getLabel(),
                                    ])
                                    ->colors([
                                        ActiveStatusEnum::ACTIVE->value => ActiveStatusEnum::ACTIVE->getColor(),
                                        ActiveStatusEnum::INACTIVE->value => ActiveStatusEnum::INACTIVE->getColor(),
                                    ])
                                    ->icons([
                                        ActiveStatusEnum::ACTIVE->value => ActiveStatusEnum::ACTIVE->getIcon(),
                                        ActiveStatusEnum::INACTIVE->value => ActiveStatusEnum::INACTIVE->getIcon(),
                                    ])
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
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ui.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('active')
                    ->label(__('ui.status'))
                    ->badge(),
                Tables\Columns\TextColumn::make('subcontractor.name')
                    ->label(__('ui.subcontractor_company'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->visible(fn () => auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_subcontractor_employees'))
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
            'index' => Pages\ListSubcontractorEmployees::route('/'),
            'create' => Pages\CreateSubcontractorEmployee::route('/create'),
            'edit' => Pages\EditSubcontractorEmployee::route('/{record}/edit'),
            //'view' => Pages\ViewSubcontractorEmployee::route('/{record}'),
        ];
    }
}
