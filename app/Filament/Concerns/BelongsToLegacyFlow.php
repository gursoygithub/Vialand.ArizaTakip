<?php

namespace App\Filament\Concerns;

/**
 * For resources of the legacy ticket flow: hidden from navigation and
 * unreachable (403) unless `config('legacy.enabled')` is on.
 */
trait BelongsToLegacyFlow
{
    public static function legacyEnabled(): bool
    {
        return (bool) config('legacy.enabled');
    }

    public static function canAccess(): bool
    {
        return static::legacyEnabled() && parent::canAccess();
    }
}
