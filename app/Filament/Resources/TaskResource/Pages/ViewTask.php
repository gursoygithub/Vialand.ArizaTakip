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
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketClosedNotification;
use App\Notifications\TicketReopenedNotification;
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
//            Actions\CreateAction::make()
//                ->label(__('ui.create_task'))
//                ->icon('heroicon-o-plus')
//                ->url($this->getResource()::getUrl('create'))
//                ->color('success'),
            Actions\Action::make(__('ui.dispatch'))
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
                        $record->notify(new TicketAssignedNotification($record));
                    }

                    Notification::make()
                        ->title(__('ui.related_person_assigned_successfully'))
                        ->success()
                        ->send();

                    // redirect to index page after dispatching if the user is not super_admin and doesn't have can_assign_task permission
                    if (!auth()->user()->hasRole('super_admin') && !auth()->user()->can('can_assign_task')) {
                        return redirect($this->getResource()::getUrl('index'));
                    }
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
                        $record->notify(new TicketAssignedNotification($record));
                    }

                    Notification::make()
                        ->title(__('ui.related_person_assigned_successfully'))
                        ->success()
                        ->send();
                })
                ->requiresConfirmation()
                ->color('warning')
                ->icon('heroicon-o-user-group'),
            Actions\EditAction::make()
                //->visible(fn ($record) => $record->status->isNot(TaskStatusEnum::COMPLETED) && (auth()->user()->hasRole('super_admin') || $record->created_by == auth()->id()))
                ->icon('heroicon-o-pencil')
                ->mutateFormDataUsing(function (array $data): array {
                    $data['updated_by'] = auth()->id();
                    return $data;
                }),
            Actions\Action::make(__('ui.close'))
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

                    $record->createdBy->notify(new TicketClosedNotification($record));

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

                    $record->employee->notify(new TicketReopenedNotification($record));

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
                                    ->columns(4)
                                    ->schema([
                                        Infolists\Components\TextEntry::make('priority')
                                            ->label(__('ui.priority'))
                                            ->badge(),
                                        Infolists\Components\TextEntry::make('unit.name')
                                            ->label(__('ui.unit'))
                                            ->badge()
                                            ->icon('heroicon-o-building-office'),
                                        Infolists\Components\TextEntry::make('group.name')
                                            ->label(__('ui.group'))
                                            ->badge()
                                            ->icon('heroicon-o-user-group'),
                                        Infolists\Components\TextEntry::make('employee.name')
                                            ->visible(fn ($record) => $record->employee_id !== null)
                                            ->label(__('ui.related_person'))
                                            ->placeholder(__('ui.not_assigned_yet'))
                                            ->badge()
                                            ->color('primary')
                                            ->icon('heroicon-o-user'),
                                        Infolists\Components\TextEntry::make('status')
                                            ->label(__('ui.status'))
                                            ->badge(),
                                        Infolists\Components\TextEntry::make('elapsed_time')
                                            ->label(__('ui.waiting_time'))
                                            ->getStateUsing(function ($record) {
                                                $start = $record->created_at;

                                                if (!$start) return null;

                                                // Bitiş noktası: Tamamlandıysa due_date (saatiyle birlikte), değilse şu an (now)
                                                // endOfDay() kaldırıldı, böylece tablo ile aynı net farkı hesaplar.
                                                $end = ($record->status->value === \App\Enums\TaskStatusEnum::COMPLETED->value && $record->due_date)
                                                    ? $record->due_date
                                                    : now();

                                                return $start->diffForHumans($end, [
                                                    'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE,
                                                    'parts' => 3, // Tabloyla tam uyum için burayı 2 de yapabilirsiniz
                                                    'join' => ' ',
                                                ]);
                                            })
                                            ->badge()
                                            ->color(fn ($record) => $record->status->value === \App\Enums\TaskStatusEnum::COMPLETED->value ? 'success' : 'warning'),
                                        Infolists\Components\TextEntry::make('sla_limit')
                                            ->label(__('ui.sla_limit'))
                                            ->getStateUsing(function ($record) {
                                                $policy = \App\Models\SlaPolicy::where('unit_id', $record->unit_id)
                                                    ->where('area_id', $record->area_id)
                                                    ->where('priority', $record->priority)
                                                    ->first();

                                                return $policy ? ($policy->deadline_minutes >= 60 ? round($policy->deadline_minutes / 60, 1) . ' Saat' : $policy->deadline_minutes . ' Dakika') : '-';
                                            })
                                            ->badge()->color('info')->icon('heroicon-o-flag'),
                                        Infolists\Components\TextEntry::make('canli_sla_durumu')
                                            ->hidden()
                                            ->label(__('ui.sla_status'))
                                            ->badge()
                                            ->getStateUsing(function ($record) {
                                                // Senin modelinde yeni gördüğüm calculateSlaStatus metodunu çağırıyoruz
                                                // Bu metod içindeki diffInMinutes(now()) sayesinde sonuç ARTIK CANLI.
                                                $status = $record->calculateSlaStatus();

                                                return match($status) {
                                                    'SUCCESS' => __('ui.on_time'),
                                                    'FAILED' => __('ui.breached'),
                                                    'NO_POLICY' => 'Politika Yok',
                                                    default => '-',
                                                };
                                            })
                                            ->color(function ($state) {
                                                return match($state) {
                                                    __('ui.on_time') => 'success',
                                                    __('ui.breached') => 'danger',
                                                    default => 'gray',
                                                };
                                            })
                                            ->icon(fn ($state) => $state === __('ui.on_time') ? 'heroicon-m-check-badge' : 'heroicon-m-x-circle'),
                                    ]),
                                Infolists\Components\Fieldset::make(__('ui.fault_location_and_date_information'))
                                    ->columns(3)
                                    ->schema([
                                        Infolists\Components\TextEntry::make('type_id')
                                            ->label(__('ui.type')),
                                        Infolists\Components\TextEntry::make('area.company.name')
                                            ->label(__('ui.company'))
                                            ->icon('heroicon-o-building-library'),
                                        Infolists\Components\TextEntry::make('area.name')
                                            ->label(__('ui.area'))
                                            ->icon('heroicon-o-map'),
                                        Infolists\Components\TextEntry::make('subArea.name')
                                            ->label(__('ui.sub_area'))
                                            ->icon('heroicon-o-map-pin'),
                                        Infolists\Components\TextEntry::make('task_date')
                                            ->label(__('ui.fault_date'))
                                            ->icon('heroicon-o-calendar-days')
                                            ->date()
                                            ->badge()
                                            ->color('primary'),
                                    ]),
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
                                                            ->hidden()
                                                            ->visible(fn ($record) => $record->status->isNot(\App\Enums\TaskStatusEnum::COMPLETED))
                                                            ->label(__('ui.waiting_time'))
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
                                                    ->columns(4)
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
                                                            ->label(__('ui.waiting_time'))
                                                            ->getStateUsing(function ($record) {
                                                                $start = $record->created_at;

                                                                if (!$start) return null;

                                                                // Bitiş noktası: Tamamlandıysa due_date (saatiyle birlikte), değilse şu an (now)
                                                                // endOfDay() kaldırıldı, böylece tablo ile aynı net farkı hesaplar.
                                                                $end = ($record->status->value === \App\Enums\TaskStatusEnum::COMPLETED->value && $record->due_date)
                                                                    ? $record->due_date
                                                                    : now();

                                                                return $start->diffForHumans($end, [
                                                                    'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE,
                                                                    'parts' => 3, // Tabloyla tam uyum için burayı 2 de yapabilirsiniz
                                                                    'join' => ' ',
                                                                ]);
                                                            })
                                                            ->badge()
                                                            ->color(fn ($record) => $record->status->value === \App\Enums\TaskStatusEnum::COMPLETED->value ? 'success' : 'warning'),
                                                        Infolists\Components\TextEntry::make('sla_limit')
                                                            ->label(__('ui.sla_limit'))
                                                            ->getStateUsing(function ($record) {
                                                                $policy = \App\Models\SlaPolicy::where('unit_id', $record->unit_id)
                                                                    ->where('area_id', $record->area_id)
                                                                    ->where('priority', $record->priority)
                                                                    ->first();

                                                                return $policy ? ($policy->deadline_minutes >= 60 ? round($policy->deadline_minutes / 60, 1) . ' Saat' : $policy->deadline_minutes . ' Dakika') : '-';
                                                            })
                                                            ->badge()->color('info')->icon('heroicon-o-flag'),
                                                        Infolists\Components\TextEntry::make('canli_sla_durumu')
                                                            ->hidden()
                                                            ->label(__('ui.sla_status'))
                                                            ->badge()
                                                            ->getStateUsing(function ($record) {
                                                                // Senin modelinde yeni gördüğüm calculateSlaStatus metodunu çağırıyoruz
                                                                // Bu metod içindeki diffInMinutes(now()) sayesinde sonuç ARTIK CANLI.
                                                                $status = $record->calculateSlaStatus();

                                                                return match($status) {
                                                                    'SUCCESS' => __('ui.on_time'),
                                                                    'FAILED' => __('ui.breached'),
                                                                    'NO_POLICY' => 'Politika Yok',
                                                                    default => '-',
                                                                };
                                                            })
                                                            ->color(function ($state) {
                                                                return match($state) {
                                                                    __('ui.on_time') => 'success',
                                                                    __('ui.breached') => 'danger',
                                                                    default => 'gray',
                                                                };
                                                            })
                                                            ->icon(fn ($state) => $state === __('ui.on_time') ? 'heroicon-m-check-badge' : 'heroicon-m-x-circle'),
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
