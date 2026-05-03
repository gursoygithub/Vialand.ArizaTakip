<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Enums\TaskStatusEnum;
use App\Exceptions\TicketTransitionException;
use App\Filament\Resources\TicketResource;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\TicketMute;
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
use Filament\Support\Enums\FontWeight;
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
                    ->extraAttributes(['class' => 'rounded-xl'])
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('ticket_no')
                                ->label(__('ui.ticket_no'))
                                ->icon('heroicon-o-ticket')
                                ->iconColor('primary')
                                ->size(TextEntry\TextEntrySize::Large)
                                ->weight(FontWeight::Bold)
                                ->copyable()
                                ->copyMessage('Talep no kopyalandı'),

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
                        ]),

                        Grid::make(3)->schema([
                            TextEntry::make('createdBy.name')
                                ->label('Oluşturan')
                                ->icon('heroicon-o-user'),

                            TextEntry::make('created_at')
                                ->label('Oluşturulma Tarihi')
                                ->icon('heroicon-o-calendar')
                                ->dateTime(),

                            TextEntry::make('employee.name')
                                ->label('Atanan Personel')
                                ->icon('heroicon-o-user-circle')
                                ->iconColor('warning')
                                ->placeholder('—')
                                ->badge()
                                ->color('warning'),
                        ]),

                        Grid::make(3)->schema([
                            TextEntry::make('area.name')
                                ->label('Bölge')
                                ->icon('heroicon-o-map-pin'),

                            TextEntry::make('subArea.name')
                                ->label('Lokasyon')
                                ->icon('heroicon-o-map')
                                ->placeholder('—'),

                            TextEntry::make('group.name')
                                ->label('Grup')
                                ->icon('heroicon-o-user-group')
                                ->placeholder('—'),
                        ]),
                    ]),

                // ── TICKET DETAIL ──
                Section::make(__('ui.ticket_information'))
                    ->icon('heroicon-o-clipboard-document-list')
                    ->extraAttributes(['class' => 'rounded-xl'])
                    ->collapsible()
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('unit.name')
                                ->label('Birim')
                                ->icon('heroicon-o-wrench-screwdriver'),

                            TextEntry::make('task_date')
                                ->label('Arıza Tarihi')
                                ->icon('heroicon-o-calendar-days')
                                ->date(),
                        ]),

                        TextEntry::make('description')
                            ->label('Açıklama')
                            ->icon('heroicon-o-document-text')
                            ->prose()
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'bg-gray-50 dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700']),
                    ]),

                // ── SLA & TIMESTAMPS ──
                Section::make(__('ui.sla_information'))
                    ->icon('heroicon-o-clock')
                    ->extraAttributes(['class' => 'rounded-xl'])
                    ->collapsible()
                    ->visible(fn (Ticket $record) => $record->sla_deadline !== null)
                    ->schema([
                        // Atanma Tarihi removed — duplicates the timeline's
                        // ASSIGNED row and is irrelevant for tickets that
                        // bypass the ASSIGNED status entirely.
                        Grid::make(3)->schema([
                            TextEntry::make('sla_deadline')
                                ->label('SLA Son Tarihi')
                                ->icon('heroicon-o-clock')
                                ->color(fn (Ticket $record) => $record->sla_breached ? 'danger' : 'success')
                                ->dateTime()
                                ->placeholder('—'),

                            TextEntry::make('resolved_at')
                                ->label('Çözüm Tarihi')
                                ->icon('heroicon-o-check-circle')
                                ->iconColor('success')
                                ->dateTime()
                                ->placeholder('—'),

                            TextEntry::make('closed_at')
                                ->label('Kapatma Tarihi')
                                ->icon('heroicon-o-lock-closed')
                                ->iconColor('gray')
                                ->dateTime()
                                ->placeholder('—'),
                        ]),

                        Grid::make(2)->schema([
                            TextEntry::make('total_on_hold_minutes')
                                ->label('Toplam Bekleme Süresi')
                                ->icon('heroicon-o-pause-circle')
                                ->iconColor('gray')
                                ->formatStateUsing(fn ($state) => ((int) $state) . ' dk')
                                ->visible(fn (Ticket $record) => (int) $record->total_on_hold_minutes > 0),

                            // Outcome derived from resolved_at vs sla_deadline
                            // — never read the persisted column for live UI.
                            TextEntry::make('sla_outcome')
                                ->label(__('ui.sla_breached'))
                                ->state(function (Ticket $record): string {
                                    $finalAt  = $record->resolved_at ?? $record->closed_at;
                                    $breached = $finalAt
                                        ? $finalAt->gt($record->sla_deadline)
                                        : now()->gt($record->sla_deadline);
                                    return $breached ? 'İhlal' : 'Zamanında';
                                })
                                ->badge()
                                ->icon(fn (?string $state) => str_contains($state ?? '', 'İhlal')
                                    ? 'heroicon-o-x-circle'
                                    : 'heroicon-o-check-circle')
                                ->color(fn (?string $state) => str_contains($state ?? '', 'İhlal')
                                    ? 'danger'
                                    : 'success')
                                ->visible(fn (Ticket $record) => $record->status?->isClosed() && $record->sla_deadline),
                        ]),
                    ]),

                // ── TALEP GEÇMİŞİ — always visible, not collapsible ──
                Section::make('Talep Geçmişi')
                    ->icon('heroicon-o-clock')
                    ->description('Bu talep üzerinde yapılan tüm durum değişiklikleri ve yorumlar (eskiden yeniye).')
                    ->schema([
                        // ViewEntry — not TextEntry — because TextEntry applies
                        // `prose` typography classes to ->html() output, which
                        // visually constrained the timeline to ~600px even with
                        // width:100% inline. ViewEntry renders the partial blade
                        // directly so we get true full width.
                        \Filament\Infolists\Components\ViewEntry::make('timeline')
                            ->view('filament.partials.ticket-timeline')
                            ->columnSpanFull(),
                    ]),

                // ── ATTACHMENTS ──
                Section::make('Resimler')
                    ->icon('heroicon-o-photo')
                    ->extraAttributes(['class' => 'rounded-xl'])
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

        // Visibility envelope shared across action buttons.
        // - Terminal tickets (resolved/closed/cancelled): non-creators see no
        //   action buttons at all; creator can still reopen via the
        //   buildTransitionActions reopen path.
        // - Active tickets: existing per-action permission checks decide.
        $isCreator      = (int) $ticket->created_by === (int) auth()->id();
        $isTerminal     = in_array($ticket->status, [
            TaskStatusEnum::RESOLVED,
            TaskStatusEnum::CLOSED,
            TaskStatusEnum::COMPLETED,
            TaskStatusEnum::CANCELLED,
        ], true);
        $allowAnyAction = $isCreator || !$isTerminal;

        return [
            // STATUS TRANSITION ACTIONS — one button per allowed next status
            ...$this->buildTransitionActions($ticket, $allowAnyAction, $isCreator),

            // ADD COMMENT
            Actions\Action::make('add_comment')
                ->label(__('ui.add_note'))
                ->icon('heroicon-o-chat-bubble-left')
                ->color('gray')
                ->visible(fn () => $allowAnyAction
                    && ($isCreator || auth()->user()?->can('ticket.assign')))
                ->form([
                    Textarea::make('note')->label(__('ui.note'))->rows(3)->required(),
                ])
                ->action(function (array $data) use ($ticket) {
                    app(TicketService::class)->addComment($ticket, auth()->user(), $data['note']);
                    Notification::make()->title('Yorum eklendi')->success()->send();
                }),

            // ASSIGN / REASSIGN — single action, label depends on current state.
            // Replaces the auto-generated "Ata" status-transition button (the
            // ASSIGNED case is filtered out of buildTransitionActions below)
            // because plain status-flip without an employee picker is useless.
            Actions\Action::make('assign')
                ->label($ticket->employee_id ? 'Yeniden Ata' : 'Ata')
                ->icon('heroicon-o-user-plus')
                ->color('warning')
                ->visible(function () use ($isTerminal, $isCreator): bool {
                    if ($isTerminal) {
                        return false;
                    }
                    $user = auth()->user();
                    if (!$user) {
                        return false;
                    }
                    // The creator opens the ticket; routing to a technician is
                    // a supervisor/admin job, never the creator's. Hide for
                    // the creator unconditionally — even if they also hold
                    // view.all / view.group (a manager opening their own
                    // ticket still doesn't reassign to themselves).
                    if ($isCreator) {
                        return false;
                    }
                    return $user->can('ticket.assign')
                        || $user->hasPermissionTo('ticket.view.all')
                        || $user->hasPermissionTo('ticket.view.group');
                })
                ->form([
                    Select::make('employee_id')
                        ->label(__('ui.assigned_employee'))
                        ->options(function () use ($ticket) {
                            // Prefer group-member filter; fall back to company
                            // employees so an unassigned ticket without a group
                            // can still be staffed.
                            $groupId = $ticket->group_id;

                            if ($groupId) {
                                $byGroup = Employee::query()
                                    ->whereHas('groupMemberships', fn (\Illuminate\Database\Eloquent\Builder $q)
                                        => $q->where('group_id', $groupId))
                                    ->where('status', \App\Enums\ActiveStatusEnum::ACTIVE->value)
                                    ->orderBy('name')
                                    ->pluck('name', 'id');

                                if ($byGroup->isNotEmpty()) {
                                    return $byGroup;
                                }
                            }

                            $companyId = Employee::find($ticket->employee_id)?->company_id
                                ?? \App\Models\Area::find($ticket->area_id)?->company_id;

                            return Employee::query()
                                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                                ->where('status', \App\Enums\ActiveStatusEnum::ACTIVE->value)
                                ->orderBy('name')
                                ->limit(500)
                                ->pluck('name', 'id');
                        })
                        ->searchable()
                        ->required(),
                    Textarea::make('note')->label(__('ui.note'))->rows(2),
                ])
                ->action(function (array $data) use ($ticket) {
                    $hadEmployee = (bool) $ticket->employee_id;

                    app(TicketService::class)->reassign(
                        $ticket,
                        (int) $data['employee_id'],
                        auth()->user(),
                        $data['note'] ?? null,
                    );

                    Notification::make()
                        ->title($hadEmployee ? 'Bilet yeniden atandı' : 'Bilet atandı')
                        ->success()
                        ->send();
                }),

            // PER-TICKET MUTE — toggle is_muted_by(viewer) for this ticket.
            // Visible only to participants (creator / current assignee /
            // anyone who has acted on the ticket via TicketStatusHistory).
            // Non-participants wouldn't receive notifications anyway, so
            // showing them a toggle would be misleading.
            Actions\Action::make('toggleMute')
                ->label(fn () => $this->getRecord()->isMutedBy(auth()->user())
                    ? 'Takibi Aç'
                    : 'Takibi Bırak')
                ->icon(fn () => $this->getRecord()->isMutedBy(auth()->user())
                    ? 'heroicon-o-bell-slash'
                    : 'heroicon-o-bell')
                ->color(fn () => $this->getRecord()->isMutedBy(auth()->user())
                    ? 'gray'
                    : 'success')
                ->outlined(fn () => true)
                ->action(function () {
                    $ticket = $this->getRecord();
                    $user   = auth()->user();
                    $mute   = TicketMute::where('ticket_id', $ticket->id)
                        ->where('user_id', $user->id)
                        ->first();
                    if ($mute) {
                        $mute->delete();
                        Notification::make()->title('Talep takibe alındı.')->success()->send();
                    } else {
                        TicketMute::create(['ticket_id' => $ticket->id, 'user_id' => $user->id]);
                        Notification::make()->title('Talep bildirimleri kapatıldı.')->success()->send();
                    }
                })
                ->visible(function () use ($ticket): bool {
                    $user = auth()->user();
                    if (!$user) {
                        return false;
                    }
                    return (int) $ticket->created_by === (int) $user->id
                        || (int) ($ticket->employee?->user?->id ?? 0) === (int) $user->id
                        || TicketStatusHistory::where('ticket_id', $ticket->id)
                            ->where('changed_by', $user->id)
                            ->exists();
                }),

            Actions\EditAction::make()
                ->visible(function () use ($ticket): bool {
                    // Mirror the mount-time gate in EditTicket so the button
                    // never appears for users the page would 403 anyway.
                    // ticket.view.group is intentionally NOT here — it's a
                    // read scope, not a write scope.
                    $user = auth()->user();
                    if (!$user) {
                        return false;
                    }
                    if (!$user->can('update', $ticket)) {
                        return false;
                    }
                    return $ticket->created_by === $user->id
                        || $user->hasPermissionTo('ticket.view.all');
                }),

            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()?->can('delete', $ticket)),
        ];
    }

    /**
     * Inline note delete. Mounted from the timeline blade via
     * wire:click="mountAction('deleteComment', { history_id: <id> })".
     * Confirmation modal protects against accidental clicks; on confirm
     * calls TicketService::deleteComment which re-checks ownership and
     * the 10-min window.
     */
    public function deleteCommentAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('deleteComment')
            ->label('Notu Sil')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Notu sil?')
            ->modalDescription('Bu notu silmek istediğinizden emin misiniz?')
            ->modalSubmitActionLabel('Evet, sil')
            ->modalCancelActionLabel('Vazgeç')
            ->action(function (array $arguments) {
                $history = TicketStatusHistory::find($arguments['history_id'] ?? null);
                if (!$history) {
                    Notification::make()->title('Yorum bulunamadı')->danger()->send();
                    return;
                }

                try {
                    app(TicketService::class)->deleteComment($history, auth()->user());
                    Notification::make()->title('Yorum silindi')->success()->send();
                } catch (\DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    /**
     * Inline note edit. Mounted from the timeline blade via
     * wire:click="mountAction('editComment', { history_id: <id> })".
     * Pre-fills the textarea with the current note; on submit calls
     * TicketService::updateComment which re-applies the author + 10-min
     * window check (so a stale modal can't bypass the edit window).
     */
    public function editCommentAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('editComment')
            ->label('Yorumu Düzenle')
            ->icon('heroicon-o-pencil')
            ->modalHeading('Yorumu Düzenle')
            ->fillForm(function (array $arguments): array {
                $history = TicketStatusHistory::find($arguments['history_id'] ?? null);
                return ['note' => (string) ($history?->note ?? '')];
            })
            ->form([
                Textarea::make('note')
                    ->label('Yorum')
                    ->rows(4)
                    ->required(),
            ])
            ->action(function (array $arguments, array $data) {
                $history = TicketStatusHistory::find($arguments['history_id'] ?? null);
                if (!$history) {
                    Notification::make()->title('Yorum bulunamadı')->danger()->send();
                    return;
                }

                try {
                    app(TicketService::class)->updateComment($history, $data['note'], auth()->user());
                    Notification::make()->title('Yorum güncellendi')->success()->send();
                } catch (\DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    /**
     * Build one Filament Action per allowed next status, gated by the
     * permission required for that status type.
     *
     * @param bool $allowAnyAction false when the ticket is terminal and the
     *   viewer is not the creator — every transition button is hidden in
     *   that case (the creator can still reopen via the IN_PROGRESS arm).
     * @param bool $isCreator     true when the viewer created the ticket.
     */
    private function buildTransitionActions(Ticket $ticket, bool $allowAnyAction, bool $isCreator): array
    {
        $service = app(TicketService::class);
        $next    = $service->allowedNextStatuses($ticket->status);
        $actions = [];

        foreach ($next as $to) {
            // ASSIGNED is handled by the dedicated Ata/Yeniden Ata header
            // action which also captures employee_id; skip the auto-generated
            // status-only button.
            if ($to === TaskStatusEnum::ASSIGNED) {
                continue;
            }

            $required = self::ACTION_PERMISSION[$to->value] ?? null;
            $label    = self::ACTION_LABEL[$to->value] ?? $to->getLabel();

            // "İşleme Al" / "Çözüldü" — open to assigned employee or anyone with ticket.assign
            $allowedFn = function () use ($required, $to, $ticket, $allowAnyAction, $isCreator) {
                if (!$allowAnyAction) {
                    return false;
                }

                $user = auth()->user();
                if (!$user) return false;
                if ($isCreator) return true;
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

            // Reopen label: any closed-ish status going back to IN_PROGRESS
            // is a reopen, including RESOLVED. The permission check above
            // already requires ticket.reopen for these source statuses.
            if ($to === TaskStatusEnum::IN_PROGRESS
                && in_array($ticket->status, [
                    TaskStatusEnum::CLOSED,
                    TaskStatusEnum::COMPLETED,
                    TaskStatusEnum::RESOLVED,
                ], true)) {
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
     * Public wrapper so the timeline partial can render the HTML.
     * Keeps the implementation private/static while still exposing it.
     */
    public static function renderTimelinePublic(Ticket $record): HtmlString
    {
        return self::renderTimeline($record);
    }

    /**
     * Vertical timeline of a ticket's full history.
     *
     * Three entry shapes:
     *   - Creation     (from_status NULL): "Talep Açıldı" header + creator name
     *   - Transition   (from != to)       : from-pill → to-pill, colored dot, optional note
     *   - Comment      (from == to)       : 💬 "Not Eklendi" header + comment body
     *
     * Author resolution prefers employee.name (the human display) over
     * users.name (often a username from LDAP). Eager loads
     * changedBy.employee so we make one user query and one employee query
     * for all rows together.
     */
    private static function renderTimeline(Ticket $record): HtmlString
    {
        $service = app(TicketService::class);
        $entries = TicketStatusHistory::where('ticket_id', $record->id)
            ->with(['changedBy.employee'])
            ->orderBy('created_at', 'asc')
            ->get();

        if ($entries->isEmpty()) {
            return new HtmlString(
                '<div style="padding:16px;text-align:center;color:#6b7280;font-size:0.9em;">'
                . 'Henüz geçmiş kaydı bulunmuyor.'
                . '</div>'
            );
        }

        $viewer = auth()->user();
        // Full-width timeline; the vertical guide line sits at left:15px and
        // spans top:0 → bottom:0 with extra margin so it visibly connects
        // every entry. Cards take the rest of the row.
        $html = '<div style="position:relative;width:100%;padding-left:36px;">';
        $html .= '<div style="position:absolute;left:14px;top:8px;bottom:8px;width:3px;background:#e5e7eb;border-radius:2px;"></div>';

        $reassignPrefix = \App\Services\TicketService::REASSIGN_NOTE_PREFIX;

        foreach ($entries as $entry) {
            $isCreation = $entry->from_status === null;
            $rawNote    = trim((string) ($entry->note ?? ''));
            $isReassign = !$isCreation
                && $entry->from_status?->value === $entry->to_status?->value
                && str_starts_with($rawNote, $reassignPrefix);
            $isComment  = !$isCreation
                && !$isReassign
                && $entry->from_status?->value === $entry->to_status?->value;

            // Author display: prefer employee.name, fall back to user.name, then '—'.
            $author = e(
                $entry->changedBy?->employee?->name
                ?? $entry->changedBy?->name
                ?? '—'
            );
            $when = $entry->created_at?->translatedFormat('d F Y H:i') ?? '';

            // Note: treat empty string the same as null. Render only if non-empty.
            // The "Ticket created" placeholder from TicketObserver is suppressed
            // for the creation card; the reassign prefix is stripped before display.
            $displayNote = $isReassign
                ? trim(substr($rawNote, strlen($reassignPrefix)))
                : $rawNote;

            $hasNote   = $displayNote !== '' && !($isCreation && $rawNote === 'Ticket created');
            $noteBlock = $hasNote
                ? '<blockquote style="margin:8px 0 0;padding:8px 12px;border-left:3px solid #d1d5db;background:#f9fafb;color:#374151;line-height:1.5;border-radius:0 6px 6px 0;">' . nl2br(e($displayNote)) . '</blockquote>'
                : '';

            if ($isCreation) {
                // 🟡 Talep Açıldı
                $dotColor = '#eab308';
                $html .= <<<HTML
                    <div style="position:relative;margin-bottom:24px;">
                        <div style="position:absolute;left:-30px;top:6px;width:24px;height:24px;border-radius:50%;background:{$dotColor};border:3px solid #fff;box-shadow:0 0 0 2px {$dotColor}33;display:flex;align-items:center;justify-content:center;font-size:12px;">🟡</div>
                        <div style="background:#fff;border:1px solid #e5e7eb;border-left:4px solid {$dotColor};border-radius:8px;padding:14px 16px;width:100%;">
                            <div style="font-weight:700;color:#111827;font-size:1em;">Talep Açıldı</div>
                            <div style="font-size:0.875em;color:#6b7280;margin-top:6px;">{$author} tarafından • {$when}</div>
                            {$noteBlock}
                        </div>
                    </div>
                HTML;
                continue;
            }

            if ($isReassign) {
                // 👤 Personel Değişikliği — system event, never editable.
                $body = $displayNote !== ''
                    ? '<div style="margin-top:8px;color:#111827;font-size:0.95em;line-height:1.55;white-space:pre-wrap;">' . nl2br(e($displayNote)) . '</div>'
                    : '';
                $dotColor = '#3b82f6';
                $html .= <<<HTML
                    <div style="position:relative;margin-bottom:24px;">
                        <div style="position:absolute;left:-30px;top:6px;width:24px;height:24px;border-radius:50%;background:#fff;border:3px solid {$dotColor};display:flex;align-items:center;justify-content:center;font-size:12px;">👤</div>
                        <div style="background:#fff;border:1px solid #e5e7eb;border-left:4px solid {$dotColor};border-radius:8px;padding:14px 16px;width:100%;">
                            <div style="font-weight:700;color:#111827;font-size:1em;">Personel Değişikliği</div>
                            <div style="font-size:0.875em;color:#6b7280;margin-top:6px;">{$author} tarafından • {$when}</div>
                            {$body}
                        </div>
                    </div>
                HTML;
                continue;
            }

            if ($isComment) {
                // 💬 Not Eklendi — comment text gets a prominent block.
                $commentBody = $rawNote !== ''
                    ? '<div style="margin-top:8px;color:#111827;font-size:0.95em;line-height:1.55;white-space:pre-wrap;">' . nl2br(e($rawNote)) . '</div>'
                    : '<div style="margin-top:8px;color:#9ca3af;font-style:italic;">(boş yorum)</div>';

                // Edit indicator. Eloquent stamps both timestamps to the same
                // microsecond on insert, so a 1-second floor avoids false
                // positives on freshly-created rows. Only shown when the row
                // was actually mutated (i.e. updateComment ran).
                $wasEdited = $entry->updated_at
                    && $entry->created_at
                    && $entry->updated_at->gt($entry->created_at->copy()->addSecond());
                $editedLine = $wasEdited
                    ? '<div style="font-size:0.8em;color:#6b7280;font-style:italic;margin-top:4px;">Son düzenleme: ' . e($entry->updated_at->translatedFormat('d F Y H:i')) . '</div>'
                    : '';

                $editable    = $viewer && $service->canEditComment($entry, $viewer);
                $minutesLeft = null;
                if ($editable && $entry->created_at) {
                    $minutesLeft = max(0, 10 - (int) $entry->created_at->diffInMinutes(now()));
                }

                // Header-row action chips (top-right corner of the card):
                //  [✏️ Düzenle • 8 dk kaldı]   [🗑️]
                // Both visible only inside the 10-min window for the author.
                // Timer color: gray when >5 min, orange when ≤5 min.
                $headerActions = '';
                if ($editable && $minutesLeft !== null && $minutesLeft > 0) {
                    $timerColor = $minutesLeft <= 5 ? '#f97316' : '#6b7280';
                    $editId     = (int) $entry->id;

                    $editChip = '<button type="button"'
                        . ' wire:click="mountAction(\'editComment\', { history_id: ' . $editId . ' })"'
                        . ' style="display:inline-flex;align-items:center;gap:4px;font-size:0.75em;color:' . $timerColor . ';background:#fff;border:1px solid #e5e7eb;border-radius:9999px;padding:2px 10px;cursor:pointer;line-height:1.4;"'
                        . ' title="Yorumu düzenle">'
                        . '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'
                        . 'Düzenle • ' . $minutesLeft . ' dk kaldı'
                        . '</button>';

                    $deleteChip = '<button type="button"'
                        . ' wire:click="mountAction(\'deleteComment\', { history_id: ' . $editId . ' })"'
                        . ' style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;color:#ef4444;background:#fff;border:1px solid #fecaca;border-radius:9999px;cursor:pointer;"'
                        . ' title="Notu sil">'
                        . '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>'
                        . '</button>';

                    $headerActions = '<div style="display:inline-flex;gap:6px;align-items:center;">' . $editChip . $deleteChip . '</div>';
                }

                $dotColor = '#9ca3af';
                $html .= <<<HTML
                    <div style="position:relative;margin-bottom:24px;">
                        <div style="position:absolute;left:-30px;top:6px;width:24px;height:24px;border-radius:50%;background:#fff;border:3px solid {$dotColor};display:flex;align-items:center;justify-content:center;font-size:12px;">💬</div>
                        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;width:100%;">
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                                <div style="font-weight:700;color:#111827;font-size:1em;">Not Eklendi</div>
                                {$headerActions}
                            </div>
                            <div style="font-size:0.875em;color:#6b7280;margin-top:6px;">{$author} tarafından • {$when}</div>
                            {$editedLine}
                            {$commentBody}
                        </div>
                    </div>
                HTML;
                continue;
            }

            // Status transition.
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
                <div style="position:relative;margin-bottom:24px;">
                    <div style="position:absolute;left:-30px;top:6px;width:24px;height:24px;border-radius:50%;background:{$toColor};border:3px solid #fff;box-shadow:0 0 0 2px {$toColor}33;"></div>
                    <div style="background:#fff;border:1px solid #e5e7eb;border-left:4px solid {$toColor};border-radius:8px;padding:14px 16px;width:100%;">
                        <div style="font-weight:600;color:#111827;font-size:1em;">
                            <span style="background:#f3f4f6;color:#6b7280;padding:3px 10px;border-radius:4px;font-size:0.85em;">{$fromLabel}</span>
                            <span style="margin:0 8px;color:#6b7280;">→</span>
                            <span style="background:{$toColor}22;color:{$toColor};padding:3px 10px;border-radius:4px;font-size:0.85em;font-weight:700;">{$toLabel}</span>
                        </div>
                        <div style="font-size:0.875em;color:#6b7280;margin-top:6px;">{$author} tarafından • {$when}</div>
                        {$noteBlock}
                    </div>
                </div>
            HTML;
        }

        $html .= '</div>';
        return new HtmlString($html);
    }
}
