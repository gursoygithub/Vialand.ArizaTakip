<?php

namespace App\Filament\Resources;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Filament\Resources\SlaPolicyResource\Pages;
use App\Filament\Resources\SlaPolicyResource\RelationManagers;
use App\Models\Area;
use App\Models\SlaPolicy;
use App\Models\SubArea;
use App\Models\Unit;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\ToggleButtons;
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

    public static function getNavigationBadge(): ?string
    {
        if (auth()->user()?->hasRole('super_admin') || auth()->user()?->can('view_all_sla_policies')) {
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
                        Fieldset::make(__('ui.sla_policy_information'))
                            ->columns(3)
                            ->schema([
                                Forms\Components\Select::make('area_id')
                                    ->label(__('ui.area'))
                                    ->options(
                                        Area::all()
                                            ->where('status', ActiveStatusEnum::ACTIVE)
                                            ->pluck('name', 'id')
                                    )
                                    ->preload()
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn (callable $set) => $set('sub_area_id', null))
                                    //->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                    ])
                                    ->when(auth()->user()->hasRole('super_admin') || auth()->user()->can('create_custom_area', Area::class), fn ($select) => $select->createOptionForm(function ($form) {
                                        $form
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
                                        return $form->model(\App\Models\Area::class);
                                    })->createOptionUsing(function ($data) {
                                        $location = \App\Models\Area::create([
                                            'name' => $data['name'],
                                            'status' => ActiveStatusEnum::ACTIVE,
                                            'created_by' => auth()->id(),
                                        ]);
                                        return $location->id;
                                    })),
                                Forms\Components\Select::make('sub_area_id')
                                    ->label(__('ui.sub_area'))
                                    ->options(function (callable $get) {
                                        $areaId = $get('area_id');
                                        if (!$areaId) {
                                            return [];
                                        }

                                        return SubArea::all()
                                            ->where('area_id', $areaId)
                                            ->pluck('name', 'id');
                                    })
                                    ->preload()
                                    ->searchable()
                                    ->required()
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                    ])
                                    ->when(auth()->user()->hasRole('super_admin') || auth()->user()->can('create_custom_sub_area', SubArea::class), fn ($select) => $select->createOptionForm(function ($form) {
                                        $form
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
                                        return $form->model(\App\Models\SubArea::class);
                                    })->createOptionUsing(function (callable $get, $data) {
                                        $area = SubArea::create([
                                            'area_id' => $data['area_id'],
                                            'name' => $data['name'],
                                            'created_by' => auth()->id(),
                                        ]);
                                        return $area->id;
                                    })),
                                Forms\Components\Select::make('unit_id')
                                    ->label(__('ui.technical_unit'))
                                    ->options(Unit::query()->pluck('name', 'id'))
                                    ->live()
                                    ->afterStateUpdated(fn (callable $set) => $set('employee_id', null))
                                    ->preload()
                                    ->searchable()
                                    ->required()
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                    ]),
                                Forms\Components\Section::make()
                                    ->columns(2)
                                    ->schema([
                                        ToggleButtons::make('priority')
                                            ->label(__('ui.priority'))
                                            ->options([TaskPriorityEnum::Low->value => TaskPriorityEnum::Low->getLabel(),
                                                TaskPriorityEnum::Medium->value => TaskPriorityEnum::Medium->getLabel(),
                                                TaskPriorityEnum::High->value => TaskPriorityEnum::High->getLabel(),
                                                TaskPriorityEnum::Urgent->value => TaskPriorityEnum::Urgent->getLabel(),
                                            ])
                                            ->icons([
                                                TaskPriorityEnum::Low->value => TaskPriorityEnum::Low->getIcon(),
                                                TaskPriorityEnum::Medium->value => TaskPriorityEnum::Medium->getIcon(),
                                                TaskPriorityEnum::High->value => TaskPriorityEnum::High->getIcon(),
                                                TaskPriorityEnum::Urgent->value => TaskPriorityEnum::Urgent->getIcon(),
                                            ])
                                            ->colors([
                                                TaskPriorityEnum::Low->value => TaskPriorityEnum::Low->getColor(),
                                                TaskPriorityEnum::Medium->value => TaskPriorityEnum::Medium->getColor(),
                                                TaskPriorityEnum::High->value => TaskPriorityEnum::High->getColor(),
                                                TaskPriorityEnum::Urgent->value => TaskPriorityEnum::Urgent->getColor(),
                                            ])
                                            ->inline()
                                            ->default(TaskPriorityEnum::Medium->value)
                                            ->required()
                                            ->unique(
                                                table: SlaPolicy::class,
                                                column: 'priority',
                                                ignoreRecord: true,
                                                modifyRuleUsing: function ($rule, callable $get) {
                                                    return $rule
                                                        ->where('area_id', $get('area_id'))
                                                        ->where('sub_area_id', $get('sub_area_id'))
                                                        ->where('unit_id', $get('unit_id'))
                                                        ->whereNull('deleted_at');
                                                }
                                            )
                                            ->validationMessages([
                                                'required' => __('ui.required'),
                                                'unique' => __('ui.priority_unique_per_area_sub_area_unit'),
                                            ]),
                                        Forms\Components\TextInput::make('deadline_minutes')
                                            ->label(__('ui.resolution_time_minutes'))
                                            ->placeholder(__('ui.resolution_time_minutes_placeholder'))
                                            ->numeric()
                                            ->minValue(1)
                                            ->required()
                                            ->validationMessages([
                                                'required' => __('ui.required'),
                                            ]),
                                            Forms\Components\TextInput::make('success_threshold')
                                                ->label(__('ui.success_threshold_percentage'))
                                                ->placeholder(__('ui.success_threshold_percentage_placeholder'))
                                                ->numeric()
                                                ->prefix('%')
                                                ->minValue(1)
                                                ->maxValue(100)
                                                ->default(80)
                                                ->required()
                                                ->validationMessages([
                                                    'required' => __('ui.required'),
                                                    'min_value' => __('ui.success_threshold_min_value'),
                                                    'max_value' => __('ui.success_threshold_max_value')
                                                    ]
                                                ),
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
                Tables\Columns\TextColumn::make('area.name')
                    ->label(__('ui.area'))
                    ->icon('heroicon-o-map')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('subArea.name')
                    ->label(__('ui.sub_area'))
                    ->icon('heroicon-o-map-pin')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('unit.name')
                    ->label(__('ui.technical_unit'))
                    ->icon('heroicon-o-building-office')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('priority')
                    ->label(__('ui.priority'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('deadline_minutes')
                    ->label(__('ui.resolution_time_minutes'))
                    ->icon('heroicon-o-clock')
                    ->badge()
                    ->alignCenter()
                    ->sortable(),
                Tables\Columns\TextColumn::make('success_threshold')
                    ->label(__('ui.success_threshold_percentage'))
                    ->icon('heroicon-o-shield-check')
                    ->badge()
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => "% " . $state)
                    ->sortable(),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->visible(fn () => auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_sla_policies'))
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
                // filter by area
                Tables\Filters\SelectFilter::make('area_id')
                    ->label(__('ui.area'))
                    ->options(Area::all()->pluck('name', 'id'))
                    ->searchable(),
                // filter by sub area
                Tables\Filters\SelectFilter::make('sub_area_id')
                    ->label(__('ui.sub_area'))
                    ->options(SubArea::all()->pluck('name', 'id'))
                    ->searchable(),
                // filter by unit
                Tables\Filters\SelectFilter::make('unit_id')
                    ->label(__('ui.technical_unit'))
                    ->options(Unit::all()->pluck('name', 'id'))
                    ->searchable(),
                // filter by priority
                Tables\Filters\SelectFilter::make('priority')
                    ->label(__('ui.priority'))
                    ->options([
                        TaskPriorityEnum::Low->value => TaskPriorityEnum::Low->getLabel(),
                        TaskPriorityEnum::Medium->value => TaskPriorityEnum::Medium->getLabel(),
                        TaskPriorityEnum::High->value => TaskPriorityEnum::High->getLabel(),
                        TaskPriorityEnum::Urgent->value => TaskPriorityEnum::Urgent->getLabel(),
                    ])
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make()
                        ->requiresConfirmation(),
                ]),
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
            'index' => Pages\ListSlaPolicies::route('/'),
            'create' => Pages\CreateSlaPolicy::route('/create'),
            'edit' => Pages\EditSlaPolicy::route('/{record}/edit'),
            'view' => Pages\ViewSlaPolicy::route('/{record}'),
        ];
    }
}
