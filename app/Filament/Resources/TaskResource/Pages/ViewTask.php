<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Enums\ActiveStatusEnum;
use App\Enums\AssignedPersonTypeEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Fieldset;
use Filament\Infolists;
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
                            'required_with' => __('ui.resolution_notes_required_with_due_date'),
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

                    $record->createdBy->notify(new \App\Notifications\TaskClosed($record));

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
//                                        Infolists\Components\TextEntry::make('employee.name')
//                                            ->label(__('ui.related_person'))
//                                            ->placeholder(__('ui.not_assigned_yet'))
//                                            ->badge()
//                                            ->color('primary')
//                                            ->icon('heroicon-o-user'),
                                        Infolists\Components\TextEntry::make('assigned_person')
                                            ->label(__('ui.related_person'))
                                            ->placeholder(__('ui.not_assigned_yet'))
                                            ->badge()
                                            ->color('primary')
                                            ->icon('heroicon-o-user')
                                            ->getStateUsing(function ($record) {
                                                if ($record->assigned_person_type_id === AssignedPersonTypeEnum::EMPLOYEE) {
                                                    return $record->employee?->name;
                                                } elseif ($record->assigned_person_type_id === AssignedPersonTypeEnum::SUBCONTRACTOR) {
                                                    return $record->subcontractorEmployee?->name;
                                                }
                                                return null;
                                            }),
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
                                    ->visible(fn ($record) => $record->status === \App\Enums\TaskStatusEnum::COMPLETED)
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
                                        Infolists\Components\Fieldset::make(__('ui.resolution_notes'))
                                            ->schema([
                                                Infolists\Components\TextEntry::make('resolution_notes')
                                                    ->hiddenLabel()
                                                    ->formatStateUsing(fn ($state) => nl2br(e($state)))
                                                    ->html()
                                                    ->columnSpanFull()
                                            ]),
                                    ]),
                            ])->columns(3),
                    ]),
            ]);
    }
}
