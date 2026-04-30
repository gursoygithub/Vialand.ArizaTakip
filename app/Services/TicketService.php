<?php

namespace App\Services;

use App\Enums\TaskStatusEnum;
use App\Models\Ticket;
use App\Models\TicketStatusHistory;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class TicketService
{
    private const ALLOWED_TRANSITIONS = [
        TaskStatusEnum::OPEN->value        => [TaskStatusEnum::ASSIGNED->value, TaskStatusEnum::CANCELLED->value],
        TaskStatusEnum::ASSIGNED->value    => [TaskStatusEnum::IN_PROGRESS->value, TaskStatusEnum::ON_HOLD->value, TaskStatusEnum::CANCELLED->value],
        TaskStatusEnum::IN_PROGRESS->value => [TaskStatusEnum::RESOLVED->value, TaskStatusEnum::ON_HOLD->value, TaskStatusEnum::CANCELLED->value],
        TaskStatusEnum::ON_HOLD->value     => [TaskStatusEnum::IN_PROGRESS->value, TaskStatusEnum::CANCELLED->value],
        TaskStatusEnum::RESOLVED->value    => [TaskStatusEnum::CLOSED->value, TaskStatusEnum::IN_PROGRESS->value],
        // Legacy statuses can transition to the new closed state
        TaskStatusEnum::PENDING->value     => [TaskStatusEnum::COMPLETED->value, TaskStatusEnum::OPEN->value, TaskStatusEnum::CANCELLED->value],
        TaskStatusEnum::COMPLETED->value   => [TaskStatusEnum::CLOSED->value],
    ];

    /**
     * Transition a ticket to a new status, logging the history.
     */
    public function transition(Ticket $ticket, TaskStatusEnum $newStatus, User $by, ?string $note = null): Ticket
    {
        $fromValue = $ticket->status?->value;
        $toValue   = $newStatus->value;

        $allowed = self::ALLOWED_TRANSITIONS[$fromValue] ?? [];
        if (!in_array($toValue, $allowed)) {
            throw ValidationException::withMessages([
                'status' => "Transition from {$ticket->status?->getLabel()} to {$newStatus->getLabel()} is not allowed.",
            ]);
        }

        $ticket->status = $newStatus;

        // Set lifecycle timestamps
        match ($newStatus) {
            TaskStatusEnum::ASSIGNED    => $ticket->assigned_at  = now(),
            TaskStatusEnum::RESOLVED    => $ticket->resolved_at  = now(),
            TaskStatusEnum::CLOSED,
            TaskStatusEnum::COMPLETED   => $this->handleClose($ticket, $by),
            default                     => null,
        };

        $ticket->save();

        TicketStatusHistory::create([
            'ticket_id'  => $ticket->id,
            'from_status' => $fromValue,
            'to_status'   => $toValue,
            'changed_by'  => $by->id,
            'note'        => $note,
        ]);

        return $ticket->fresh();
    }

    /**
     * Add a comment/note without changing status.
     */
    public function addNote(Ticket $ticket, User $by, string $note): TicketStatusHistory
    {
        return TicketStatusHistory::create([
            'ticket_id'   => $ticket->id,
            'from_status' => $ticket->status?->value,
            'to_status'   => null,
            'changed_by'  => $by->id,
            'note'        => $note,
        ]);
    }

    private function handleClose(Ticket $ticket, User $by): void
    {
        $ticket->closed_at    = now();
        $ticket->closed_by    = $by->id;
        $ticket->sla_breached = $ticket->sla_deadline && now()->isAfter($ticket->sla_deadline);
    }
}
