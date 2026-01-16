<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Enums\ActiveStatusEnum;
use App\Enums\AssignedPersonTypeEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Filament\Resources\TaskResource;
use App\Models\Employee;
use App\Models\Task;
use App\Models\Unit;
use App\Notifications\TaskClosed;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Fieldset;
use Filament\Infolists;
use Filament\Infolists\Components\Tabs;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make(__('ui.related_person'))
                ->hidden()
                ->visible(fn ($record) => auth()->user()->hasRole('super_admin') || auth()->user()->can('can_assign_task') && $record->employee_id === null)
                ->form([
                    Fieldset::make(__('ui.assign_task'))
                        ->columns(1)
                        ->schema([
                            Forms\Components\Select::make('person_type')
                                ->label(__('ui.person_type'))
                                ->options([
                                    AssignedPersonTypeEnum::EMPLOYEE->value => AssignedPersonTypeEnum::EMPLOYEE->getLabel(),
                                    AssignedPersonTypeEnum::SUBCONTRACTOR->value => AssignedPersonTypeEnum::SUBCONTRACTOR->getLabel(),
                                ])
                                ->required()
                                ->live()
                                ->afterStateUpdated(fn (callable $set) => $set('employee_id', null))
                                ->validationMessages([
                                    'required' => __('ui.required'),
                                ]),
                            Forms\Components\Select::make('employee_id')
                                ->label(__('ui.related_person'))
                                ->options(function (callable $get) {
                                    $personType = $get('person_type');

                                    if ($personType == AssignedPersonTypeEnum::EMPLOYEE->value) {
                                        return \App\Models\Employee::all()
                                            ->where('status', ActiveStatusEnum::ACTIVE)
                                            ->pluck('name', 'id');
                                    } elseif ($personType == AssignedPersonTypeEnum::SUBCONTRACTOR->value) {
                                        return \App\Models\SubcontractorEmployee::all()
                                            ->where('active', ActiveStatusEnum::ACTIVE)
                                            ->pluck('name', 'id');
                                    }

//                                    if ($personType === 'employee') {
//                                        return \App\Models\Employee::all()
//                                            ->where('status', ActiveStatusEnum::ACTIVE)
//                                            ->pluck('name', 'id');
//                                    } elseif ($personType === 'subcontractor') {
//                                        return \App\Models\SubcontractorEmployee::all()
//                                            ->where('active', ActiveStatusEnum::ACTIVE)
//                                            ->pluck('name', 'id');
//                                    }

                                    return [];
                                })
                                ->preload()
                                ->searchable()
                                ->required()
                                ->visible(fn (callable $get) => filled($get('person_type')))
                                ->validationMessages([
                                    'required' => __('ui.required'),
                                ]),
                        ]),
                ])
                ->action(function (array $data, Task $record) {
                    DB::transaction(function () use ($data, $record) {
                        $record->update([
                            'employee_id' => $data['employee_id'],
                            'assigned_person_type_id' => $data['person_type'],
                            'updated_by' => Auth::id(),
                            'updated_at' => now(),
                        ]);
                    });

                    $record->refresh();

                    //$record->employee->notify(new \App\Notifications\TaskAssigned($record));

                    Notification::make()
                        ->title(__('ui.task_assigned_successfully'))
                        ->success()
                        ->send();
                })
                ->requiresConfirmation()
                ->color('warning')
                ->icon('heroicon-o-user'),
            Actions\Action::make(__('ui.close'))
                ->hidden(fn ($record) => $record->trashed())
                ->visible(fn ($record) => $record->status->isNot(TaskStatusEnum::COMPLETED) && (auth()->user()->hasRole('super_admin') || auth()->user()->can('can_close_task')) && $record->task_date <= today())
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
//                        ->action(function (array $data, Task $record) {
//                            DB::transaction(function () use ($data, $record) {
////                                $record->update([
////                                    'status' => TaskStatusEnum::COMPLETED,
////                                    'due_date' => $data['due_date'],
////                                    'resolution_notes' => $data['resolution_notes'],
////                                    'completed_by' => Auth::id(),
////                                    'updated_by' => Auth::id(),
////                                    'updated_at' => now(),
////                                ]);
//
//                                $personType = $data['person_type'];
//                                $assignedPersonId = $data['assigned_person_id'];
//                                $updateData = [
//                                    'status' => TaskStatusEnum::COMPLETED,
//                                    'due_date' => $data['due_date'],
//                                    'resolution_notes' => $data['resolution_notes'],
//                                    'completed_by' => Auth::id(),
//                                    'updated_by' => Auth::id(),
//                                    'updated_at' => now(),
//                                ];
//
//                                if ($personType === 'employee') {
//                                    $updateData['employee_id'] = $assignedPersonId;
//                                    $updateData['subcontractor_employee_id'] = null;
//                                } elseif ($personType === 'subcontractor') {
//                                    $updateData['subcontractor_employee_id'] = $assignedPersonId;
//                                    $updateData['employee_id'] = null;
//                                }
//
//                                $record->update($updateData);
//                            });
//
//                            $record->refresh();
//
//                            //$record->notify(new TaskClosed($record));
//                            $record->createdBy->notify(new TaskClosed($record));
//
//                            Notification::make()
//                                ->title(__('ui.task_closed_successfully'))
//                                ->success()
//                                ->send();
//                        })
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
            Actions\EditAction::make()
                ->icon('heroicon-o-pencil')
                ->mutateFormDataUsing(function (array $data): array {
                    $data['updated_by'] = auth()->id();
                    return $data;
                }),
            Actions\DeleteAction::make()
                ->icon('heroicon-o-trash'),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Card::make()
                    ->schema([
                        Infolists\Components\Fieldset::make(__('ui.task_details'))
                            ->schema([
//                                Infolists\Components\TextEntry::make('title')
//                                    ->label(__('ui.task_title')),
                                Infolists\Components\Fieldset::make(__('ui.priority_and_assigned_to'))
                                    ->columns(3)
                                    ->schema([
                                        Infolists\Components\TextEntry::make('priority')
                                            ->label(__('ui.priority'))
                                            ->badge(),
                                        Infolists\Components\TextEntry::make('status')
                                            ->label(__('ui.status'))
                                            ->badge(),
                                        Infolists\Components\TextEntry::make('employee.name')
                                            ->label(__('ui.related_person'))
                                            ->placeholder(__('ui.not_assigned_yet'))
                                            ->badge()
                                            ->color('primary')
                                            ->icon('heroicon-o-user'),
//                                        Infolists\Components\TextEntry::make('assigned_person')
//                                            ->label(__('ui.related_person'))
//                                            ->placeholder(__('ui.not_assigned_yet'))
//                                            ->badge()
//                                            ->color('primary')
//                                            ->icon('heroicon-o-user')
//                                            ->getStateUsing(function ($record) {
//                                                if ($record->assigned_person_type_id === AssignedPersonTypeEnum::EMPLOYEE) {
//                                                    return $record->employee?->name;
//                                                } elseif ($record->assigned_person_type_id === AssignedPersonTypeEnum::SUBCONTRACTOR) {
//                                                    return $record->subcontractorEmployee?->name;
//                                                }
//                                                return null;
//                                            }),
                                        Infolists\Components\TextEntry::make('subcontractor')
                                            ->hidden()
                                            ->visible(fn ($record) => $record->assigned_person_type_id === AssignedPersonTypeEnum::SUBCONTRACTOR && $record->subcontractorEmployee?->subcontractor)
                                            ->getStateUsing(fn ($record) => $record->subcontractorEmployee?->subcontractor?->name)
                                            ->label(__('ui.subcontractor_company'))
                                            ->badge()
                                            ->color('primary')
                                            ->icon('heroicon-o-building-office-2')
                                    ]),
                                Infolists\Components\TextEntry::make('type_id')
                                    ->label(__('ui.type')),
                                Infolists\Components\TextEntry::make('area.name')
                                    ->label(__('ui.area'))
                                    ->icon('heroicon-o-map'),
                                Infolists\Components\TextEntry::make('subArea.name')
                                    ->label(__('ui.sub_area'))
                                    ->icon('heroicon-o-map-pin'),
                                Infolists\Components\TextEntry::make('unit.name')
                                    ->label(__('ui.unit'))
                                    ->badge()
                                    ->icon('heroicon-o-building-office'),
                                Infolists\Components\TextEntry::make('task_date')
                                    ->label(__('ui.fault_date'))
                                    ->icon('heroicon-o-calendar-days')
                                    ->date()
                                    ->badge()
                                    ->color('primary'),
                                Infolists\Components\Fieldset::make(__('ui.descriptions'))
                                    ->columns(2)
                                    ->schema([
                                        Infolists\Components\TextEntry::make('description')
                                            ->label(__('ui.task_description'))
                                            //->formatStateUsing(fn ($state) => nl2br(e($state)))
                                            ->formatStateUsing(fn ($state) => '<strong>' . nl2br(e($state)) . '</strong>')
                                            ->html()
                                            ->columnSpan(1),
                                        Infolists\Components\TextEntry::make('unit_description')
                                            ->label(__('ui.unit_description'))
                                            ->placeholder(__('ui.not_yet'))
                                            ->formatStateUsing(fn ($state) => '<strong>' . nl2br(e($state)) . '</strong>')
                                            ->html()
                                            ->columnSpan(1),
                                    ]),
                                Infolists\Components\Fieldset::make(__('ui.image'))
                                    ->hidden(fn ($record) => !$record->hasMedia('task_attachments'))
                                    ->schema([
                                        Infolists\Components\TextEntry::make('media.task_attachments')
                                            ->hiddenLabel()
                                            ->visible(fn ($record) => $record->hasMedia('task_attachments'))
                                            ->getStateUsing(fn ($record) =>
                                            $record->getMedia('task_attachments')->map(function ($media) {
                                                $url = $media->getUrl();
                                                return '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer">'
                                                    .'<img src="'.e($url).'" alt="'.e($media->file_name ?? '').'" style="width:480px;height:320px;object-fit:cover;cursor:pointer;border-radius:8px;margin:12px;" onclick="window.open(this.src)" />'
                                                    .'</a>';
                                            })->implode('')
                                            )
                                            ->html()
                                            ->helperText(__('ui.click_image_to_view_full_size'))
                                            ->alignCenter()
                                            ->columnSpanFull(),
                                    ]),
                                //->stacked() // Alt alta sıralamak için (opsiyonel)
                                Infolists\Components\Fieldset::make(__('ui.record_info'))
                                    ->hidden()
                                    ->schema([
                                        Infolists\Components\TextEntry::make('createdBy.name')
                                            ->label(__('ui.created_by'))
                                            ->badge()
                                            ->color('primary')
                                            ->icon('heroicon-o-user'),
                                        Infolists\Components\TextEntry::make('created_at')
                                            ->label(__('ui.created_at'))
                                            ->dateTime()
                                            ->badge()
                                            ->color('primary')
                                            ->icon('heroicon-o-calendar-days'),
                                        Infolists\Components\TextEntry::make('updatedBy.name')
                                            ->visible(fn ($record) => $record->updated_by !== null)
                                            ->label(__('ui.last_updated_by'))
                                            ->badge()
                                            ->color('primary')
                                            ->icon('heroicon-o-user'),
                                        Infolists\Components\TextEntry::make('updated_at')
                                            ->visible(fn ($record) => $record->updated_by !== null)
                                            ->label(__('ui.last_updated_at'))
                                            ->dateTime()
                                            ->badge()
                                            ->color('primary')
                                            ->icon('heroicon-o-calendar-days'),
                                    ])->columns(4),

                                Infolists\Components\Fieldset::make(__('ui.resolution_information'))
                                    ->hidden()
                                    ->visible(fn ($record) => $record->status === \App\Enums\TaskStatusEnum::COMPLETED)
                                    ->columns(3)
                                    ->schema([
                                        Infolists\Components\TextEntry::make('completedBy.name')
                                            ->label(__('ui.closed_by'))
                                            ->badge()
                                            ->color('success')
                                            ->icon('heroicon-o-user'),
                                        Infolists\Components\TextEntry::make('due_date')
                                            ->label(__('ui.due_date'))
                                            ->date()
                                            ->badge()
                                            ->color('success')
                                            ->icon('heroicon-o-calendar-days'),
                                        Infolists\Components\TextEntry::make('elapsed_time')
                                                ->label(__('ui.elapsed_time_in_days'))
                                                ->getStateUsing(function ($record) {
                                                    if ($record->due_date && $record->task_date) {
                                                        $start = Carbon::parse($record->task_date)->startOfDay();
                                                        $end = Carbon::parse($record->due_date)->startOfDay();

                                                        $days = $start->diffInDays($end);

                                                        return $days == 0 ? __('ui.completed_in_same_day') : $days . ' ' . __('ui.days');
                                                    }
                                                    return __('ui.not_available');
                                                })
                                                ->badge()
                                                ->color('warning')
                                                ->icon('heroicon-o-clock'),
                                        Infolists\Components\Fieldset::make(__('ui.resolution_notes'))
                                            ->schema([
                                                Infolists\Components\TextEntry::make('resolution_notes')
                                                    ->hiddenLabel()
                                                    ->formatStateUsing(fn ($state) => nl2br(e($state)))
                                                    ->html()
                                                    ->columnSpanFull()
                                            ]),
                                    ]),

                                Tabs::make(__('ui.resolution_information'))
                                    ->columnSpanFull()
                                    ->hiddenLabel()
                                    ->tabs([
                                        Tabs\Tab::make(__('ui.record_info'))
                                            ->icon('heroicon-m-bookmark-square')
                                            ->schema([
                                                Infolists\Components\Fieldset::make(__('ui.record_info'))
                                                    ->hiddenLabel()
                                                    ->schema([
                                                        Infolists\Components\TextEntry::make('createdBy.name')
                                                            ->label(__('ui.created_by'))
                                                            ->badge()
                                                            ->color('primary')
                                                            ->icon('heroicon-o-user'),
                                                        Infolists\Components\TextEntry::make('created_at')
                                                            ->label(__('ui.created_at'))
                                                            ->dateTime()
                                                            ->badge()
                                                            ->color('primary')
                                                            ->icon('heroicon-o-calendar-days'),
                                                        Infolists\Components\TextEntry::make('updatedBy.name')
                                                            ->visible(fn ($record) => $record->updated_by !== null)
                                                            ->label(__('ui.last_updated_by'))
                                                            ->badge()
                                                            ->color('primary')
                                                            ->icon('heroicon-o-user'),
                                                        Infolists\Components\TextEntry::make('updated_at')
                                                            ->visible(fn ($record) => $record->updated_by !== null)
                                                            ->label(__('ui.last_updated_at'))
                                                            ->dateTime()
                                                            ->badge()
                                                            ->color('primary')
                                                            ->icon('heroicon-o-calendar-days'),
                                                    ])->columns(4),
                                            ]),

                                        Tabs\Tab::make(__('ui.resolution_information'))
                                            ->visible(fn ($record) => $record->status === \App\Enums\TaskStatusEnum::COMPLETED)
                                            ->icon('heroicon-m-information-circle')
                                            ->schema([
                                                Infolists\Components\Fieldset::make(__('ui.resolution_information'))
                                                    ->hiddenLabel()
                                                    ->columns(3)
                                                    ->schema([
                                                        Infolists\Components\TextEntry::make('completedBy.name')
                                                            ->label(__('ui.closed_by'))
                                                            ->badge()
                                                            ->color('success')
                                                            ->icon('heroicon-o-user'),
                                                        Infolists\Components\TextEntry::make('due_date')
                                                            ->label(__('ui.due_date'))
                                                            ->date()
                                                            ->badge()
                                                            ->color('success')
                                                            ->icon('heroicon-o-calendar-days'),
                                                        Infolists\Components\TextEntry::make('elapsed_time')
                                                            ->label(__('ui.elapsed_time_in_days'))
                                                            ->getStateUsing(function ($record) {
                                                                if ($record->due_date && $record->task_date) {
                                                                    $start = Carbon::parse($record->task_date)->startOfDay();
                                                                    $end = Carbon::parse($record->due_date)->startOfDay();

                                                                    $days = $start->diffInDays($end);

                                                                    return $days == 0 ? __('ui.completed_in_same_day') : $days . ' ' . __('ui.days');
                                                                }
                                                                return __('ui.not_available');
                                                            })
                                                            ->badge()
                                                            ->color('warning')
                                                            ->icon('heroicon-o-clock'),
                                                        Infolists\Components\Fieldset::make(__('ui.resolution_notes'))
                                                            ->schema([
                                                                Infolists\Components\TextEntry::make('resolution_notes')
                                                                    ->hiddenLabel()
                                                                    ->formatStateUsing(fn ($state) => nl2br(e($state)))
                                                                    ->html()
                                                                    ->columnSpanFull()
                                                            ]),
                                                    ]),
                                            ]),
                                    ])
                            ])->columns(3),
                    ]),
            ]);
    }
}
