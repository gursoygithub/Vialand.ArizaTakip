<?php

namespace App\Filament\Resources\SubcontractorResource\RelationManagers;

use App\Enums\ActiveStatusEnum;
use App\Models\Subcontractor;
use Filament\Forms;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EmployeesRelationManager extends RelationManager
{
    protected static string $relationship = 'employees';

    /**
     * @return string|null
     */
    public static function getModelLabel(): ?string
    {
        return __('ui.subcontractor_employee');
    }

    /**
     * @return string|null
     */
    public static function getPluralModelLabel(): ?string
    {
        return __('ui.subcontractor_employees');
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('ui.subcontractor_employees');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                \Filament\Forms\Components\Card::make()
                    ->schema([
                        Fieldset::make(__('ui.subcontractor_employee_information'))
                            ->columns(1)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label(__('ui.name'))
                                    ->placeholder(__('ui.subcontractor_employee_placeholder'))
                                    ->required()
                                    ->maxLength(255)
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

    public function table(Table $table): Table
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
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->visible(fn () => auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_subcontractor_employees'))
                    ->label(__('ui.created_by'))
                    ->icon('heroicon-o-user')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.created_at'))
                    ->icon('heroicon-o-calendar-days')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('updatedBy.name')
                    ->label(__('ui.updated_by'))
                    ->icon('heroicon-o-user')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('ui.updated_at'))
                    ->icon('heroicon-o-calendar-days')
                    ->getStateUsing(fn ($record) => $record->updated_by ? $record->updated_at : null)
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])->headerActions([
                Tables\Actions\CreateAction::make(),
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

    public function isReadOnly(): bool
    {
        return false;
    }
}
