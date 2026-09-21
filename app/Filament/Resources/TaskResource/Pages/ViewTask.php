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
                                    ->date()
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
                                    ->visible(fn ($record) => $record->hasMedia('task_attachments'))
                                    ->schema(fn ($record) => $record->getMedia('task_attachments')
                                        ->map(function ($media) {
                                            $url = $media->getUrl();
                                            $alt = e($media->file_name ?? '');

                                            return Infolists\Components\ImageEntry::make('task_image_'.$media->id)
                                                ->hiddenLabel()
                                                ->state($url)
                                                ->size(120)
                                                ->square()
                                                ->extraImgAttributes(['style' => 'cursor:zoom-in;box-shadow:0 0 0 1px rgba(0,0,0,.15);transition:transform .1s;', 'loading' => 'lazy'])
                                                ->action(
                                                    Infolists\Components\Actions\Action::make('viewImage_'.$media->id)
                                                        ->modalHeading($alt !== '' ? $alt : __('ui.image'))
                                                        ->modalContent(fn () => new \Illuminate\Support\HtmlString(
                                                            '<img src="'.e($url).'" alt="'.$alt.'" style="max-width:100%;max-height:75vh;object-fit:contain;display:block;margin:0 auto;border-radius:8px;" />'
                                                        ))
                                                        ->modalSubmitAction(false)
                                                        ->modalCancelAction(false)
                                                        ->modalWidth('4xl')
                                                );
                                        })
                                        ->values()
                                        ->all())
                                    ->columns(['default' => 3, 'sm' => 4, 'md' => 5, 'lg' => 6]),
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
