<?php

namespace App\Services;

use App\Enums\HoldReasonEnum;
use App\Enums\TeamTicketEventEnum;
use App\Models\Employee;
use App\Models\SlaExemption;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketSlaCycle;
use App\Models\User;
use App\Notifications\TeamTicketNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;

/**
 * The team-ticket notification matrix (agreement §8) in one place.
 *
 * Rules:
 *  - "Action" notices (the person must do something) cannot be switched off:
 *    they ignore personal preferences and ticket mutes.
 *  - "Info" notices respect the `ticket_participant` bell preference and
 *    per-ticket mutes, and never send mail.
 *  - The person who performed the action is not notified (except the
 *    requester's confirmation when a ticket is opened).
 *  - Each person gets at most one notice per event (strongest role wins).
 *  - Push (FCM) goes only with action notices that the matrix marks as push.
 */
class TeamTicketNotifier
{
    public const PERMISSION_ESCALATION = 'ticket.sla.escalation';

    private const INFO_PREFERENCE = 'ticket_participant';

    public function __construct(private FcmService $fcm) {}

    /**
     * @param  array<string, mixed>  $context  hold_reason, previous_team_id, note
     */
    public function dispatch(TeamTicketEventEnum $event, Ticket $ticket, ?User $actor, array $context = []): void
    {
        $ticket->loadMissing(['team.supervisor', 'ticketType', 'employee', 'createdBy']);

        $requester = $ticket->createdBy;
        $assignee = $this->userOf($ticket->employee);
        $supervisor = $this->userOf($ticket->team?->supervisor);

        $plan = match ($event) {
            TeamTicketEventEnum::Opened => [
                [$supervisor, 'action', true],
                [$requester, 'info', false],
            ],
            TeamTicketEventEnum::Assigned => [
                [$assignee, 'action', true],
                [$requester, 'info', false],
            ],
            TeamTicketEventEnum::Started => [
                [$requester, 'info', false],
            ],
            TeamTicketEventEnum::Held => [
                ($context['hold_reason'] ?? null) === HoldReasonEnum::AwaitingRequester
                    ? [$requester, 'action', true]
                    : [$requester, 'info', false],
                [$supervisor, 'info', false],
            ],
            TeamTicketEventEnum::Resolved => [
                [$requester, 'action', true],
            ],
            TeamTicketEventEnum::ResolutionRejected => [
                [$assignee, 'action', true],
                [$supervisor, 'info', false],
            ],
            TeamTicketEventEnum::Closed => [
                [$assignee, 'info', false],
            ],
            TeamTicketEventEnum::Reopened => [
                $assignee ? [$assignee, 'action', true] : [$supervisor, 'action', true],
                [$supervisor, 'info', false],
            ],
            TeamTicketEventEnum::Transferred => [
                [$supervisor, 'action', true],
                [$this->userOf(Team::find($context['previous_team_id'] ?? null)?->supervisor), 'info', false],
                [$requester, 'info', false],
            ],
            TeamTicketEventEnum::Rejected, TeamTicketEventEnum::Cancelled => [
                [$requester, 'important', false],
                [$assignee, 'info', false],
            ],
            TeamTicketEventEnum::PriorityChanged => [
                [$assignee, 'info', false],
                [$requester, 'important', false],
            ],
            TeamTicketEventEnum::PriorityObjected => [
                [$supervisor, 'action', true],
            ],
            TeamTicketEventEnum::PriorityObjectionDecided => [
                [$requester, 'important', false],
                [$assignee, 'info', false],
            ],
            TeamTicketEventEnum::SlaWarning => [
                [$assignee ?? $supervisor, 'important', false],
            ],
            TeamTicketEventEnum::SlaBreached => [
                [$assignee, 'important', false],
                [$supervisor, 'important', false],
            ],
            TeamTicketEventEnum::SlaEscalated => $this->escalationRecipients()
                ->map(fn (User $user): array => [$user, 'important', false])
                ->all(),
            TeamTicketEventEnum::ExemptionRequested => collect($context['recipients'] ?? [])
                ->map(fn (User $user): array => [$user, 'important', false])
                ->all(),
            TeamTicketEventEnum::ExemptionDecided => [
                [$context['requester'] ?? null, 'important', false],
            ],
        };

        $watcherLevel = $this->watcherLevel($event);
        $watchers = $watcherLevel ? $ticket->watchers()->with('user')->get() : collect();

        foreach ($watchers as $watcher) {
            if ($watcher->user) {
                $plan[] = [$watcher->user, $watcherLevel, false];
            }
        }

        // The requester's "talebiniz alındı" confirmation is the one notice
        // that goes to the person who performed the action.
        $excludeActor = $event !== TeamTicketEventEnum::Opened;

        $this->deliver($event, $ticket, $actor, $plan, $context['note'] ?? null, $excludeActor);

        if ($watcherLevel !== 'info') {
            $this->mailWatchersWithoutAccount($event, $ticket, $actor, $watchers, $context['note'] ?? null);
        }
    }

