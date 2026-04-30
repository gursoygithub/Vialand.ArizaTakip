<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Enums\TaskStatusEnum;
use App\Exceptions\TicketTransitionException;
use App\Filament\Resources\TicketResource;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\TicketStatusHistory;
use App\Services\SlaService;
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

    public function getTitle(): string
    {
        return $this->getRecord()->ticket_no ?? __('ui.ticket_detail');
    }

    public function getHeading(): string
    {
        return $this->getRecord()->ticket_no ?? __('ui.ticket_detail');
    }

    /**
     * Permissions required by each action button.
     * Per spec:
     *   Ata          → ticket.assign
     *   Kapat        → ticket.close
     *   Yeniden Aç   → ticket.reopen
     *   İptal Et     → ticket.close
     *   Beklemede    → ticket.assign
     *   İşleme Al    → assigned employee OR ticket.assign
     */
    private const ACTION_PERMISSION = [
        TaskStatusEnum::ASSIGNED->value    => 'ticket.assign',
        TaskStatusEnum::IN_PROGRESS->value => null, // open to assigned employee or ticket.assign
        TaskStatusEnum::ON_HOLD->value     => 'ticket.assign',
        TaskStatusEnum::RESOLVED->value    => null, // assigned employee can resolve
        TaskStatusEnum::CLOSED->value      => 'ticket.close',
        TaskStatusEnum::CANCELLED->value   => 'ticket.close',
        TaskStatusEnum::COMPLETED->value   => 'ticket.close',
    ];

    private const ACTION_LABEL = [
        TaskStatusEnum::ASSIGNED->value    => 'Ata',
        TaskStatusEnum::IN_PROGRESS->value => 'İşleme Al',
        TaskStatusEnum::ON_HOLD->value     => 'Beklemede',
        TaskStatusEnum::RESOLVED->value    => 'Çözüldü',
        TaskStatusEnum::CLOSED->value      => 'Kapat',
        TaskStatusEnum::CANCELLED->value   => 'İptal Et',
        TaskStatusEnum::COMPLETED->value   => 'Tamamla',
    ];

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                // ── HEADER ──
                Section::make()
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('ticket_no')
                                ->label(__('ui.ticket_no'))
                                ->size(TextEntry\TextEntrySize::Large)
                                ->weight('bold')
                                ->copyable(),

                            TextEntry::make('status')
                                ->label(__('ui.status'))
                                ->badge()
                                ->color(fn (TaskStatusEnum $state) => $state->getColor())
                                ->icon(fn (TaskStatusEnum $state) => $state->getIcon()),

                            TextEntry::make('priority')
                                ->label(__('ui.priority'))
                                ->badge()
                                ->color(fn ($state) => $state->getColor())
                                ->icon(fn ($state) => $state->getIcon()),

                            TextEntry::make('sla_progress')
                                ->label(__('ui.sla_indicator'))
                                ->formatStateUsing(fn (Ticket $record) => self::renderSlaProgress($record))
                                ->html(),
                        ]),

                        Grid::make(3)->schema([
                            TextEntry::make('createdBy.name')
                                ->label(__('ui.created_by')),

                            TextEntry::make('created_at')
                                ->label(__('ui.created_at'))
                                ->dateTime(),

                            TextEntry::make('employee.name')
                                ->label(__('ui.assigned_employee'))
                                ->placeholder('—')
                                ->badge()
                                ->color('warning'),
                        ]),
                    ]),

                // ── TICKET DETAIL ──
                Section::make(__('ui.ticket_information'))
                    ->collapsible()
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('type_id')->label(__('ui.type'))->badge(),
                            TextEntry::make('area.name')->label(__('ui.area')),
                            TextEntry::make('subArea.name')->label(__('ui.sub_area'))->placeholder('—'),
                            TextEntry::make('unit.name')->label(__('ui.unit')),
                            TextEntry::make('group.name')->label(__('ui.group'))->placeholder('—'),
                            TextEntry::make('task_date')->label(__('ui.task_date'))->date(),
                        ]),

                        TextEntry::make('description')
                            ->label(__('ui.description'))
                            ->columnSpanFull(),
                    ]),

                // ── SLA & TIMESTAMPS ──
                Section::make(__('ui.sla_information'))
                    ->collapsible()
                    ->visible(fn (Ticket $record) => $record->sla_deadline !== null
                        || $record->assigned_at || $record->resolved_at || $record->closed_at)
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('sla_deadline')->label(__('ui.sla_deadline'))->dateTime()->placeholder('—'),
                            TextEntry::make('assigned_at')->label(__('ui.assigned_at'))->dateTime()->placeholder('—'),
                            TextEntry::make('resolved_at')->label(__('ui.resolved_at'))->dateTime()->placeholder('—'),
                            TextEntry::make('closed_at')->label(__('ui.closed_at'))->dateTime()->placeholder('—'),
                        ]),

                        Grid::make(2)->schema([
                            TextEntry::make('total_on_hold_minutes')
                                ->label('Toplam Bekleme Süresi')
                                ->formatStateUsing(fn ($state) => ((int) $state) . ' dk')
                                ->visible(fn (Ticket $record) => (int) $record->total_on_hold_minutes > 0),

                            TextEntry::make('sla_breached')
                                ->label(__('ui.sla_breached'))
                                ->badge()
                                ->formatStateUsing(fn ($state) => $state ? __('ui.sla_breached') : __('ui.on_time'))
                                ->color(fn ($state) => $state ? 'danger' : 'success')
                                ->visible(fn (Ticket $record) => $record->status?->isClosed()),
                        ]),
                    ]),

                // ── TALEP GEÇMİŞİ — always visible, not collapsible ──
                Section::make('Talep Geçmişi')
                    ->icon('heroicon-o-clock')
                    ->description('Bu talep üzerinde yapılan tüm durum değişiklikleri ve yorumlar (eskiden yeniye).')
                    ->schema([
                        TextEntry::make('timeline')
                            ->label('')
                            ->formatStateUsing(fn (Ticket $record) => self::renderTimeline($record))
                            ->html()
                            ->columnSpanFull(),
                    ]),

                // ── ATTACHMENTS ──
                Section::make(__('ui.images'))
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        \Filament\Infolists\Components\SpatieMediaLibraryImageEntry::make('task_attachments')
                            ->label('')
                            ->collection('task_attachments')
                            ->disk('s3')
                            ->columnSpanFull()
                            ->placeholder('Henüz dosya eklenmemiş'),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        $ticket = $this->getRecord();

        return [
            // STATUS TRANSITION ACTIONS — one button per allowed next status
            ...$this->buildTransitionActions($ticket),

            // ADD COMMENT
            Actions\Action::make('add_comment')
                ->label(__('ui.add_note'))
                ->icon('heroicon-o-chat-bubble-left')
                ->color('gray')
                ->form([
                    Textarea::make('note')->label(__('ui.note'))->rows(3)->required(),
                ])
                ->action(function (array $data) use ($ticket) {
                    app(TicketService::class)->addComment($ticket, auth()->user(), $data['note']);
                    Notification::make()->title('Yorum eklendi')->success()->send();
                }),

            // REASSIGN
            Actions\Action::make('reassign')
                ->label('Yeniden Ata')
                ->icon('heroicon-o-user-plus')
                ->color('warning')
                ->visible(fn () => auth()->user()?->can('ticket.assign')
                    && !$ticket->status?->isClosed())
                ->form([
                    Select::make('employee_id')
                        ->label(__('ui.assigned_employee'))
                        ->options(function () use ($ticket) {
                            return Employee::query()
                                ->whereHas('groupMemberships.group', fn ($q) =>
                                    $q->where('area_id', $ticket->area_id)
                                      ->when($ticket->unit_id, fn ($q2, $u) => $q2->where('unit_id', $u))
                                )
                                ->pluck('name', 'id');
                        })
                        ->searchable()
                        ->required(),
                    Textarea::make('note')->label(__('ui.note'))->rows(2),
                ])
                ->action(function (array $data) use ($ticket) {
                    $ticket->update(['employee_id' => $data['employee_id']]);
                    if ($ticket->status === TaskStatusEnum::OPEN) {
                        try {
                            app(TicketService::class)->transition(
                                $ticket->fresh(),
                                TaskStatusEnum::ASSIGNED,
                                auth()->user(),
                                $data['note'] ?? null
                            );
                        } catch (TicketTransitionException $e) {
                            // already not open — fine
                        }
                    }
                    Notification::make()->title('Bilet yeniden atandı')->success()->send();
                }),

            Actions\EditAction::make()
                ->visible(fn () => auth()->user()?->can('update', $ticket)),

            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()?->can('delete', $ticket)),
        ];
    }

    /**
     * Build one Filament Action per allowed next status, gated by the
     * permission required for that status type.
     */
    private function buildTransitionActions(Ticket $ticket): array
    {
        $service = app(TicketService::class);
        $next    = $service->allowedNextStatuses($ticket->status);
        $actions = [];

        foreach ($next as $to) {
            $required = self::ACTION_PERMISSION[$to->value] ?? null;
            $label    = self::ACTION_LABEL[$to->value] ?? $to->getLabel();

            // "İşleme Al" / "Çözüldü" — open to assigned employee or anyone with ticket.assign
            $allowedFn = function () use ($required, $to, $ticket) {
                $user = auth()->user();
                if (!$user) return false;
                if ($required && $user->can($required)) return true;

                // Re-open special-case: ticket.reopen permission
                if ($to === TaskStatusEnum::IN_PROGRESS
                    && in_array($ticket->status, [
                        TaskStatusEnum::CLOSED, TaskStatusEnum::COMPLETED, TaskStatusEnum::RESOLVED
                    ], true)) {
                    return $user->can('ticket.reopen') || $user->can('can_reopen_task');
                }

                // assigned employee can move to in_progress / resolved
                if (in_array($to, [TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::RESOLVED], true)) {
                    if ($user->can('ticket.assign')) return true;
                    return $ticket->employee?->email === $user->email;
                }

                return false;
            };

            // Override label for reopen
            if ($to === TaskStatusEnum::IN_PROGRESS
                && in_array($ticket->status, [TaskStatusEnum::CLOSED, TaskStatusEnum::COMPLETED], true)) {
                $label = 'Yeniden Aç';
            }

            $actions[] = Actions\Action::make('to_' . $to->value)
                ->label($label)
                ->icon($to->getIcon())
                ->color($to->getColor())
                ->visible($allowedFn)
                ->form([
                    Textarea::make('note')->label(__('ui.note'))->rows(2),
                ])
                ->requiresConfirmation()
                ->action(function (array $data) use ($ticket, $to) {
                    try {
                        app(TicketService::class)->transition(
                            $ticket,
                            $to,
                            auth()->user(),
                            $data['note'] ?? null
                        );
                        Notification::make()
                            ->title($ticket->ticket_no . ' → ' . $to->getLabel())
                            ->success()
                            ->send();
                    } catch (TicketTransitionException $e) {
                        Notification::make()
                            ->title($e->getMessage())
                            ->danger()
                            ->send();
                    }
                });
        }

        return $actions;
    }

    /**
     * Render the SLA progress bar — green / yellow / red based on remaining time.
     * on_hold tickets always render as paused — never show overdue/remaining.
     */
    private static function renderSlaProgress(Ticket $record): HtmlString
    {
        // 1. on_hold — clock paused, never show countdown or breach
        if ($record->status === TaskStatusEnum::ON_HOLD) {
            return new HtmlString(
                '<div style="background:#e5e7eb;border-radius:6px;height:10px;overflow:hidden;">'
                . '<div style="width:0;background:#9ca3af;height:10px;"></div></div>'
                . '<div style="margin-top:4px;color:#6b7280;font-size:0.85em;font-weight:500;">⏸ Duraklatıldı</div>'
            );
        }

        // 2. no SLA policy
        if (!$record->sla_deadline) {
            return new HtmlString('<span class="text-gray-400">SLA tanımlı değil</span>');
        }

        $sla        = app(SlaService::class);
        $elapsedPct = $sla->getElapsedPercentage($record);
        $remaining  = $sla->getRemainingMinutes($record);
        $breached   = $record->sla_breached || ($remaining !== null && $remaining < 0);

        $widthPct = (int) round(min(100, max(0, ($elapsedPct ?? 0) * 100)));
        $color    = $breached ? '#ef4444'
            : (($elapsedPct ?? 0) >= 0.5 ? '#f59e0b' : '#22c55e');

        if ($breached) {
            $absMins = abs((int) $remaining);
            $h = intdiv($absMins, 60); $m = $absMins % 60;
            $label = "{$h}s {$m}d gecikmiş";
        } elseif ($remaining !== null) {
            $h = intdiv($remaining, 60); $m = $remaining % 60;
            $label = "{$h}s {$m}d kaldı";
        } else {
            $label = '—';
        }

        return new HtmlString(<<<HTML
            <div style="width:100%">
                <div style="background:#e5e7eb;border-radius:6px;height:10px;overflow:hidden;">
                    <div style="width:{$widthPct}%;background:{$color};height:10px;transition:width 0.3s;"></div>
                </div>
                <div style="margin-top:4px;color:{$color};font-size:0.85em;font-weight:500;">{$label}</div>
            </div>
        HTML);
    }

    /**
     * Vertical timeline of status transitions + comments for a ticket.
     * Order is oldest → newest so the reader follows the lifecycle top-down.
     * Comments (where from_status === to_status) get the 💬 styling and an
     * "X dakika içinde düzenlenebilir" hint when within the edit window for
     * the comment's own author.
     */
    private static function renderTimeline(Ticket $record): HtmlString
    {
        $service = app(TicketService::class);
        $entries = TicketStatusHistory::where('ticket_id', $record->id)
            ->with('changedBy')
            ->orderBy('created_at', 'asc')
            ->get();

        if ($entries->isEmpty()) {
            return new HtmlString(
                '<div style="padding:16px;text-align:center;color:#6b7280;font-size:0.9em;">'
                . 'Henüz geçmiş kaydı bulunmuyor.'
                . '</div>'
            );
        }

        $user = auth()->user();
        $html = '<div style="position:relative;padding-left:28px;">';
        // Vertical guide line for the whole timeline column.
        $html .= '<div style="position:absolute;left:11px;top:6px;bottom:6px;width:2px;background:#e5e7eb;border-radius:1px;"></div>';

        foreach ($entries as $entry) {
            $isComment = $entry->from_status?->value === $entry->to_status?->value
                && $entry->from_status !== null;
            $author    = e($entry->changedBy?->name ?? '—');
            $when      = $entry->created_at?->format('d M Y H:i') ?? '';
            $note      = $entry->note ? '<div style="margin-top:6px;color:#374151;line-height:1.5;">' . e($entry->note) . '</div>' : '';

            if ($isComment) {
                $editable    = $user && $service->canEditComment($entry, $user);
                $minutesLeft = null;
                if ($editable && $entry->created_at) {
                    $minutesLeft = max(0, 10 - (int) $entry->created_at->diffInMinutes(now()));
                }
                $editTag = $editable && $minutesLeft !== null && $minutesLeft > 0
                    ? '<span style="font-size:0.75em;color:#6b7280;margin-left:6px;">' . $minutesLeft . ' dakika içinde düzenlenebilir</span>'
                    : '';

                $dotColor = '#9ca3af';
                $html .= <<<HTML
                    <div style="position:relative;margin-bottom:18px;">
                        <div style="position:absolute;left:-22px;top:2px;width:20px;height:20px;border-radius:50%;background:#fff;border:2px solid {$dotColor};display:flex;align-items:center;justify-content:center;font-size:11px;">💬</div>
                        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;">
                            <div style="font-weight:600;color:#111827;">{$author} <span style="font-weight:400;color:#6b7280;font-size:0.85em;">• {$when}</span>{$editTag}</div>
                            {$note}
                        </div>
                    </div>
                HTML;
            } else {
                $fromLabel = e($entry->from_status?->getLabel() ?? '—');
                $toLabel   = e($entry->to_status?->getLabel() ?? '—');
                $toColor   = match (true) {
                    $entry->to_status === TaskStatusEnum::CANCELLED => '#ef4444',
                    $entry->to_status === TaskStatusEnum::CLOSED, $entry->to_status === TaskStatusEnum::COMPLETED => '#10b981',
                    $entry->to_status === TaskStatusEnum::ON_HOLD   => '#f59e0b',
                    $entry->to_status === TaskStatusEnum::RESOLVED  => '#22c55e',
                    default => '#3b82f6',
                };
                $html .= <<<HTML
                    <div style="position:relative;margin-bottom:18px;">
                        <div style="position:absolute;left:-22px;top:2px;width:20px;height:20px;border-radius:50%;background:{$toColor};border:2px solid #fff;box-shadow:0 0 0 2px {$toColor}33;"></div>
                        <div style="background:#fff;border:1px solid #e5e7eb;border-left:3px solid {$toColor};border-radius:8px;padding:10px 12px;">
                            <div style="font-weight:600;color:#111827;">
                                <span style="background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:4px;font-size:0.85em;">{$fromLabel}</span>
                                <span style="margin:0 6px;color:#6b7280;">→</span>
                                <span style="background:{$toColor}22;color:{$toColor};padding:2px 8px;border-radius:4px;font-size:0.85em;font-weight:700;">{$toLabel}</span>
                            </div>
                            <div style="font-size:0.85em;color:#6b7280;margin-top:4px;">{$author} • {$when}</div>
                            {$note}
                        </div>
                    </div>
                HTML;
            }
        }

        $html .= '</div>';
        return new HtmlString($html);
    }
}
