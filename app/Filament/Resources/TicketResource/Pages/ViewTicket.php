<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Enums\TaskStatusEnum;
use App\Filament\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\TicketStatusHistory;
use App\Services\TicketService;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;

class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make(__('ui.ticket_information'))
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('ticket_no')
                                ->label(__('ui.ticket_no'))
                                ->weight('bold')
                                ->copyable(),

                            TextEntry::make('status')
                                ->label(__('ui.status'))
                                ->badge()
                                ->color(fn (TaskStatusEnum $state) => $state->getColor()),

                            TextEntry::make('priority')
                                ->label(__('ui.priority'))
                                ->badge()
                                ->color(fn ($state) => $state->getColor()),

                            TextEntry::make('type_id')
                                ->label(__('ui.type'))
                                ->badge(),

                            TextEntry::make('area.name')
                                ->label(__('ui.area')),

                            TextEntry::make('subArea.name')
                                ->label(__('ui.sub_area')),

                            TextEntry::make('unit.name')
                                ->label(__('ui.unit')),

                            TextEntry::make('employee.name')
                                ->label(__('ui.assigned_employee')),

                            TextEntry::make('task_date')
                                ->label(__('ui.task_date'))
                                ->date(),
                        ]),

                        TextEntry::make('description')
                            ->label(__('ui.description'))
                            ->columnSpanFull(),
                    ]),

                // SLA Section
                Section::make(__('ui.sla_information'))
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('sla_deadline')
                                ->label(__('ui.sla_deadline'))
                                ->dateTime()
                                ->color(fn (Ticket $record): string =>
                                    !$record->sla_deadline ? 'gray' :
                                    ($record->sla_breached ? 'danger' :
                                    (($record->sla_percent_remaining ?? 100) > 50 ? 'success' : 'warning'))
                                ),

                            TextEntry::make('sla_breached')
                                ->label(__('ui.sla_breached'))
                                ->badge()
                                ->formatStateUsing(fn (bool $state) => $state ? __('ui.sla_breached') : __('ui.on_time'))
                                ->color(fn (bool $state) => $state ? 'danger' : 'success'),

                            TextEntry::make('sla_percent_remaining')
                                ->label(__('ui.sla_remaining'))
                                ->formatStateUsing(fn (Ticket $record): string => self::renderSlaBar($record))
                                ->html(),
                        ]),
                    ])
                    ->visible(fn (Ticket $record) => $record->sla_deadline !== null),

                // Status Timeline
                Section::make(__('ui.status_history'))
                    ->schema([
                        TextEntry::make('statusHistories')
                            ->label('')
                            ->formatStateUsing(fn (Ticket $record): HtmlString => self::renderTimeline($record))
                            ->html()
                            ->columnSpanFull(),
                    ]),

                // Closure info
                Section::make(__('ui.closure_info'))
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('assigned_at')->label(__('ui.assigned_at'))->dateTime(),
                            TextEntry::make('resolved_at')->label(__('ui.resolved_at'))->dateTime(),
                            TextEntry::make('closed_at')->label(__('ui.closed_at'))->dateTime(),
                        ]),
                    ])
                    ->visible(fn (Ticket $r) => $r->assigned_at || $r->resolved_at || $r->closed_at),
            ]);
    }

    protected function getHeaderActions(): array
    {
        $record = $this->getRecord();

        return [
            Actions\EditAction::make()
                ->visible(fn () => auth()->user()->can('update', $this->getRecord())),

            // Status change action (permission-gated)
            Actions\Action::make('change_status')
                ->label(__('ui.change_status'))
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () =>
                    auth()->user()->can('update', $this->getRecord())
                    && !$this->getRecord()->status?->isClosed()
                )
                ->form([
                    Select::make('status')
                        ->label(__('ui.to_status'))
                        ->options(fn () => $this->getAllowedTransitions())
                        ->required(),

                    Textarea::make('note')
                        ->label(__('ui.note'))
                        ->rows(2),
                ])
                ->action(function (array $data) {
                    $ticket  = $this->getRecord();
                    $service = app(TicketService::class);
                    $newStatus = TaskStatusEnum::from((int) $data['status']);

                    try {
                        $service->transition($ticket, $newStatus, auth()->user(), $data['note'] ?? null);

                        Notification::make()
                            ->title(__('ui.status_change'))
                            ->success()
                            ->send();

                        $this->refreshFormData(['status', 'closed_at', 'assigned_at', 'resolved_at', 'sla_breached']);
                    } catch (\Illuminate\Validation\ValidationException $e) {
                        Notification::make()
                            ->title(collect($e->errors())->flatten()->first())
                            ->danger()
                            ->send();
                    }
                }),

            // Add note without status change
            Actions\Action::make('add_note')
                ->label(__('ui.add_note'))
                ->icon('heroicon-o-chat-bubble-left')
                ->color('gray')
                ->form([
                    Textarea::make('note')
                        ->label(__('ui.note'))
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data) {
                    $service = app(TicketService::class);
                    $service->addNote($this->getRecord(), auth()->user(), $data['note']);

                    Notification::make()
                        ->title(__('ui.add_note'))
                        ->success()
                        ->send();
                }),

            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()->can('delete', $this->getRecord())),
        ];
    }

    private function getAllowedTransitions(): array
    {
        $current = $this->getRecord()->status?->value;

        $map = [
            TaskStatusEnum::OPEN->value        => [TaskStatusEnum::ASSIGNED, TaskStatusEnum::CANCELLED],
            TaskStatusEnum::ASSIGNED->value    => [TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::ON_HOLD, TaskStatusEnum::CANCELLED],
            TaskStatusEnum::IN_PROGRESS->value => [TaskStatusEnum::RESOLVED, TaskStatusEnum::ON_HOLD, TaskStatusEnum::CANCELLED],
            TaskStatusEnum::ON_HOLD->value     => [TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::CANCELLED],
            TaskStatusEnum::RESOLVED->value    => [TaskStatusEnum::CLOSED, TaskStatusEnum::IN_PROGRESS],
            TaskStatusEnum::PENDING->value     => [TaskStatusEnum::COMPLETED, TaskStatusEnum::OPEN],
        ];

        $allowed = $map[$current] ?? [];

        // Close action requires ticket.close permission
        if (!auth()->user()->can('ticket.close')) {
            $allowed = array_filter($allowed, fn ($s) =>
                !in_array($s, [TaskStatusEnum::CLOSED, TaskStatusEnum::COMPLETED])
            );
        }

        return collect($allowed)
            ->mapWithKeys(fn (TaskStatusEnum $s) => [$s->value => $s->getLabel()])
            ->toArray();
    }

    private static function renderSlaBar(Ticket $record): string
    {
        $pct = $record->sla_percent_remaining;

        if ($pct === null) {
            return '—';
        }

        $color = $record->sla_breached || $pct <= 0 ? '#ef4444'
            : ($pct <= 50 ? '#f59e0b' : '#22c55e');

        $display = max(0, round($pct));

        return <<<HTML
            <div style="width:100%;background:#e5e7eb;border-radius:4px;height:10px;">
                <div style="width:{$display}%;background:{$color};height:10px;border-radius:4px;transition:width 0.3s;"></div>
            </div>
            <small style="color:{$color};">{$display}% remaining</small>
        HTML;
    }

    private static function renderTimeline(Ticket $record): HtmlString
    {
        $histories = TicketStatusHistory::where('ticket_id', $record->id)
            ->with('changedBy')
            ->orderBy('created_at', 'asc')
            ->get();

        if ($histories->isEmpty()) {
            return new HtmlString('<p class="text-gray-400">—</p>');
        }

        $html = '<div class="space-y-3">';

        foreach ($histories as $h) {
            $from = $h->from_status?->getLabel() ?? '—';
            $to   = $h->to_status?->getLabel() ?? '—';
            $by   = $h->changedBy?->name ?? '—';
            $at   = $h->created_at?->format('d M Y H:i') ?? '—';
            $note = $h->note ? '<p class="text-xs text-gray-500 mt-1">' . e($h->note) . '</p>' : '';

            if ($h->to_status === null) {
                // Comment-only entry
                $html .= <<<HTML
                    <div class="border-l-2 border-gray-300 pl-3">
                        <p class="text-sm font-medium">💬 {$by} <span class="text-xs text-gray-400">{$at}</span></p>
                        {$note}
                    </div>
                HTML;
            } else {
                $html .= <<<HTML
                    <div class="border-l-2 border-blue-400 pl-3">
                        <p class="text-sm font-medium">{$from} → {$to} <span class="text-xs text-gray-400">by {$by} • {$at}</span></p>
                        {$note}
                    </div>
                HTML;
            }
        }

        $html .= '</div>';

        return new HtmlString($html);
    }
}
