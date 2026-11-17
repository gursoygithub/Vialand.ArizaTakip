<?php

namespace App\Filament\Resources\TeamTicketResource\Pages;

use App\Enums\ActiveStatusEnum;
use App\Enums\HoldReasonEnum;
use App\Enums\RejectionReasonEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\TaskStatusEnum;
use App\Exceptions\TicketWorkflowException;
use App\Filament\Resources\TeamResource;
use App\Filament\Resources\TeamTicketResource;
use App\Models\Employee;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketSlaCycle;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;

/**
 * Detail page of a team ticket. Every button calls TicketWorkflowService,
 * which re-checks the rule server-side; visibility here is only UX.
 */
class ViewTeamTicket extends ViewRecord
{
    protected static string $resource = TeamTicketResource::class;

    public function getTitle(): string
    {
        return $this->record->ticket_no;
    }

    private function workflow(): TicketWorkflowService
    {
        return app(TicketWorkflowService::class);
    }

    private function ticket(): Ticket
    {
        return $this->record;
    }

    private function cycle(): ?TicketSlaCycle
    {
        return $this->ticket()->currentCycle()->with('workCalendar', 'breachEmployee')->first();
    }

    private function is(TaskStatusEnum ...$statuses): bool
    {
        return in_array($this->ticket()->status, $statuses, true);
    }

    private function isSupervisor(): bool
    {
        return $this->workflow()->isSupervisor($this->ticket(), auth()->user());
    }

    private function isAssignee(): bool
    {
        return $this->workflow()->isAssignee($this->ticket(), auth()->user());
    }

    private function isRequester(): bool
    {
        return $this->workflow()->isRequester($this->ticket(), auth()->user());
    }

    /**
     * Run a workflow step, report the outcome and reload the record.
     */
    private function run(callable $step, string $successTitle): void
    {
        try {
            $this->record = $step($this->workflow(), auth()->user());
            Notification::make()->title($successTitle)->success()->send();
        } catch (TicketWorkflowException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }

        $this->record = $this->record->fresh();
    }

    private function reasonField(string $name = 'reason', bool $required = true): Forms\Components\Textarea
    {
        return Forms\Components\Textarea::make($name)
            ->label(__('ui.note'))
            ->rows(3)
            ->required($required);
    }

