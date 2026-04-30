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
use App\Models\SlaPolicy;
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
use Filament\Forms\Get;
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
use Spatie\MediaLibrary\ResponsiveImages\TinyPlaceholderGenerator\Blurred;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?int $navigationSort = -999;

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
        return static::getModel()::where('created_by', auth()->id())
            ->orWhere('employee_id', function ($query) {
                $query->select('id')
                    ->from('employees')
                    ->where('email', auth()->user()->email);
            })
            ->count();
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
                                Fieldset::make(__('ui.priority_level_and_status'))
                                    ->hidden()
                                    ->columns(2)
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
                                            ]),
                                        Forms\Components\ToggleButtons::make('status')
                                            ->hiddenLabel(__('ui.status'))
                                            ->options([
                                                TaskStatusEnum::PENDING->value => TaskStatusEnum::PENDING->getLabel(),
                                                TaskStatusEnum::WINTER_MAINTENANCE->value => TaskStatusEnum::WINTER_MAINTENANCE->getLabel(),
                                            ])
                                            ->icons([
                                                TaskStatusEnum::PENDING->value => TaskStatusEnum::PENDING->getIcon(),
                                                TaskStatusEnum::WINTER_MAINTENANCE->value => TaskStatusEnum::WINTER_MAINTENANCE->getIcon(),
                                            ])
                                            ->colors([
                                                TaskStatusEnum::PENDING->value => TaskStatusEnum::PENDING->getColor(),
                                                TaskStatusEnum::WINTER_MAINTENANCE->value => TaskStatusEnum::WINTER_MAINTENANCE->getColor(),
                                            ])
                                            ->default(TaskStatusEnum::PENDING->value)
                                            ->inline(),
                                    ]),

                                Fieldset::make(__('ui.fault_location_and_priority_information'))
                                    ->columns(2)
                                    ->schema([

                                        // GÖREV TİPİ
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

                                        // BÖLGE
                                        Forms\Components\Select::make('sla_policy_id') // Sanal isim ŞART
                                        ->label(__('ui.area'))
                                        ->prefixIcon('heroicon-o-map')
                                        ->options(function () {
                                            $user = auth()->user();
                                            $query = SlaPolicy::with(['area', 'subArea', 'unit']);

                                            if (!$user->hasRole('super_admin')) {
                                                $employee = Employee::where('email', $user->email)->first();
                                                if (!$employee) return [];

                                                $query->whereIn('id', function ($sub) use ($employee) {
                                                    $sub->select('sla_policy_id')
                                                        ->from('employee_sla_policies')
                                                        ->where('employee_id', $employee->id);
                                                });
                                            }

                                            return $query->get()->mapWithKeys(function ($policy) {
                                                $label = "{$policy->area?->name} | " . ($policy->subArea?->name ?? 'Genel') . " | " . ($policy->unit?->name) . " | " . ($policy->priority->getLabel());
                                                return [$policy->id => $label];
                                            })->toArray();
                                        })
                                        ->formatStateUsing(function ($record) { // Edit formu açıldığında, task'ın bağlı olduğu SLA politikasını bulup seçili hale getirmek için
                                            if (!$record) return null;

                                            // Task üzerindeki verilere göre eşleşen SLA politikasını buluyoruz
                                            return \App\Models\SlaPolicy::where([
                                                'area_id' => $record->area_id,
                                                'sub_area_id' => $record->sub_area_id,
                                                'unit_id' => $record->unit_id,
                                                'priority' => $record->priority,
                                            ])->value('id'); // Bize Policy ID'sini (10 gibi) döndürür
                                        })
                                        ->getOptionLabelUsing(function ($value) {
                                            // ID'den ismi bulan kısım. Yetki sorgusuna takılmaması için düz sorgu atıyoruz.
                                            $policy = SlaPolicy::find($value);
                                            if (!$policy) return $value;

                                            return "{$policy->area?->name} | " . ($policy->subArea?->name ?? 'Genel') . " | " . ($policy->unit?->name) . " | " . ($policy->priority->getLabel());
                                        })
                                        ->preload()
                                        ->searchable()
                                        ->required()
                                        ->live()
                                        ->dehydrated(false) // Bu sanal ismi sakın DB'ye gönderme
                                        ->afterStateUpdated(function ($state, callable $set) {
                                            if ($state) {
                                                $policy = SlaPolicy::find($state);
                                                if ($policy) {
                                                    // BURASI ASIL SİHİR: Diğer alanları dolduruyoruz
                                                    $set('area_id', $policy->area_id);
                                                    $set('sub_area_id', $policy->sub_area_id);
                                                    $set('unit_id', $policy->unit_id);
                                                    $set('priority', $policy->priority->value);
                                                }
                                            }
                                        })
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
                                                                    Forms\Components\Select::make('company_id')
                                                                        ->label(__('ui.company'))
                                                                        ->options(\App\Models\Company::pluck('name', 'id'))
                                                                        ->searchable()
                                                                        ->validationMessages([
                                                                            'required' => __('ui.required'),
                                                                        ])
                                                                        ->required(),
                                                                ]),
                                                        ]),
                                                ]);
                                            return $form->model(\App\Models\Area::class);
                                        })->createOptionUsing(function ($data) {
                                            $location = \App\Models\Area::create([
                                                'company_id' => $data['company_id'],
                                                'name' => $data['name'],
                                                'status' => ActiveStatusEnum::ACTIVE,
                                                'created_by' => auth()->id(),
                                            ]);
                                            return $location->id;
                                        })),

                                        Forms\Components\Hidden::make('area_id')->required(), // Bu alan görünmez ama gerekli, çünkü area_id'ye göre diğer alanlar şekillenecek ve veritabanında da saklanacak

                                        // LOKASYON
                                        Forms\Components\Select::make('sub_area_id')
                                            ->label(__('ui.sub_area'))
                                            ->options(SubArea::all()->pluck('name', 'id')) // Tümünü çekebiliriz çünkü disabled
                                            ->disabled()
                                            ->dehydrated() // Veritabanına kaydedilmesi için ŞART
                                            ->placeholder('Bölge seçiniz...'),

                                        // BİRİM
                                        Forms\Components\Select::make('unit_id')
                                            ->label(__('ui.unit'))
                                            ->options(Unit::all()->pluck('name', 'id'))
                                            ->disabled()
                                            ->dehydrated()
                                            ->required(),

                                        // ÖNCELİK
                                        ToggleButtons::make('priority')
                                            ->label(__('ui.priority'))
                                            ->options(\App\Enums\TaskPriorityEnum::class)
                                            ->disabled()
                                            ->dehydrated()
                                            ->inline(),

                                        Forms\Components\DatePicker::make('task_date')
                                            ->label(__('ui.fault_date'))
                                            ->prefixIcon('heroicon-o-calendar-days')
                                            ->required()
                                            ->maxDate(today())
                                            ->live()
                                            ->afterStateUpdated(fn (callable $set) => $set('due_date', null))
                                            ->validationMessages([
                                                'required' => __('ui.required'),
                                                'max' => __('ui.fault_date_cannot_be_in_future'),
                                                'maxDate' => __('ui.fault_date_cannot_be_in_future'),
                                            ]),

                                        Forms\Components\Select::make('sub_area_id')
                                            ->hidden()
                                            ->label(__('ui.sub_area'))
                                            ->prefixIcon('heroicon-o-map-pin')
                                            ->disabled(fn (Get $get) => ! $get('area_id')) // Area seçili değilse kilitli
                                            ->dehydrated() // Disabled olsa bile form gönderildiğinde veriyi korur
                                            ->options(function (Get $get) {
                                                $areaId = $get('area_id');
                                                if (! $areaId) return [];

                                                return SubArea::whereIn('id', function ($query) use ($areaId) {
                                                    $query->select('sub_area_id')
                                                        ->from('sla_policies')
                                                        ->where('area_id', $areaId)
                                                        ->whereNotNull('sub_area_id');
                                                })->pluck('name', 'id');
                                            })
