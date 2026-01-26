<?php

namespace App\Filament\Resources;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\TaskTypeEnum;
use App\Filament\Resources\TaskResource\Pages;
use App\Filament\Resources\TaskResource\RelationManagers;
use App\Models\Area;
use App\Models\Employee;
use App\Models\SubArea;
use App\Models\Task;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Notifications\TaskClosed;
use Filament\Forms;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\ToggleButtons;
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
use Illuminate\Support\Str;

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
                                Fieldset::make(__('ui.priority_level'))
                                    ->columns(1)
                                    ->schema([
                                        ToggleButtons::make('priority')
                                            ->hiddenLabel()
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
                                            ->validationMessages([
                                                'required' => __('ui.required'),
                                            ])
                                            ->columnSpanFull(),
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
                                Forms\Components\DatePicker::make('task_date')
                                    ->label(__('ui.fault_date'))
                                    ->required()
                                    ->maxDate(today())
                                    ->live()
                                    ->afterStateUpdated(fn (callable $set) => $set('due_date', null))
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                        'max' => __('ui.fault_date_cannot_be_in_future'),
                                        'maxDate' => __('ui.fault_date_cannot_be_in_future'),
                                    ]),
                                Forms\Components\Select::make('unit_id')
                                    ->label(__('ui.unit'))
                                    ->options(Unit::query()->pluck('name', 'id'))
                                    ->live()
                                    ->afterStateUpdated(fn (callable $set) => $set('employee_id', null))
                                    ->preload()
                                    ->searchable()
                                    ->required()
                                    ->validationMessages([
                                        'required' => __('ui.required'),
                                    ]),
                                Forms\Components\Select::make('employee_id')
                                    //->hidden()
                                    ->label(__('ui.related_person'))
                                    ->options(function (callable $get) {

                                        $unitId = $get('unit_id');

                                        if (!$unitId) {
                                            return [];
                                        }

                                        // if unit_id is 5 then return employees like "Bakım%"
                                        // 5: Ünite Bakımı
                                        if ($unitId == 5) {
                                            return Employee::query()
                                                ->where('status', ActiveStatusEnum::ACTIVE)
                                                ->where('profession', 'LIKE', 'Bakım%')
                                                ->pluck('name', 'id');
                                        }

                                        // if unit_id is 6 then return all employees
                                        // 6: Temapark Görsel
                                        if ($unitId == 6) {
                                            return Employee::query()
                                                ->where('status', ActiveStatusEnum::ACTIVE)
                                                ->pluck('name', 'id');
                                        }

                                        $unitName = Unit::query()
                                            ->where('id', $unitId)
                                            ->value('name');

                                        if (!$unitName) {
                                            return [];
                                        }

                                        return Employee::query()
                                            ->where('status', ActiveStatusEnum::ACTIVE)
                                            ->where('profession', 'LIKE', $unitName . '%')
                                            ->pluck('name', 'id');
                                    })
                                    ->preload()
                                    ->searchable()
                                    //->required()
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
                Tables\Columns\TextColumn::make('priority')
                    ->label(__('ui.priority'))
                    ->badge()
                    ->sortable(),
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
                Tables\Columns\TextColumn::make('employee.name')
                    ->label(__('ui.related_person'))
                    ->placeholder(__('ui.not_assigned'))
                    ->alignCenter()
                    ->icon('heroicon-o-user')
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
                Tables\Columns\TextColumn::make('completedBy.name')
                    ->label(__('ui.closed_by'))
                    ->icon('heroicon-o-user')
                    ->badge()
                    ->color('success')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('due_date')
                    ->label(__('ui.due_date'))
                    ->icon('heroicon-o-calendar-days')
                    ->date()
                    ->badge()
                    ->color('success')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->visible(fn () => auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_tasks'))
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
                // filter by priority
                Tables\Filters\SelectFilter::make('priority')
                    ->label(__('ui.priority'))
                    ->options(
                        collect(TaskPriorityEnum::cases())
                            ->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()])
                            ->toArray()
                    ),
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
                    Tables\Actions\Action::make(__('ui.assign_related_person'))
                        ->visible(fn ($record) => $record->status->isNot(TaskStatusEnum::COMPLETED) && (auth()->user()->hasRole('super_admin') || auth()->user()->can('can_assign_task')) && $record->employee_id === null)
                        ->form([
                            Fieldset::make(__('ui.related_person_info'))
                                ->columns(1)
                                ->schema([
                                    Forms\Components\Select::make('unit_id')
                                        ->label(__('ui.unit'))
                                        ->options(Unit::query()->pluck('name', 'id'))
                                        ->live()
                                        ->afterStateUpdated(fn (callable $set) => $set('employee_id', null))
                                        ->preload()
                                        ->searchable()
                                        ->required()
                                        ->default(fn ($record) => $record->unit_id)
                                        ->disabled(fn ($record) => filled($record->unit_id))
                                        ->validationMessages([
                                            'required' => __('ui.required'),
                                        ]),
                                    Forms\Components\Select::make('employee_id')
                                        //->hidden()
                                        ->label(__('ui.related_person'))
                                        ->options(function (callable $get) {

                                            $unitId = $get('unit_id');

                                            if (!$unitId) {
                                                return [];
                                            }

                                            // if unit_id is 5 then return employees like "Bakım%"
                                            // 5: Ünite Bakımı
                                            if ($unitId == 5) {
                                                return Employee::query()
                                                    ->where('status', ActiveStatusEnum::ACTIVE)
                                                    ->where('profession', 'LIKE', 'Bakım%')
                                                    ->pluck('name', 'id');
                                            }

                                            // if unit_id is 6 then return all employees
                                            // 6: Temapark Görsel
                                            if ($unitId == 6) {
                                                return Employee::query()
                                                    ->where('status', ActiveStatusEnum::ACTIVE)
                                                    ->pluck('name', 'id');
                                            }

                                            $unitName = Unit::query()
                                                ->where('id', $unitId)
                                                ->value('name');

                                            if (!$unitName) {
                                                return [];
                                            }

                                            return Employee::query()
                                                ->where('status', ActiveStatusEnum::ACTIVE)
                                                ->where('profession', 'LIKE', $unitName . '%')
                                                ->pluck('name', 'id');
                                        })
                                        ->preload()
                                        ->searchable()
                                        ->default(fn ($record) => $record->employee_id)
                                        //->disabled(fn ($record) => filled($record->employee_id))
                                        //->required()
                                        ->validationMessages([
                                            'required' => __('ui.required'),
                                        ]),
                                ]),
                        ])
                        ->action(function (array $data, Task $record) {
                            DB::transaction(function () use ($data, $record) {
                                $updateData = [
                                    'employee_id' => $data['employee_id'],
                                    'updated_by' => Auth::id(),
                                    'updated_at' => now(),
                                ];

                                // Eğer yeni unit_id ve employee_id verilmişse güncelle
                                if (isset($data['unit_id']) && !$record->unit_id) {
                                    $updateData['unit_id'] = $data['unit_id'];
                                }
                                if (isset($data['employee_id']) && !$record->employee_id) {
                                    $updateData['employee_id'] = $data['employee_id'];
                                }

                                $record->update($updateData);
                            });

                            $record->refresh();

                            if ($record->employee) {

                                $record->employee->notify(new TaskAssigned($record));

                                $employeeId = $record->employee->email;

                                $recipient = User::where('email', $employeeId)->first();

                                if ($recipient) {
                                    $recipient->notify(
                                        Notification::make()
                                            //->title(__('ui.task_assigned_notification_title', ['task_id' => $record->id]))
                                            ->title(__('ui.task_assigned_notification_title'))
                                            ->body(__('ui.task_assigned_notification_body', [
                                                'task_description' => Str::limit($record->description, 50),
                                            ]))
                                            ->icon('heroicon-o-clipboard-check')
                                            ->toDatabase()
                                    );
                                }
                            }

                            Notification::make()
                                ->title(__('ui.related_person_assigned_successfully'))
                                ->success()
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->color('warning')
                        ->icon('heroicon-o-user-circle'),
                    Tables\Actions\Action::make(__('ui.close'))
                        //->hidden(fn ($record) => $record->trashed())
                        ->visible(fn ($record) => $record->status->isNot(TaskStatusEnum::COMPLETED) && (auth()->user()->hasRole('super_admin') || auth()->user()->can('can_close_task')) && $record->task_date <= today() && filled($record->employee_id))
                        ->form([
                            Fieldset::make(__('ui.related_person_info'))
                                ->columns(1)
                                ->schema([
                                    Forms\Components\Select::make('unit_id')
                                        ->label(__('ui.unit'))
                                        ->options(Unit::query()->pluck('name', 'id'))
                                        ->live()
                                        ->afterStateUpdated(fn (callable $set) => $set('employee_id', null))
                                        ->preload()
                                        ->searchable()
                                        ->required()
                                        ->default(fn ($record) => $record->unit_id)
                                        //->disabled(fn ($record) => filled($record->unit_id))
                                        ->validationMessages([
                                            'required' => __('ui.required'),
                                        ]),
                                    Forms\Components\Select::make('employee_id')
                                        //->hidden()
                                        ->label(__('ui.related_person'))
                                        ->options(function (callable $get) {

                                            $unitId = $get('unit_id');

                                            if (!$unitId) {
                                                return [];
                                            }

                                            // if unit_id is 5 then return employees like "Bakım%"
                                            // 5: Ünite Bakımı
                                            if ($unitId == 5) {
                                                return Employee::query()
                                                    ->where('status', ActiveStatusEnum::ACTIVE)
                                                    ->where('profession', 'LIKE', 'Bakım%')
                                                    ->pluck('name', 'id');
                                            }

                                            // if unit_id is 6 then return all employees
                                            // 6: Temapark Görsel
                                            if ($unitId == 6) {
                                                return Employee::query()
                                                    ->where('status', ActiveStatusEnum::ACTIVE)
                                                    ->pluck('name', 'id');
                                            }

                                            $unitName = Unit::query()
                                                ->where('id', $unitId)
                                                ->value('name');

                                            if (!$unitName) {
                                                return [];
                                            }

                                            return Employee::query()
                                                ->where('status', ActiveStatusEnum::ACTIVE)
                                                ->where('profession', 'LIKE', $unitName . '%')
                                                ->pluck('name', 'id');
                                        })
                                        ->preload()
                                        ->searchable()
                                        ->default(fn ($record) => $record->employee_id)
                                        //->disabled(fn ($record) => filled($record->employee_id))
                                        //->required()
                                        ->validationMessages([
                                            'required' => __('ui.required'),
                                        ]),
//                                    Forms\Components\Select::make('employee_id')
//                                        ->label(__('ui.assigned_to'))
//                                        ->options(
//                                            \App\Models\Employee::all()
//                                                ->where('status', ActiveStatusEnum::ACTIVE)
//                                                ->pluck('name', 'id')
//                                        )
//                                        ->preload()
//                                        ->searchable(),

//                                    Forms\Components\Select::make('person_type')
//                                        ->label(__('ui.person_type'))
//                                        ->options([
//                                            'employee' => __('ui.employee'),
//                                            'subcontractor' => __('ui.subcontractor'),
//                                        ])
//                                        ->required()
//                                        ->live()
//                                        ->afterStateUpdated(fn (callable $set) => $set('assigned_person_id', null))
//                                        ->validationMessages([
//                                            'required' => __('ui.required'),
//                                        ]),
//                                    Forms\Components\Select::make('assigned_person_id')
//                                        ->label(__('ui.assigned_to'))
//                                        ->options(function (callable $get) {
//                                            $personType = $get('person_type');
//
//                                            if ($personType === 'employee') {
//                                                return \App\Models\Employee::all()
//                                                    ->where('status', ActiveStatusEnum::ACTIVE)
//                                                    ->pluck('name', 'id');
//                                            } elseif ($personType === 'subcontractor') {
//                                                return \App\Models\SubcontractorEmployee::all()
//                                                    ->where('active', ActiveStatusEnum::ACTIVE)
//                                                    ->pluck('name', 'id');
//                                            }
//
//                                            return [];
//                                        })
//                                        ->preload()
//                                        ->searchable()
//                                        ->required()
//                                        ->visible(fn (callable $get) => filled($get('person_type')))
//                                        ->validationMessages([
//                                            'required' => __('ui.required'),
//                                        ]),
                                ]),
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
                                $updateData = [
                                    'status' => TaskStatusEnum::COMPLETED,
                                    'due_date' => $data['due_date'],
                                    'resolution_notes' => $data['resolution_notes'],
                                    'completed_by' => Auth::id(),
                                    'updated_by' => Auth::id(),
                                    'updated_at' => now(),
                                ];

                                // Eğer yeni unit_id ve employee_id verilmişse güncelle
                                if (isset($data['unit_id']) && !$record->unit_id) {
                                    $updateData['unit_id'] = $data['unit_id'];
                                }
                                if (isset($data['employee_id']) && !$record->employee_id) {
                                    $updateData['employee_id'] = $data['employee_id'];
                                }

                                $record->update($updateData);
                            });

                            $record->refresh();

                            $record->createdBy->notify(new TaskClosed($record));

                            Notification::make()
                                ->title(__('ui.task_closed_successfully'))
                                ->success()
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->color('success')
                        ->icon('heroicon-o-check-circle'),
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