    protected function getHeaderActions(): array
    {
        $ticket = $this->ticket();

        return [
            Action::make('selfAssign')
                ->label(__('ui.action_self_assign'))
                ->icon('heroicon-o-hand-raised')
                ->color('primary')
                ->visible(fn (): bool => $this->is(TaskStatusEnum::OPEN)
                    && $ticket->team?->allow_self_assign
                    && auth()->user()->employee
                    && $this->workflow()->canWorkInTeam($ticket->team, auth()->user()->employee))
                ->requiresConfirmation()
                ->action(fn () => $this->run(fn ($wf, $user) => $wf->selfAssign($ticket, $user), __('ui.action_done'))),

            Action::make('assign')
                ->label(fn (): string => $ticket->employee_id ? __('ui.action_reassign') : __('ui.action_assign'))
                ->icon('heroicon-o-user-plus')
                ->color('warning')
                ->visible(fn (): bool => $this->isSupervisor()
                    && $this->is(TaskStatusEnum::OPEN, TaskStatusEnum::ASSIGNED, TaskStatusEnum::IN_PROGRESS))
                ->form([
                    Forms\Components\Select::make('employee_id')
                        ->label(__('ui.assigned_employee'))
                        ->options(fn (): array => $this->assignableEmployees())
                        ->required()
                        ->searchable(),
                    $this->reasonField('note', required: false),
                ])
                ->action(fn (array $data) => $this->run(
                    fn ($wf, $user) => $wf->assign($ticket, Employee::findOrFail($data['employee_id']), $user, $data['note'] ?? null),
                    __('ui.action_done'),
                )),

            Action::make('startWork')
                ->label(__('ui.action_start_work'))
                ->icon('heroicon-o-play')
                ->color('primary')
                ->visible(fn (): bool => $this->isAssignee() && $this->is(TaskStatusEnum::ASSIGNED))
                ->requiresConfirmation()
                ->action(fn () => $this->run(fn ($wf, $user) => $wf->startWork($ticket, $user), __('ui.action_done'))),

            Action::make('hold')
                ->label(__('ui.action_hold'))
                ->icon('heroicon-o-pause')
                ->color('gray')
                ->visible(fn (): bool => $this->isAssignee() && $this->is(TaskStatusEnum::IN_PROGRESS))
                ->form([
                    Forms\Components\Select::make('reason')
                        ->label(__('ui.hold_reason'))
                        ->options(HoldReasonEnum::class)
                        ->required(),
                    $this->reasonField('note', required: false),
                ])
                ->action(fn (array $data) => $this->run(
                    fn ($wf, $user) => $wf->hold($ticket, $user, HoldReasonEnum::from($data['reason']), $data['note'] ?? null),
                    __('ui.action_done'),
                )),

            Action::make('resume')
                ->label(__('ui.action_resume'))
                ->icon('heroicon-o-play-pause')
                ->color('primary')
                ->visible(fn (): bool => ($this->isAssignee() || $this->isSupervisor()) && $this->is(TaskStatusEnum::ON_HOLD))
                ->requiresConfirmation()
                ->action(fn () => $this->run(fn ($wf, $user) => $wf->resume($ticket, $user), __('ui.action_done'))),

            Action::make('resolve')
                ->label(__('ui.action_resolve'))
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (): bool => $this->isAssignee() && $this->is(TaskStatusEnum::IN_PROGRESS))
                ->form([$this->reasonField('note')->label(__('ui.resolution_notes'))])
                ->action(fn (array $data) => $this->run(fn ($wf, $user) => $wf->resolve($ticket, $user, $data['note']), __('ui.action_done'))),

            Action::make('confirm')
                ->label(__('ui.action_confirm'))
                ->icon('heroicon-o-hand-thumb-up')
                ->color('success')
                ->visible(fn (): bool => $this->isRequester() && $this->is(TaskStatusEnum::RESOLVED))
                ->requiresConfirmation()
                ->modalDescription(__('ui.action_confirm_description'))
                ->action(fn () => $this->run(fn ($wf, $user) => $wf->confirm($ticket, $user), __('ui.action_done'))),

            Action::make('rejectResolution')
                ->label(__('ui.action_reject_resolution'))
                ->icon('heroicon-o-hand-thumb-down')
                ->color('danger')
                ->visible(fn (): bool => $this->isRequester() && $this->is(TaskStatusEnum::RESOLVED))
                ->form([$this->reasonField()])
                ->action(fn (array $data) => $this->run(fn ($wf, $user) => $wf->rejectResolution($ticket, $user, $data['reason']), __('ui.action_done'))),

            Action::make('reopen')
                ->label(__('ui.action_reopen'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (): bool => $this->isRequester() && $this->is(TaskStatusEnum::CLOSED)
                    && $ticket->reopen_until?->isFuture())
                ->form([$this->reasonField()])
                ->action(fn (array $data) => $this->run(fn ($wf, $user) => $wf->reopen($ticket, $user, $data['reason']), __('ui.action_done'))),

            Action::make('addComment')
                ->label(__('ui.add_note'))
                ->icon('heroicon-o-chat-bubble-left')
                ->color('gray')
                ->visible(fn (): bool => $this->isRequester() || $this->isAssignee() || $this->isSupervisor())
                ->form([$this->reasonField('note')])
                ->action(function (array $data) use ($ticket): void {
                    app(TicketService::class)->addComment($ticket, auth()->user(), $data['note']);
                    Notification::make()->title(__('ui.action_done'))->success()->send();
                }),

            ActionGroup::make([
                Action::make('changePriority')
                    ->label(__('ui.action_change_priority'))
                    ->icon('heroicon-o-arrows-up-down')
                    ->visible(fn (): bool => $this->isSupervisor() && $this->is(
                        TaskStatusEnum::OPEN, TaskStatusEnum::ASSIGNED, TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::ON_HOLD,
                    ))
                    ->form([
                        Forms\Components\ToggleButtons::make('priority')
                            ->label(__('ui.priority'))
                            ->options(TaskPriorityEnum::class)
                            ->default(fn () => $ticket->priority?->value)
                            ->inline()
                            ->required(),
                        $this->reasonField(),
                    ])
                    ->action(fn (array $data) => $this->run(
                        fn ($wf, $user) => $wf->changePriority($ticket, TaskPriorityEnum::from((int) $data['priority']), $user, $data['reason']),
                        __('ui.action_done'),
                    )),

                Action::make('transfer')
                    ->label(__('ui.action_transfer'))
                    ->icon('heroicon-o-arrows-right-left')
                    ->visible(fn (): bool => $this->isSupervisor()
                        && $this->is(TaskStatusEnum::OPEN, TaskStatusEnum::ASSIGNED, TaskStatusEnum::IN_PROGRESS))
                    ->form([
                        Forms\Components\Select::make('team_id')
                            ->label(__('ui.team'))
                            ->options(fn (): array => Team::query()->active()->whereKeyNot($ticket->team_id)
                                ->with('ticketType')->orderBy('name')->get()
                                ->mapWithKeys(fn (Team $team): array => [$team->id => "{$team->name} ({$team->ticketType->name})"])
                                ->all())
                            ->required()
                            ->searchable(),
                        $this->reasonField(),
                    ])
                    ->action(fn (array $data) => $this->run(
                        fn ($wf, $user) => $wf->transfer($ticket, Team::findOrFail($data['team_id']), $user, $data['reason']),
                        __('ui.action_done'),
                    )),

                Action::make('reject')
                    ->label(__('ui.action_reject'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (): bool => $this->isSupervisor() && $this->is(TaskStatusEnum::OPEN, TaskStatusEnum::ASSIGNED))
                    ->form([
                        Forms\Components\Select::make('reason')
                            ->label(__('ui.rejection_reason'))
                            ->options(RejectionReasonEnum::class)
                            ->required(),
                        $this->reasonField('note', required: false),
                    ])
                    ->action(fn (array $data) => $this->run(
                        fn ($wf, $user) => $wf->reject($ticket, $user, RejectionReasonEnum::from($data['reason']), $data['note'] ?? null),
                        __('ui.action_done'),
                    )),

                Action::make('cancel')
                    ->label(__('ui.action_cancel'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (): bool => $this->canCancel())
                    ->form([$this->reasonField()])
                    ->action(fn (array $data) => $this->run(fn ($wf, $user) => $wf->cancel($ticket, $user, $data['reason']), __('ui.action_done'))),
            ])
                ->label(__('ui.more_actions'))
                ->icon('heroicon-o-ellipsis-vertical')
                ->button()
                ->color('gray'),
        ];
    }

    private function canCancel(): bool
    {
        if (! $this->is(TaskStatusEnum::OPEN, TaskStatusEnum::ASSIGNED, TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::ON_HOLD)) {
            return false;
        }

        if ($this->isSupervisor()) {
            return true;
        }

        return $this->isRequester()
            && $this->is(TaskStatusEnum::OPEN, TaskStatusEnum::ASSIGNED)
            && $this->cycle()?->responded_at === null;
    }

    /**
     * Supervisor + members of the ticket's team, active, labelled with company.
     *
     * @return array<int, string>
     */
    private function assignableEmployees(): array
    {
        $team = $this->ticket()->team()->with(['members.company', 'supervisor.company'])->first();

        return $team->members
            ->push($team->supervisor)
            ->filter(fn (?Employee $employee): bool => $employee !== null
                && $employee->status === ActiveStatusEnum::ACTIVE
                && (int) $employee->id !== (int) $this->ticket()->employee_id)
            ->unique('id')
            ->sortBy('name')
            ->mapWithKeys(fn (Employee $employee): array => [$employee->id => TeamResource::employeeLabel($employee)])
            ->all();
    }

    // ── Infolist ────────────────────────────────────────────────────────

    public function infolist(Infolist $infolist): Infolist
    {
        $ticket = $this->ticket()->loadMissing([
            'ticketType', 'team.supervisor.company', 'employee.company', 'createdBy.employee.company', 'area', 'subArea',
        ]);
        $cycle = $this->cycle();

        return $infolist
            ->record($ticket)
            ->columns(3)
            ->schema([
                Infolists\Components\Section::make(__('ui.request'))
                    ->icon('heroicon-o-inbox-stack')
                    ->columnSpan(2)
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('ticketType.name')
                            ->label(__('ui.ticket_type'))
                            ->badge()
                            ->color($ticket->ticketType?->color ?: 'gray')
                            ->icon($ticket->ticketType?->icon),
                        Infolists\Components\TextEntry::make('status')->label(__('ui.status'))->badge(),
                        Infolists\Components\TextEntry::make('priority')->label(__('ui.priority'))->badge(),
                        Infolists\Components\TextEntry::make('location')
                            ->label(__('ui.location'))
                            ->state(collect([$ticket->area?->name, $ticket->subArea?->name])->filter()->implode(' / ') ?: null)
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('hold_reason')
                            ->label(__('ui.hold_reason'))
                            ->visible($ticket->status === TaskStatusEnum::ON_HOLD)
                            ->badge()
                            ->color('gray'),
                        Infolists\Components\TextEntry::make('created_at')->label(__('ui.created_at'))->dateTime(),
                        Infolists\Components\TextEntry::make('description')
                            ->label(__('ui.description'))
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('custom_fields_view')
                            ->label(__('ui.form_fields'))
                            ->state($this->customFieldsHtml($ticket))
                            ->visible(filled($ticket->custom_fields))
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('resolution_notes')
                            ->label(__('ui.resolution_notes'))
                            ->visible(filled($ticket->resolution_notes))
                            ->columnSpanFull(),
                    ]),

                Infolists\Components\Section::make(__('ui.people'))
                    ->icon('heroicon-o-users')
                    ->columnSpan(1)
                    ->schema([
                        Infolists\Components\TextEntry::make('requester')
                            ->label(__('ui.requester'))
                            ->state($ticket->createdBy?->employee
                                ? TeamResource::employeeLabel($ticket->createdBy->employee)
                                : $ticket->createdBy?->name),
                        Infolists\Components\TextEntry::make('team_name')
                            ->label(__('ui.team'))
                            ->state($ticket->team?->name),
                        Infolists\Components\TextEntry::make('supervisor')
                            ->label(__('ui.supervisor'))
                            ->state($ticket->team?->supervisor ? TeamResource::employeeLabel($ticket->team->supervisor) : null),
                        Infolists\Components\TextEntry::make('assignee')
                            ->label(__('ui.assigned_employee'))
                            ->state($ticket->employee ? TeamResource::employeeLabel($ticket->employee) : null)
                            ->placeholder(__('ui.request_in_pool')),
                    ]),

                Infolists\Components\Section::make(__('ui.sla'))
                    ->icon('heroicon-o-clock')
                    ->columnSpanFull()
                    ->columns(4)
                    ->visible($cycle !== null)
                    ->schema($cycle ? $this->slaEntries($cycle) : []),

                Infolists\Components\Section::make(__('ui.images'))
                    ->icon('heroicon-o-paper-clip')
                    ->columnSpanFull()
                    ->collapsible()
                    ->visible($ticket->getMedia('task_attachments')->isNotEmpty())
                    ->schema([
                        Infolists\Components\SpatieMediaLibraryImageEntry::make('task_attachments')
                            ->hiddenLabel()
                            ->collection('task_attachments')
                            ->disk('s3'),
                    ]),

                Infolists\Components\Section::make(__('ui.history'))
                    ->icon('heroicon-o-clock')
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Infolists\Components\ViewEntry::make('timeline')
                            ->hiddenLabel()
                            ->view('filament.partials.team-ticket-timeline', [
                                'histories' => $ticket->statusHistories()->with('changedBy')->get()->reverse()->values(),
                            ]),
                    ]),
            ]);
    }

    /**
     * @return array<int, Infolists\Components\Component>
     */
    private function slaEntries(TicketSlaCycle $cycle): array
    {
        $workflow = $this->workflow();
        $running = $cycle->outcome === null;
        $ratio = $running ? $workflow->elapsedRatio($cycle) : null;

        return [
            Infolists\Components\TextEntry::make('sla_response')
                ->label(__('ui.sla_response'))
                ->state($cycle->responded_at
                    ? __('ui.sla_responded_at', ['time' => $cycle->responded_at->translatedFormat('d M H:i')])
                    : __('ui.sla_due_at', ['time' => $cycle->response_due_at->translatedFormat('d M H:i')]))
                ->badge()
                ->color($cycle->response_breached ? 'danger' : ($cycle->responded_at ? 'success' : 'gray')),

            Infolists\Components\TextEntry::make('sla_resolution')
                ->label(__('ui.sla_resolution'))
                ->state(__('ui.sla_due_at', ['time' => $cycle->resolution_due_at->translatedFormat('d M H:i')]))
                ->helperText($running && ! $cycle->isPaused() && ! $cycle->resolution_breached
                    ? __('ui.sla_due', ['time' => $cycle->resolution_due_at->diffForHumans(['parts' => 2, 'short' => true])])
                    : null)
                ->badge()
                ->color($cycle->resolution_breached ? 'danger' : ($ratio !== null && $ratio >= TicketWorkflowService::WARNING_RATIO ? 'warning' : 'success')),

            Infolists\Components\TextEntry::make('sla_state')
                ->label(__('ui.status'))
                ->state(match (true) {
                    $cycle->outcome !== null => $cycle->outcome->getLabel(),
                    $cycle->isPaused() => '⏸ '.__('ui.sla_paused'),
                    $cycle->resolution_breached => __('ui.sla_breached'),
                    default => __('ui.sla_running', ['percent' => (int) round(min(1, $ratio) * 100)]),
                })
                ->helperText($cycle->resolution_breached && $cycle->breach_attribution
                    ? __('ui.sla_breach_owner', [
                        'owner' => $cycle->breach_attribution->getLabel()
                            .($cycle->breachEmployee ? ': '.$cycle->breachEmployee->name : ''),
                    ])
                    : null)
                ->badge(),

            Infolists\Components\TextEntry::make('sla_meta')
                ->label(__('ui.sla_targets'))
                ->state(__('ui.sla_meta', [
                    'response' => TeamTicketResource::humanMinutes($cycle->response_minutes),
                    'resolution' => TeamTicketResource::humanMinutes($cycle->resolution_minutes),
                    'calendar' => $cycle->workCalendar?->name ?? __('ui.around_the_clock'),
                ]))
                ->helperText($cycle->paused_minutes > 0
                    ? __('ui.sla_paused_total', ['time' => TeamTicketResource::humanMinutes($cycle->paused_minutes)])
                    : ($cycle->cycle_no > 1 ? __('ui.sla_cycle_no', ['no' => $cycle->cycle_no]) : null)),
        ];
    }

    private function customFieldsHtml(Ticket $ticket): HtmlString
    {
        $labels = collect($ticket->ticketType?->form_fields ?? [])->pluck('label', 'key');

        $rows = collect($ticket->custom_fields ?? [])
            ->reject(fn ($value): bool => $value === null || $value === '')
            ->map(function ($value, string $key) use ($labels): string {
                $display = is_bool($value) ? ($value ? __('ui.yes') : __('ui.no')) : (string) $value;

                return '<div><span class="font-medium">'.e($labels[$key] ?? $key).':</span> '.e($display).'</div>';
            });

        return new HtmlString($rows->implode(''));
    }
}