//                                            ->options(function (callable $get) {
//                                                $areaId = $get('area_id');
//                                                if (!$areaId) {
//                                                    return [];
//                                                }
//
//                                                return SubArea::where('area_id', $areaId)->pluck('name', 'id');
//                                            })
                                            ->live()
                                            ->afterStateUpdated(fn (callable $set) => $set('unit_id', null))
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
                                    ]),

                                Fieldset::make(__('ui.descriptions'))
                                    ->columns(1)
                                    ->schema([
                                        Forms\Components\Textarea::make('description')
                                            ->label(__('ui.fault_description'))
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

                                Fieldset::make(__('ui.related_person_assignment'))
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Select::make('unit_id')
                                            ->hidden()
                                            ->label(__('ui.unit'))
                                            ->prefixIcon('heroicon-o-building-office')
                                            ->disabled(fn (Get $get) => ! $get('sub_area_id')) // Lokasyon seçilmeden birim seçilemez
                                            ->options(function (Get $get) {
                                                $areaId = $get('area_id');
                                                $subAreaId = $get('sub_area_id');
                                                if (! $areaId || ! $subAreaId) return [];

                                                return Unit::whereIn('id', function ($query) use ($areaId, $subAreaId) {
                                                    $query->select('unit_id')
                                                        ->from('sla_policies')
                                                        ->where('area_id', $areaId)
                                                        ->where('sub_area_id', $subAreaId);
                                                })->pluck('name', 'id');
                                            })
//                                            ->options(function (callable $get) {
//                                                $areaId = $get('area_id');
//
//                                                if (!$areaId) {
//                                                    return []; // Bölge seçilmeden birim gösterme
//                                                }
//
//                                                // Seçilen bölgeye (area_id) atanmış grupların bağlı olduğu birimleri (unit) getir
//                                                return Unit::query()
//                                                    ->whereHas('groups', function ($query) use ($areaId) {
//                                                        $query->where('area_id', $areaId)
//                                                            ->where('status', \App\Enums\ActiveStatusEnum::ACTIVE);
//                                                    })
//                                                    ->pluck('name', 'id');
//                                            })
                                            ->live()
                                            ->afterStateUpdated(function (callable $set) {
                                                $set('group_id', null);
                                                $set('employee_id', null);
                                                $set('priority', null);
                                            })
                                            ->preload()
                                            ->searchable()
                                            ->required()
                                            ->afterStateUpdated(function (callable $set) {
                                                $set('group_id', null);
                                                $set('employee_id', null);
                                            })
                                            ->preload()
                                            ->searchable()
                                            ->required()
                                            ->validationMessages([
                                                'required' => __('ui.required'),
                                            ]),
                                        Forms\Components\Select::make('group_id')
                                            ->label(__('ui.group'))
                                            ->prefixIcon('heroicon-o-user-group')
                                            ->hintIcon('heroicon-o-exclamation-circle')
                                            ->hintIconTooltip(__('ui.group_manager_mail_notification_hint'))
                                            ->options(function (callable $get) {
                                                $unitId = $get('unit_id');
                                                $areaId = $get('area_id');

                                                if (!$unitId) {
                                                    return [];
                                                }

                                                return \App\Models\Group::query()
                                                    ->where('status', \App\Enums\ActiveStatusEnum::ACTIVE)
                                                    //->where('area_id', $areaId) // Bölge filtresi
                                                    ->where('unit_id', $unitId) // Birim filtresi
                                                    ->pluck('name', 'id');
                                            })
                                            ->live()
                                            ->preload()
                                            ->searchable()
                                            ->required()
                                            ->afterStateUpdated(fn (callable $set) => $set('employee_id', null))
                                            ->validationMessages([
                                                'required' => __('ui.required'),
                                            ]),
                                        Forms\Components\Select::make('employee_id')
                                            ->label(__('ui.related_person'))
                                            ->prefixIcon('heroicon-o-user')
                                            ->hintIcon('heroicon-o-exclamation-circle')
                                            ->hintIconTooltip(__('ui.related_person_mail_notification_hint'))
                                            ->options(function (callable $get) {
                                                $groupId = $get('group_id');
                                                if (!$groupId) {
                                                    return [];
                                                }

                                                return Employee::query()
                                                    ->where('status', ActiveStatusEnum::ACTIVE)
                                                    ->whereHas('groupMemberships', function (Builder $query) use ($groupId) {
                                                        $query->where('group_id', $groupId)
                                                            ->whereNull('deleted_at');
                                                    })
                                                    ->pluck('name', 'id');
                                            })
                                            ->preload()
                                            ->searchable()
                                            //->requiredWith('group_id')
                                            ->validationMessages([
                                                'required' => __('ui.required'),
                                                //'required_with' => __('ui.related_person_required_when_group_selected'),
                                            ]),
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

                                ToggleButtons::make('status')
                                    ->label(__('ui.status'))
                                    ->options([
                                        TaskStatusEnum::PENDING->value => TaskStatusEnum::PENDING->getLabel(),
                                        TaskStatusEnum::WINTER_MAINTENANCE->value => TaskStatusEnum::WINTER_MAINTENANCE->getLabel(),
                                    ])
                                    ->icons([
                                        TaskStatusEnum::PENDING->value => TaskStatusEnum::PENDING->getIcon(),
                                        TaskStatusEnum::WINTER_MAINTENANCE->value => TaskStatusEnum::WINTER_MAINTENANCE->getIcon(),
                                    ])
                                    ->colors([
                                        TaskStatusEnum::PENDING->value => TaskStatusEnum::PENDING->getColor(),
                                        TaskStatusEnum::WINTER_MAINTENANCE->value => TaskStatusEnum::WINTER_MAINTENANCE->getColor(),
                                    ])
                                    ->default(TaskStatusEnum::PENDING->value)
                                    ->inline(),

                                Fieldset::make(__('ui.status_and_priority'))
                                    ->hidden()
                                    ->columns(2)
                                    ->schema([
                                        ToggleButtons::make('status')
                                            ->label(__('ui.status'))
                                            ->options([
                                                TaskStatusEnum::PENDING->value => TaskStatusEnum::PENDING->getLabel(),
                                                TaskStatusEnum::WINTER_MAINTENANCE->value => TaskStatusEnum::WINTER_MAINTENANCE->getLabel(),
                                            ])
                                            ->icons([
                                                TaskStatusEnum::PENDING->value => TaskStatusEnum::PENDING->getIcon(),
                                                TaskStatusEnum::WINTER_MAINTENANCE->value => TaskStatusEnum::WINTER_MAINTENANCE->getIcon(),
                                            ])
                                            ->colors([
                                                TaskStatusEnum::PENDING->value => TaskStatusEnum::PENDING->getColor(),
                                                TaskStatusEnum::WINTER_MAINTENANCE->value => TaskStatusEnum::WINTER_MAINTENANCE->getColor(),
                                            ])
                                            ->default(TaskStatusEnum::PENDING->value)
                                            ->inline(),

                                        ToggleButtons::make('priority')
                                            ->hidden()
                                            ->label(__('ui.priority'))
                                            ->options(function (Get $get) {
                                                $areaId = $get('area_id');
                                                $subAreaId = $get('sub_area_id');
                                                $unitId = $get('unit_id');

                                                if (!$areaId || !$unitId) return [];

                                                // Bu kombinasyona sahip tüm SLA politikalarındaki öncelikleri bul
                                                $priorities = SlaPolicy::where('area_id', $areaId)
                                                    ->where('unit_id', $unitId)
                                                    ->when($subAreaId, fn($q) => $q->where('sub_area_id', $subAreaId))
                                                    ->get()
                                                    ->pluck('priority')
                                                    ->unique()
                                                    ->sortBy(fn ($priority) => $priority->value);

                                                return $priorities->mapWithKeys(fn ($priority) => [
                                                    $priority->value => $priority->getLabel()
                                                ])->toArray();
                                            })
                                            ->icons(function (Get $get) {
                                                // Yukarıdaki mantığın aynısını ikonlar için de uygula
                                                return SlaPolicy::where('area_id', $get('area_id'))
                                                    ->where('unit_id', $get('unit_id'))
                                                    ->when($get('sub_area_id'), fn($q) => $q->where('sub_area_id', $get('sub_area_id')))
                                                    ->get()
                                                    ->pluck('priority')
                                                    ->unique()
                                                    ->mapWithKeys(fn ($priority) => [$priority->value => $priority->getIcon()])
                                                    ->toArray();
                                            })
                                            ->colors(function (Get $get) {
                                                // Renkler için de aynı filtreleme
                                                return SlaPolicy::where('area_id', $get('area_id'))
                                                    ->where('unit_id', $get('unit_id'))
                                                    ->when($get('sub_area_id'), fn($q) => $q->where('sub_area_id', $get('sub_area_id')))
                                                    ->get()
                                                    ->pluck('priority')
                                                    ->unique()
                                                    ->mapWithKeys(fn ($priority) => [$priority->value => $priority->getColor()])
                                                    ->toArray();
                                            })
                                            ->inline()
                                            ->required(),

                                        ToggleButtons::make('priority')
                                            ->hidden()
                                            ->label(__('ui.priority'))
                                            ->disabled(fn (Get $get) => ! $get('unit_id')) // Birim seçilmeden öncelik seçilemez
                                            ->options(function (Get $get) {
                                                $areaId = $get('area_id');
                                                $subAreaId = $get('sub_area_id');
                                                $unitId = $get('unit_id');

                                                if (! $areaId || ! $unitId) return [];

                                                return SlaPolicy::where('area_id', $areaId)
                                                    ->where('unit_id', $unitId)
                                                    ->when($subAreaId, fn($q) => $q->where('sub_area_id', $subAreaId))
                                                    ->get()
                                                    ->pluck('priority')
                                                    ->unique()
                                                    ->mapWithKeys(fn ($priority) => [$priority->value => $priority->getLabel()])
                                                    ->toArray();
                                            })
                                            ->icons(function (Get $get) {
                                                // Yukarıdaki mantığın aynısını ikonlar için de uygula
                                                return SlaPolicy::where('area_id', $get('area_id'))
                                                    ->where('unit_id', $get('unit_id'))
                                                    ->when($get('sub_area_id'), fn($q) => $q->where('sub_area_id', $get('sub_area_id')))
                                                    ->get()
                                                    ->pluck('priority')
                                                    ->unique()
                                                    ->mapWithKeys(fn ($priority) => [$priority->value => $priority->getIcon()])
                                                    ->toArray();
                                            })
                                            ->colors(function (Get $get) {
                                                // Renkler için de aynı filtreleme
                                                return SlaPolicy::where('area_id', $get('area_id'))
                                                    ->where('unit_id', $get('unit_id'))
                                                    ->when($get('sub_area_id'), fn($q) => $q->where('sub_area_id', $get('sub_area_id')))
                                                    ->get()
                                                    ->pluck('priority')
                                                    ->unique()
                                                    ->mapWithKeys(fn ($priority) => [$priority->value => $priority->getColor()])
                                                    ->toArray();
                                            })
                                            ->inline()
                                            ->required(),
                                    ]),
                            ]),
                        Fieldset::make(__('ui.resolution_information'))
                            ->hidden()
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
                            ->hidden()
                            ->label(__('ui.winter_maintenance'))
                            ->helperText(__('ui.winter_maintenance_helper_text'))
                            ->visibleOn(['view', 'edit'])
                            ->onColor('success')
                            ->offColor('danger')
                            ->columnSpan(3),
                        Forms\Components\ToggleButtons::make('status')
                            ->hidden()
                            ->label(__('ui.status'))
                            ->options([
                                TaskStatusEnum::PENDING->value => TaskStatusEnum::PENDING->getLabel(),
                                TaskStatusEnum::WINTER_MAINTENANCE->value => TaskStatusEnum::WINTER_MAINTENANCE->getLabel(),
                            ])
                            ->icons([
                                TaskStatusEnum::PENDING->value => TaskStatusEnum::PENDING->getIcon(),
                                TaskStatusEnum::WINTER_MAINTENANCE->value => TaskStatusEnum::WINTER_MAINTENANCE->getIcon(),
                            ])
                            ->colors([
                                TaskStatusEnum::PENDING->value => TaskStatusEnum::PENDING->getColor(),
                                TaskStatusEnum::WINTER_MAINTENANCE->value => TaskStatusEnum::WINTER_MAINTENANCE->getColor(),
                            ])
                            ->inline()
                            ->visibleOn(['view', 'edit'])
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
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('area.name')
                    ->label(__('ui.area'))
                    ->icon('heroicon-o-map')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('subArea.name')
                    ->label(__('ui.sub_area'))
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-map-pin')
                    ->toggleable(isToggledHiddenByDefault: true),
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
                // filter by related person
                Tables\Filters\SelectFilter::make('employee_id')
                    ->label(__('ui.related_person'))
                    ->options(
                        Employee::all()
                            ->where('status', ActiveStatusEnum::ACTIVE)
                            ->pluck('name', 'id')
                    )
                    ->preload()
                    ->searchable(),
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
                    Tables\Actions\Action::make(__('ui.dispatch'))
                        ->visible(fn ($record) => $record->status->isNot(TaskStatusEnum::COMPLETED) && (auth()->user()->hasRole('super_admin') || auth()->user()->can('can_assign_task') || $record->employee?->email === auth()->user()->email) && filled($record->employee_id))
                        ->form([
                            Fieldset::make(__('ui.related_person_info'))
                                ->columns(1)
                                ->schema([
                                    Forms\Components\TextInput::make('group.name')
                                        ->label(__('ui.group'))
                                        ->default(fn ($record) => $record->group?->name)
                                        ->disabled(),
                                    Forms\Components\Select::make('employee_id')
                                        //->hidden()
                                        ->label(__('ui.related_person'))
                                        ->options(function (callable $get) {

                                            $groupName = $get('group.name');

                                            if (!$groupName) {
                                                return [];
                                            }

                                            return Employee::query()
                                                ->where('status', ActiveStatusEnum::ACTIVE)
                                                ->whereHas('groupMemberships', function ($query) use ($groupName) {
                                                    $query->whereHas('group', function ($q) use ($groupName) {
                                                        $q->where('name', $groupName);
                                                    })->whereNull('deleted_at');
                                                })
                                                ->pluck('name', 'id');
                                        })
                                        ->preload()
                                        ->searchable()
                                        ->default(fn ($record) => $record->employee_id)
                                        //->disabled(fn ($record) => filled($record->employee_id))
                                        ->required()
                                        ->validationMessages([
                                            'required' => __('ui.required'),
                                        ])
                                ]),
                        ])
                        ->action(function (array $data, Task $record) {
                            DB::transaction(function () use ($data, $record) {
                                $record->update([
                                    'employee_id' => $data['employee_id'],
                                    'updated_by' => Auth::id(),
                                    'updated_at' => now(),
                                ]);
                            });

                            $record->refresh();

                            if ($record->employee) {
                                $record->notify(new \App\Notifications\TaskAssigned($record));
                            }

                            Notification::make()
                                ->title(__('ui.related_person_assigned_successfully'))
                                ->success()
                                ->send();

                            // redirect to index page after dispatching if the user is not super_admin and doesn't have can_assign_task permission
//                            if (!auth()->user()->hasRole('super_admin') && !auth()->user()->can('can_assign_task')) {
//                                return redirect($this->getResource()::getUrl('index'));
//                            }
                        })
                        ->requiresConfirmation()
                        ->color('warning')
                        ->icon('heroicon-o-arrow-uturn-right'),
                    Tables\Actions\Action::make(__('ui.assign_related_person'))
                        ->visible(fn ($record) => ($record->status->isNot(TaskStatusEnum::COMPLETED) && $record->employee_id === null) && (auth()->user()->hasRole('super_admin') || auth()->user()->can('can_assign_task') || $record->created_by === auth()->id()))
                        ->form([
                            Fieldset::make(__('ui.related_person_info'))
                                ->columns(1)
                                ->schema([
                                    Forms\Components\TextInput::make('group.name')
                                        ->label(__('ui.group'))
                                        ->default(fn ($record) => $record->group?->name)
                                        ->disabled(),
                                    Forms\Components\Select::make('employee_id')
                                        //->hidden()
                                        ->label(__('ui.related_person'))
                                        ->options(function (callable $get) {

                                            $groupName = $get('group.name');

                                            if (!$groupName) {
                                                return [];
                                            }

                                            return Employee::query()
                                                ->where('status', ActiveStatusEnum::ACTIVE)
                                                ->whereHas('groupMemberships', function ($query) use ($groupName) {
                                                    $query->whereHas('group', function ($q) use ($groupName) {
                                                        $q->where('name', $groupName);
                                                    })->whereNull('deleted_at');
                                                })
                                                ->pluck('name', 'id');
                                        })
                                        ->preload()
                                        ->searchable()
                                        ->default(fn ($record) => $record->employee_id)
                                        //->disabled(fn ($record) => filled($record->employee_id))
                                        ->required()
                                        ->validationMessages([
                                            'required' => __('ui.required'),
                                        ]),
                                ]),
                        ])
                        ->action(function (array $data, Task $record) {
                            DB::transaction(function () use ($data, $record) {
                                $record->update([
                                    'employee_id' => $data['employee_id'],
                                    'updated_by' => Auth::id(),
                                    'updated_at' => now(),
                                ]);
                            });

                            $record->refresh();

                            if ($record->employee) {
                                $record->notify(new \App\Notifications\TaskAssigned($record));
                            }

                            Notification::make()
                                ->title(__('ui.related_person_assigned_successfully'))
                                ->success()
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->color('warning')
                        ->icon('heroicon-o-user-group'),
                    Tables\Actions\Action::make(__('ui.close'))
                        ->visible(fn ($record) =>
                            ($record->status->isNot(TaskStatusEnum::COMPLETED) && filled($record->employee_id)) &&
                            (auth()->user()->hasRole('super_admin') || auth()->user()->can('can_close_task') || $record->employee?->email === auth()->user()->email || $record->created_by === auth()->id())
                        )
                        ->form([
                            Fieldset::make(__('ui.closure_info'))
                                ->columns(1)
                                ->schema([
                                    Forms\Components\DatePicker::make('due_date')
                                        ->hidden()
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
                                ]),
                        ])
                        ->action(function (array $data, Task $record) {
                            DB::transaction(function () use ($data, $record) {
                                $record->update([
                                    'status' => TaskStatusEnum::COMPLETED,
                                    'due_date' => now(),
                                    //'due_date' => $data['due_date'],
                                    'resolution_notes' => $data['resolution_notes'],
                                    'completed_by' => Auth::id(),
                                    'updated_by' => Auth::id(),
                                    'updated_at' => now(),
                                    'reopen_reason' => null,
                                    'reopened_by' => null,
                                    'reopened_at' => null,
                                ]);
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
                    Tables\Actions\Action::make(__('ui.reopen'))
                        ->visible(fn ($record) => $record->status->is(TaskStatusEnum::COMPLETED) && (auth()->user()->hasRole('super_admin') || auth()->user()->can('can_reopen_task') || $record->created_by === auth()->id()))
                        ->form([
                            Fieldset::make(__('ui.reopening_reason'))
                                ->schema([
                                    Forms\Components\Textarea::make('reopen_reason')
                                        ->hiddenLabel(__('ui.reopen_reason'))
                                        ->placeholder(__('ui.reopen_reason_placeholder'))
                                        ->required()
                                        ->columnSpanFull()
                                        ->validationMessages([
                                            'required' => __('ui.required'),
                                        ]),
                                ]),
                        ])
                        ->action(function (Task $record, array $data) {
                            DB::transaction(function () use ($record, $data) {
                                $record->update([
                                    'status' => TaskStatusEnum::PENDING,
                                    'due_date' => null,
                                    'resolution_notes' => null,
                                    'completed_by' => null,
                                    'updated_by' => Auth::id(),
                                    'updated_at' => now(),
                                    'reopen_reason' => $data['reopen_reason'],
                                    'reopened_by' => Auth::id(),
                                    'reopened_at' => now(),
                                ]);
                            });

                            $record->refresh();

                            $record->employee->notify(new \App\Notifications\TaskReopened($record));

                            Notification::make()
                                ->title(__('ui.task_reopened_successfully'))
                                ->success()
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->color('warning')
                        ->icon('heroicon-o-arrow-uturn-left'),
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

    public static function canEdit(Model $record): bool
    {
    return $record->status->isNot(TaskStatusEnum::COMPLETED) && (auth()->user()->hasRole('super_admin') || $record->created_by == auth()->id());
    }

    public static function canDelete(Model $record): bool
    {
        return $record->status->isNot(TaskStatusEnum::COMPLETED) && (auth()->user()->hasRole('super_admin') || $record->created_by == auth()->id());
    }
}
