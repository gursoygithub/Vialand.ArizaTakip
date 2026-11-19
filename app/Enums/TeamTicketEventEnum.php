<?php

namespace App\Enums;

/**
 * Events of the team-ticket notification matrix (agreement §8).
 */
enum TeamTicketEventEnum: string
{
    case Opened = 'opened';
    case Assigned = 'assigned';
    case Started = 'started';
    case Held = 'held';
    case HoldOverdue = 'hold_overdue';
    case Resolved = 'resolved';
    case ResolutionRejected = 'resolution_rejected';
    case Closed = 'closed';
    case Reopened = 'reopened';
    case Transferred = 'transferred';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case SlaWarning = 'sla_warning';
    case SlaBreached = 'sla_breached';
    case SlaEscalated = 'sla_escalated';
    case ExemptionRequested = 'exemption_requested';
    case ExemptionDecided = 'exemption_decided';

    public function title(): string
    {
        return __('ui.event_'.$this->value);
    }

    public function description(): string
    {
        return __('ui.event_'.$this->value.'_desc');
    }

    public function color(): string
    {
        return match ($this) {
            self::Resolved, self::Closed => '#198754',
            self::SlaWarning, self::Held, self::HoldOverdue => '#d97706',
            self::SlaBreached, self::SlaEscalated, self::ResolutionRejected, self::Cancelled, self::Rejected => '#dc3545',
            self::ExemptionRequested, self::ExemptionDecided => '#6f42c1',
            default => '#0d6efd',
        };
    }

    public function filamentColor(): string
    {
        return match ($this) {
            self::Resolved, self::Closed => 'success',
            self::SlaWarning, self::Held, self::HoldOverdue => 'warning',
            self::SlaBreached, self::SlaEscalated, self::ResolutionRejected, self::Cancelled, self::Rejected => 'danger',
            default => 'info',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Opened => 'heroicon-o-inbox-arrow-down',
            self::Assigned => 'heroicon-o-user-plus',
            self::Started => 'heroicon-o-play',
            self::Held => 'heroicon-o-pause',
            self::HoldOverdue => 'heroicon-o-clock',
            self::Resolved => 'heroicon-o-check-badge',
            self::ResolutionRejected => 'heroicon-o-hand-thumb-down',
            self::Closed => 'heroicon-o-lock-closed',
            self::Reopened => 'heroicon-o-arrow-uturn-left',
            self::Transferred => 'heroicon-o-arrows-right-left',
            self::Rejected => 'heroicon-o-no-symbol',
            self::Cancelled => 'heroicon-o-x-circle',
            self::SlaWarning => 'heroicon-o-exclamation-triangle',
            self::SlaBreached, self::SlaEscalated => 'heroicon-o-fire',
            self::ExemptionRequested, self::ExemptionDecided => 'heroicon-o-scale',
        };
    }
}
