<?php

namespace App\Filament\Resources;

use App\Enums\TaskPriorityEnum;
use App\Filament\Resources\SlaPolicyResource\Pages;
use App\Filament\Resources\SlaPolicyResource\RelationManagers;
use App\Models\SlaPolicy;
use Filament\Forms;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SlaPolicyResource extends Resource
{
    protected static ?string $model = SlaPolicy::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 999;

    public static function getModelLabel(): string
    {
        return __('ui.sla_policy');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.sla_policies');
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
                        Fieldset::make(__('ui.sla_policy_information'))
                            ->columns(1)
                            ->schema([
                                Forms\Components\Select::make('area_id')
                                    ->label(__('ui.area'))
                                    ->options(\App\Models\Area::query()->pluck('name', 'id'))
                                    ->searchable()
                                    ->required()
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                    ]),
                                Forms\Components\Section::make()
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Select::make('priority')
                                            ->label(__('ui.priority'))
                                            ->options([
                                                TaskPriorityEnum::Low->value => TaskPriorityEnum::Low->getLabel(),
                                                TaskPriorityEnum::Medium->value => TaskPriorityEnum::Medium->getLabel(),
                                                TaskPriorityEnum::High->value => TaskPriorityEnum::High->getLabel(),
                                                TaskPriorityEnum::Urgent->value => TaskPriorityEnum::Urgent->getLabel(),
                                            ])
                                            ->searchable()
                                            ->required()
                                            ->validationMessages([
                                                'required' => __('ui.required'),
                                            ]),
                                        Forms\Components\TextInput::make('response_time')
                                            ->label(__('ui.response_time_minutes'))
                                            ->numeric()
                                            ->required()
                                            ->validationMessages([
                                                'required' => __('ui.required'),
                                            ]),
                                        ]),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
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
            'index' => Pages\ListSlaPolicies::route('/'),
            'create' => Pages\CreateSlaPolicy::route('/create'),
            'edit' => Pages\EditSlaPolicy::route('/{record}/edit'),
        ];
    }
}
