<?php

namespace App\Exceptions;

use App\Enums\TaskStatusEnum;
use DomainException;

/**
 * A team-workflow action that the rules do not allow. The message is
 * user-facing (Turkish, from lang/tr/ui.php).
 */
class TicketWorkflowException extends DomainException
{
    /**
     * Form field the message belongs to, when it is about one input.
     */
    public ?string $field = null;

    public static function notAllowed(): self
    {
        return new self(__('ui.workflow_not_allowed'));
    }

    public static function wrongStatus(?TaskStatusEnum $status): self
    {
        return new self(__('ui.workflow_wrong_status', ['status' => $status?->getLabel() ?? '—']));
    }

    public static function notTeamMember(): self
    {
        return new self(__('ui.workflow_not_team_member'));
    }

    public static function inactiveTeam(): self
    {
        return new self(__('ui.workflow_inactive_team'));
    }

    public static function missingField(string $label): self
    {
        return new self(__('ui.workflow_missing_field', ['field' => $label]));
    }

    public static function topicRequired(): self
    {
        return new self(__('ui.workflow_topic_required'));
    }

    public static function locationRequired(): self
    {
        return new self(__('ui.workflow_location_required'));
    }

    public static function reopenWindowClosed(): self
    {
        return new self(__('ui.workflow_reopen_window_closed'));
    }

    public static function noSlaPolicy(): self
    {
        return new self(__('ui.workflow_no_sla_policy'));
    }

    public static function reasonRequired(): self
    {
        return new self(__('ui.workflow_reason_required'));
    }

    public static function watchersRequired(): self
    {
        return tap(new self(__('ui.workflow_watchers_required')), fn (self $exception) => $exception->field = 'watchers');
    }

    public static function watcherWithoutEmail(string $names): self
    {
        return tap(new self(__('ui.workflow_watcher_without_email', ['names' => $names])), fn (self $exception) => $exception->field = 'watchers');
    }
}
