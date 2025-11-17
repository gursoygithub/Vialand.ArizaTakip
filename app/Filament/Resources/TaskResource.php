<?php

namespace App\Filament\Resources;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\TaskTypeEnum;
use App\Filament\Resources\TaskResource\Pages;
use App\Filament\Resources\TaskResource\RelationManagers;
use App\Models\Area;
use App\Models\SubArea;
use App\Models\Task;
use App\Models\Unit;
use Filament\Forms;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    public static function getModelLabel(): string
    {
        return __('ui.task');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.tasks');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.task_management');
    }

    public static function getNavigationBadge(): ?string
    {
        if (auth()->user()?->hasRole('super_admin') || auth()->user()?->can('view_all_tasks')) {
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
                        Forms\Components\TextInput::make('title')
                            ->label(__('ui.task_title'))
                            ->placeholder(__('ui.task_placeholder'))
                            ->required()
                            ->validationMessages([
                                'required' => __('ui.required'),
                            ]),
                        Fieldset::make(__('ui.task_information'))
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
                                    ->label(__('ui.unit'))
                                    ->options(Unit::query()->pluck('name', 'id'))
                                    ->preload()
                                    ->searchable()
                                    ->required()
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                    ]),
                                Forms\Components\Select::make('type_id')
                                    ->label(__('ui.type'))
                                    ->options(
                                        collect(TaskTypeEnum::cases())
                                            ->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()])
                                            ->toArray()
                                    )
                                    ->required()
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                    ]),
                                Forms\Components\Select::make('employee_id')
                                    ->label(__('ui.assigned_to'))
                                    ->options(
                                        \App\Models\Employee::all()
                                            ->where('status', ActiveStatusEnum::ACTIVE)
                                            ->pluck('name', 'id')
                                    )
//                                    ->options(function () {
//                                        return DB::connection('sqlsrv2')
//                                            ->table('dbo._TGRY_PERSONEL')
//                                            ->where('AKTIF_MI', 1)
//                                            ->where('VERITABANI_ADI' , '=', 'VIALAND_EGLENCE')
//                                            ->selectRaw("UNIQUE_ID as id, CONCAT(ADI, ' ', SOYADI) as name")
//                                            ->orderBy('name')
//                                            ->pluck('name', 'id')
//                                            ->toArray();
//                                    })
                                    ->preload()
                                    ->searchable()
                                    ->required()
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                    ]),
                                Forms\Components\DatePicker::make('task_date')
                                    ->label(__('ui.date'))
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn (callable $set) => $set('due_date', null))
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                    ]),
                                Forms\Components\Textarea::make('description')
                                    ->label(__('ui.description'))
                                    ->rows(3)
                                    ->columnSpan(3)
                                    ->placeholder(__('ui.task_description_placeholder'))
                                    ->required()
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                    ]),
                                Fieldset::make(__('ui.image'))
                                    ->hiddenLabel()
                                    ->columns(1)
                                    ->schema([
                                        Forms\Components\SpatieMediaLibraryFileUpload::make('images')
                                            ->label(__('ui.images'))
                                            ->helperText(__('ui.task_photo_helper_text'))
                                            ->collection('task_attachments')
                                            ->downloadable()
                                            ->openable()
                                            ->maxFiles(10)
                                            ->maxSize(10240) // 10 MB
                                            ->image()
                                            ->required()
                                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
                                            ->maxSize(1024 * 5) // Maksimum 5MB örnek
                                            ->validationMessages([
                                                'required' => __('ui.required'),
                                                'accepted_file_types' => __('ui.invalid_file_type'),
                                                'max' => __('ui.max_files_exceeded', ['max' => 10]),
                                                'file' => __('ui.file_upload_error'),
                                            ])
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Fieldset::make(__('ui.resolution_information'))
                            ->visibleOn(['edit', 'view'])
                            ->columns(2)
                            ->schema([
                                Forms\Components\Select::make('status')
                                    ->label(__('ui.status'))
                                    ->options([
                                        TaskStatusEnum::PENDING->value => TaskStatusEnum::PENDING->getLabel(),
                                        TaskStatusEnum::COMPLETED->value => TaskStatusEnum::COMPLETED->getLabel(),
                                        TaskStatusEnum::WINTER_MAINTENANCE->value => TaskStatusEnum::WINTER_MAINTENANCE->getLabel(),
                                    ])
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->afterStateUpdated(fn (callable $set) => $set('due_date', null))
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                    ]),
                                Forms\Components\DatePicker::make('due_date')
                                    ->hidden(fn ($get) => $get('status') != TaskStatusEnum::COMPLETED->value)
                                    ->label(__('ui.due_date'))
                                    ->minDate(fn ($get) => $get('task_date'))
                                    ->afterOrEqual('task_date')
                                    ->required()
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                        'after_or_equal' => __('ui.due_date_after_or_equal_task_date'),
                                    ]),
                                Forms\Components\Textarea::make('resolution_notes')
                                    ->hidden(fn ($get) => $get('status') != TaskStatusEnum::COMPLETED->value)
                                    ->label(__('ui.resolution_notes'))
                                    ->placeholder(__('ui.resolution_placeholder'))
                                    ->requiredWith('due_date')
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                    ])->columnSpanFull(),
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
                Tables\Columns\SpatieMediaLibraryImageColumn::make('images')
                    ->label(__('ui.images'))
                    ->collection('task_photos')
                    ->square()
                    ->size(50),
                Tables\Columns\TextColumn::make('title')
                    ->label(__('ui.task_title'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('area.name')
                    ->label(__('ui.area'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('subArea.name')
                    ->label(__('ui.sub_area'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('unit.name')
                    ->label(__('ui.unit'))
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type_id')
                    ->label(__('ui.type'))
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('employee.name')
                    ->label(__('ui.assigned_to'))
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('ui.status'))
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('due_date')
                    ->label(__('ui.due_date'))
                    ->date()
                    ->badge()
                    ->color('success')
//                    ->colors([
//                        'warning' => fn ($state): bool => $state === TaskStatusEnum::PENDING->value,
//                        'success' => fn ($state): bool => $state === TaskStatusEnum::COMPLETED->value,
//                        'primary' => fn ($state): bool => $state === TaskStatusEnum::WINTER_MAINTENANCE->value,
//                    ])
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
                // filter by status
//                Tables\Filters\SelectFilter::make('status')
//                    ->label(__('ui.status'))
//                    ->options([
//                        TaskStatusEnum::PENDING->value => TaskStatusEnum::PENDING->getLabel(),
//                        TaskStatusEnum::COMPLETED->value => TaskStatusEnum::COMPLETED->getLabel(),
//                        TaskStatusEnum::WINTER_MAINTENANCE->value => TaskStatusEnum::WINTER_MAINTENANCE->getLabel(),
//                    ]),
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
            'index' => Pages\ListTasks::route('/'),
            'create' => Pages\CreateTask::route('/create'),
            'edit' => Pages\EditTask::route('/{record}/edit'),
            'view' => Pages\ViewTask::route('/{record}'),
        ];
    }
}