    /**
     * CC people hear about the opening and the outcome by mail; the steps in
     * between only ring their bell. SLA and exemption matters stay inside
     * the team.
     */
    private function watcherLevel(TeamTicketEventEnum $event): ?string
    {
        return match ($event) {
            TeamTicketEventEnum::Opened,
            TeamTicketEventEnum::Resolved,
            TeamTicketEventEnum::Rejected,
            TeamTicketEventEnum::Cancelled => 'important',
            TeamTicketEventEnum::Assigned,
            TeamTicketEventEnum::Started,
            TeamTicketEventEnum::Held,
            TeamTicketEventEnum::ResolutionRejected,
            TeamTicketEventEnum::Closed,
            TeamTicketEventEnum::Reopened,
            TeamTicketEventEnum::Transferred,
            TeamTicketEventEnum::PriorityChanged => 'info',
            default => null,
        };
    }

    /**
     * A CC person who never logged in has no account yet, so no bell — the
     * important steps still reach them by mail.
     *
     * @param  Collection<int, Employee>  $watchers
     */
    private function mailWatchersWithoutAccount(TeamTicketEventEnum $event, Ticket $ticket, ?User $actor, Collection $watchers, ?string $note): void
    {
        $actorName = $actor?->employee?->name ?? $actor?->name;

        $watchers
            ->filter(fn (Employee $watcher): bool => $watcher->user === null && filled($watcher->email))
            ->each(fn (Employee $watcher) => Notification::route('mail', $watcher->email)
                ->notify(new TeamTicketNotification($ticket, $event, ['mail'], $actorName, $note)));
    }

    /**
     * @param  Collection<int, User>  $approvers
     */
    public function exemptionRequested(SlaExemption $exemption, Collection $approvers): void
    {
        $this->dispatch(TeamTicketEventEnum::ExemptionRequested, $exemption->ticket, $exemption->requestedBy, [
            'recipients' => $approvers->all(),
            'note' => $exemption->reason->getLabel().': '.$exemption->note,
        ]);
    }

    public function exemptionDecided(SlaExemption $exemption): void
    {
        $this->dispatch(TeamTicketEventEnum::ExemptionDecided, $exemption->ticket, $exemption->decidedBy, [
            'requester' => $exemption->requestedBy,
            'note' => $exemption->status->getLabel().($exemption->decision_note ? ': '.$exemption->decision_note : ''),
        ]);
    }

    public function slaWarning(TicketSlaCycle $cycle): void
    {
        $this->dispatch(TeamTicketEventEnum::SlaWarning, $cycle->ticket, null);
    }

    public function slaBreached(TicketSlaCycle $cycle): void
    {
        $this->dispatch(TeamTicketEventEnum::SlaBreached, $cycle->ticket, null);
    }

    public function slaEscalated(TicketSlaCycle $cycle): void
    {
        $this->dispatch(TeamTicketEventEnum::SlaEscalated, $cycle->ticket, null);
    }

    /**
     * @param  array<int, array{0: ?User, 1: string, 2: bool}>  $plan  [recipient, level, push]
     */
    private function deliver(TeamTicketEventEnum $event, Ticket $ticket, ?User $actor, array $plan, ?string $note, bool $excludeActor = true): void
    {
        $rank = ['info' => 1, 'important' => 2, 'action' => 3];

        /** @var Collection<int, array{user: User, level: string, push: bool}> $recipients */
        $recipients = collect($plan)
            ->filter(fn (array $row): bool => $row[0] instanceof User)
            ->reject(fn (array $row): bool => $excludeActor && $actor !== null && (int) $row[0]->id === (int) $actor->id)
            ->map(fn (array $row): array => ['user' => $row[0], 'level' => $row[1], 'push' => $row[2]])
            ->sortByDesc(fn (array $row): int => $rank[$row['level']])
            ->unique(fn (array $row): int => $row['user']->id);

        $actorName = $actor?->employee?->name ?? $actor?->name;

        foreach ($recipients as ['user' => $user, 'level' => $level, 'push' => $push]) {
            if ($level === 'info' && ! $this->wantsInfo($ticket, $user)) {
                continue;
            }

            $channels = $level === 'info' ? ['database'] : ['database', 'mail'];

            if (blank($user->email) && in_array('mail', $channels, true)) {
                $channels = ['database'];

                Log::warning('Mail skipped: recipient has no e-mail address.', [
                    'notification' => TeamTicketNotification::class,
                    'event' => $event->value,
                    'ticket_no' => $ticket->ticket_no,
                    'user_id' => $user->id,
                    'name' => $user->name,
                ]);
            }

            $user->notify(new TeamTicketNotification($ticket, $event, $channels, $actorName, $note));

            if ($push) {
                $this->fcm->sendToUser(
                    $user,
                    $ticket->ticket_no.' • '.$event->title(),
                    $actorName ? __('ui.event_by', ['name' => $actorName]) : $event->description(),
                    TeamTicketNotification::urlFor($ticket),
                );
            }
        }
    }

    private function wantsInfo(Ticket $ticket, User $user): bool
    {
        return ! $ticket->isMutedBy($user) && $user->wantsNotification(self::INFO_PREFERENCE, 'database');
    }

    private function userOf(?Employee $employee): ?User
    {
        return $employee?->user;
    }

    /**
     * @return Collection<int, User>
     */
    private function escalationRecipients(): Collection
    {
        if (! Permission::query()->where('name', self::PERMISSION_ESCALATION)->exists()) {
            return collect();
        }

        return User::permission(self::PERMISSION_ESCALATION)->get();
    }
}
