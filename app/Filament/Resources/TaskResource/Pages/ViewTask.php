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
            Actions\Action::make(__('ui.dispatch'))
                ->visible(fn ($record) => $record->status->isNot(TaskStatusEnum::COMPLETED) && (auth()->user()->hasRole('super_admin') || auth()->user()->can('can_assign_task') || $record->employee?->email === auth()->user()->email) && filled($record->employee_id))
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
                        $record->notify(new \App\Notifications\TaskAssigned($record));
                    }

                    Notification::make()
                        ->title(__('ui.related_person_assigned_successfully'))
                        ->success()
                        ->send();
                })
                ->requiresConfirmation()
                ->color('warning')
                ->icon('heroicon-o-arrow-uturn-right'),
            Actions\Action::make(__('ui.assign_related_person'))
                ->visible(fn ($record) => ($record->status->isNot(TaskStatusEnum::COMPLETED) && $record->employee_id === null) && (auth()->user()->hasRole('super_admin') || auth()->user()->can('can_assign_task') || $record->created_by === auth()->id()))
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
                        $record->notify(new \App\Notifications\TaskAssigned($record));
                    }

                    Notification::make()
                        ->title(__('ui.related_person_assigned_successfully'))
                        ->success()
                        ->send();
                })
                ->requiresConfirmation()
                ->color('warning')
                ->icon('heroicon-o-user-circle'),
            Actions\EditAction::make()
                //->visible(fn ($record) => $record->status->isNot(TaskStatusEnum::COMPLETED) && (auth()->user()->hasRole('super_admin') || $record->created_by == auth()->id()))
                ->icon('heroicon-o-pencil')
                ->mutateFormDataUsing(function (array $data): array {
                    $data['updated_by'] = auth()->id();
                    return $data;
                }),
            Actions\Action::make(__('ui.close'))
                ->hidden(fn ($record) => $record->trashed())
                ->visible(fn ($record) =>
                    ($record->status->isNot(TaskStatusEnum::COMPLETED) && filled($record->employee_id)) &&
                    (auth()->user()->hasRole('super_admin') || auth()->user()->can('can_close_task') || $record->employee?->email === auth()->user()->email || $record->created_by === auth()->id())
                )
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
                            'reopen_reason' => null,
                            'reopened_by' => null,
                            'reopened_at' => null,
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
            Actions\Action::make('ui.reopen')
                ->label(__('ui.reopen'))
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
                                Infolists\Components\Fieldset::make(__('ui.reopening_reason'))
                                    ->visible(fn ($record) => $record->status === TaskStatusEnum::PENDING && $record->reopen_reason !== null)
                                    ->schema([
                                        Infolists\Components\TextEntry::make('reopen_reason')
                                            ->hiddenLabel()
                                            ->formatStateUsing(fn ($state) => '<strong>' . nl2br(e($state)) . '</strong>')
                                            ->html(),
                                    ]),
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
                                                        Infolists\Components\TextEntry::make('elapsed_time')
                                                            ->visible(fn ($record) => $record->status->isNot(\App\Enums\TaskStatusEnum::COMPLETED))                                                            ->label(__('ui.waiting_time'))
                                                            ->getStateUsing(function ($record) {
                                                                // Başlangıç her zaman oluşturulma tarihi (datetime)
                                                                $start = $record->created_at;

                                                                if (!$start) return __('ui.not_available');

                                                                // Bitiş: Tamamlanmışsa due_date, hala açıksa şu anki zaman
                                                                $isCompleted = $record->status->value === \App\Enums\TaskStatusEnum::COMPLETED->value;
                                                                $end = ($isCompleted && $record->due_date) ? $record->due_date : now();

                                                                // Aradaki farkı Carbon'un diffForHumans metodu ile alalım
                                                                // 'parts' => 2 sayesinde "10 Gün 2 Dakika" formatını yakalarız
                                                                return $start->diffForHumans($end, [
                                                                    'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE,
                                                                    'parts' => 2,
                                                                    'join' => ' ',
                                                                ]);
                                                            })
                                                            ->badge()
                                                            ->color(fn ($record) =>
                                                            $record->status->value === \App\Enums\TaskStatusEnum::COMPLETED->value ? 'success' : 'warning'
                                                            )
                                                            ->icon('heroicon-o-clock'),
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
//                                                        Infolists\Components\TextEntry::make('elapsed_time')
//                                                            ->label(__('ui.elapsed_time_in_days'))
//                                                            ->getStateUsing(function ($record) {
//                                                                if ($record->due_date && $record->created_at) {
//                                                                    $start = Carbon::parse($record->created_at)->startOfDay();
//                                                                    $end = Carbon::parse($record->due_date)->startOfDay();
//
//                                                                    $days = $start->diffInDays($end);
//
//                                                                    return $days == 0 ? __('ui.completed_in_same_day') : $days . ' ' . __('ui.days');
//                                                                }
//                                                                return __('ui.not_available');
//                                                            })
//                                                            ->badge()
//                                                            ->color('warning')
//                                                            ->icon('heroicon-o-clock'),
                                                        Infolists\Components\TextEntry::make('elapsed_time')
                                                            ->label(__('ui.elapsed_time'))
                                                            ->getStateUsing(function ($record) {
                                                                // Başlangıç her zaman oluşturulma tarihi (datetime)
                                                                $start = $record->created_at;

                                                                if (!$start) return __('ui.not_available');

                                                                // Bitiş: Tamamlanmışsa due_date, hala açıksa şu anki zaman
                                                                $isCompleted = $record->status->value === \App\Enums\TaskStatusEnum::COMPLETED->value;
                                                                $end = ($isCompleted && $record->due_date) ? $record->due_date : now();

                                                                // Aradaki farkı Carbon'un diffForHumans metodu ile alalım
                                                                // 'parts' => 2 sayesinde "10 Gün 2 Dakika" formatını yakalarız
                                                                return $start->diffForHumans($end, [
                                                                    'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE,
                                                                    'parts' => 2,
                                                                    'join' => ' ',
                                                                ]);
                                                            })
                                                            ->badge()
                                                            ->color(fn ($record) =>
                                                            $record->status->value === \App\Enums\TaskStatusEnum::COMPLETED->value ? 'success' : 'warning'
                                                            )
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
