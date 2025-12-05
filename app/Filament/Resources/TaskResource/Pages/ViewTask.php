<?php

namespace App\Filament\Resources\TaskResource\Pages;

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
                ->icon('heroicon-o-check'),
            Actions\EditAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['updated_by'] = auth()->id();
                    return $data;
                }),
            Actions\DeleteAction::make(),
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
                                Infolists\Components\TextEntry::make('type_id')
                                    ->label(__('ui.type'))
                                    ->badge(),
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
                                    ->formatStateUsing(fn ($state) => Carbon::parse($state)->locale(app()->getLocale())->translatedFormat('d F Y'))
                                    ->badge()
                                    ->color('primary'),
                                Infolists\Components\TextEntry::make('status')
                                    ->label(__('ui.status'))
                                    ->badge(),
//                                Infolists\Components\TextEntry::make('employee.name')
//                                    ->label(__('ui.assigned_to'))
//                                    ->badge()
//                                    ->color('primary')
//                                    ->icon('heroicon-o-user'),
                                Infolists\Components\Fieldset::make(__('ui.description'))
                                    ->schema([
                                        Infolists\Components\TextEntry::make('description')
                                            ->hiddenLabel()
                                            ->formatStateUsing(fn ($state) => nl2br(e($state)))
                                            ->html()
                                            ->columnSpanFull()
                                    ]),
//                                Infolists\Components\Fieldset::make(__('ui.image'))
//                                    ->schema([
//                                        Infolists\Components\ImageEntry::make('media.task_attachments')
//                                            ->hiddenLabel()
//                                            ->visible(fn ($record) => $record->hasMedia('task_attachments'))
//                                            ->getStateUsing(fn ($record) =>
//                                                $record->getMedia('task_attachments')->map->getUrl()
//                                            )
//                                            ->alignCenter()
//                                            ->columnSpanFull(),
//                                    ]),
                                Infolists\Components\Fieldset::make(__('ui.image'))
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
                                            ->formatStateUsing(fn ($state) => Carbon::parse($state)->locale(app()->getLocale())->translatedFormat('d F Y - H:i'))
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
                                            ->formatStateUsing(fn ($state) => Carbon::parse($state)->locale(app()->getLocale())->translatedFormat('d F Y - H:i'))
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
                                            ->formatStateUsing(fn ($state) => Carbon::parse($state)->locale(app()->getLocale())->translatedFormat('d F Y H:i'))
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
