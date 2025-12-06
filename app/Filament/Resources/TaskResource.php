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
use App\Notifications\TaskClosed;
use Filament\Forms;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
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
                            ->hidden()
                            ->label(__('ui.task_title'))
                            ->placeholder(__('ui.task_placeholder'))
                            ->required()
                            ->validationMessages([
                                'required' => __('ui.required'),
                            ]),
                        Fieldset::make(__('ui.task_information'))
                            ->columns(3)
                            ->schema([
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
                                Forms\Components\Select::make('employee_id')
                                    ->hidden()
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
                                    ->label(__('ui.fault_date'))
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn (callable $set) => $set('due_date', null))
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                    ]),
                                Fieldset::make(__('ui.descriptions'))
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Textarea::make('description')
                                            ->label(__('ui.task_description'))
                                            ->rows(3)
                                            ->placeholder(__('ui.task_description_placeholder'))
                                            ->required()
                                            ->validationMessages([
                                                'required' => __('ui.required'),
                                            ]),
                                        Forms\Components\Textarea::make('unit_description')
                                            ->visibleOn('edit')
                                            ->label(__('ui.unit_description'))
                                            ->rows(3)
                                            ->label(__('ui.unit_description'))
                                            ->placeholder(__('ui.unit_description_placeholder')),
                                    ]),
                                Fieldset::make(__('ui.image'))
                                    ->hiddenLabel()
                                    ->columns(1)
                                    ->schema([
                                        Forms\Components\SpatieMediaLibraryFileUpload::make('images')
                                            ->label(__('ui.images'))
                                            ->helperText(__('ui.task_photo_helper_text'))
                                            ->collection('task_attachments')
                                            ->visibility('public')
                                            ->downloadable()
                                            ->openable()
                                            ->maxFiles(10)
                                            ->maxSize(10240) // 10 MB
                                            ->image()
                                            //->required()
                                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
                                            ->maxSize(1024 * 5) // Maksimum 5MB örnek
                                            ->validationMessages([
                                                //'required' => __('ui.required'),
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
                                    ->maxDate(now())
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
                        Forms\Components\Toggle::make('is_winter_maintenance')
                            ->label(__('ui.winter_maintenance'))
                            ->helperText(__('ui.winter_maintenance_helper_text'))
                            ->visibleOn('create')
                            ->onColor('success')
                            ->offColor('danger')
                            ->columnSpan(3),
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
                    ->collection('task_attachments')
                    ->square()
                    ->size(50),
                Tables\Columns\TextColumn::make('type_id')
                    ->label(__('ui.type'))
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('area.name')
                    ->label(__('ui.area'))
                    ->icon('heroicon-o-map')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('subArea.name')
                    ->label(__('ui.sub_area'))
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-map-pin'),
                Tables\Columns\TextColumn::make('unit.name')
                    ->label(__('ui.unit'))
                    ->icon('heroicon-o-building-office')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('task_date')
                    ->label(__('ui.fault_date'))
                    ->icon('heroicon-o-calendar-days')
                    ->date()
                    ->badge()
                    ->color('primary')
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                        ->label(__('ui.description'))
                        ->limit(30)
                        ->wrap()
                        ->formatStateUsing(fn ($state) => $state ? "<strong>{$state}</strong>" : $state)
                        ->html()
                        ->tooltip(fn ($record) => $record->description)
                        ->searchable()
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    Tables\Columns\TextColumn::make('unit_description')
                        ->label(__('ui.unit_description'))
                        ->limit(30)
                        ->wrap()
                        ->formatStateUsing(fn ($state) => $state ? "<strong>{$state}</strong>" : $state)
                        ->html()
                        ->tooltip(fn ($record) => $record->unit_description)
                        ->searchable()
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
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
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('completedBy.name')
                    ->label(__('ui.closed_by'))
                    ->badge()
                    ->color('success')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->visible(fn () => auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_tasks'))
                    ->label(__('ui.created_by'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                // filter by unit
                Tables\Filters\SelectFilter::make('unit_id')
                    ->label(__('ui.unit'))
                    ->options(
                        Unit::all()
                            ->pluck('name', 'id')
                    ),
                // filter by type
                Tables\Filters\SelectFilter::make('type_id')
                    ->label(__('ui.type'))
                    ->options(
                        collect(TaskTypeEnum::cases())
                            ->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()])
                            ->toArray()
                    ),
            ])
            ->headerActions([
                Tables\Actions\ExportAction::make()
                    ->exporter(\App\Filament\Exports\TaskExporter::class)
                    ->label(__('ui.export'))
                    ->modalHeading(__('ui.export'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn () => auth()->user()->hasRole('super_admin') || auth()->user()->can('export_tasks')),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make(__('ui.close'))
                        ->hidden(fn ($record) => $record->trashed())
                        ->visible(fn ($record) => $record->status->isNot(TaskStatusEnum::COMPLETED) && (auth()->user()->hasRole('super_admin') || auth()->user()->can('can_close_task')) && $record->task_date <= today())
                        ->form([
                            Forms\Components\DatePicker::make('due_date')
                                ->label(__('ui.due_date'))
                                ->minDate(fn ($record) => $record->task_date)
                                ->maxDate(now())
                                ->afterOrEqual('task_date')
                                ->required()
                                ->validationMessages([
                                    'required' => __('ui.required'),
                                    'after_or_equal' => __('ui.due_date_after_or_equal_task_date'),
                                ]),
                            Forms\Components\Textarea::make('resolution_notes')
                                ->label(__('ui.resolution_notes'))
                                ->placeholder(__('ui.resolution_placeholder'))
                                ->requiredWith('due_date')
                                ->columnSpanFull()
                                ->validationMessages([
                                    'required' => __('ui.required'),
                                ])->columnSpanFull(),
                        ])
                        ->action(function (array $data, Task $record) {
                            DB::transaction(function () use ($data, $record) {
                                $record->update([
                                    'status' => TaskStatusEnum::COMPLETED,
                                    'due_date' => $data['due_date'],
                                    'resolution_notes' => $data['resolution_notes'],
                                    'completed_by' => Auth::id(),
                                    'updated_by' => Auth::id(),
                                    'updated_at' => now(),
                                ]);
                            });

                            $record->refresh();

                            //$record->notify(new TaskClosed($record));
                            $record->createdBy->notify(new TaskClosed($record));

                            Notification::make()
                                ->title(__('ui.task_closed_successfully'))
                                ->success()
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->color('success')
                        ->icon('heroicon-o-check'),
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

    public static function canDelete(Model $record): bool
    {
        return $record->status !== TaskStatusEnum::COMPLETED && (auth()->user()->hasRole('super_admin') || auth()->user()->can('delete_tasks') || $record->created_by == auth()->id());
    }
}
