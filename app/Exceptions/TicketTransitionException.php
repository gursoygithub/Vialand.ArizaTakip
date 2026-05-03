<?php

namespace App\Exceptions;

use App\Enums\TaskStatusEnum;
use RuntimeException;

class TicketTransitionException extends RuntimeException
{
    public static function invalid(?TaskStatusEnum $from, TaskStatusEnum $to): self
    {
        $fromLabel = $from?->getLabel() ?? '—';
        $toLabel   = $to->getLabel();

        return new self("Geçersiz durum geçişi: {$fromLabel} → {$toLabel}");
    }
}
