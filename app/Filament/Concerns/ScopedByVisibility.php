<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Resource-level scope filter for non-Ticket resources.
 *
 * Default behavior: filter records to those created by the current user.
 * If user has 'view_all_<plural_lower>' permission, scope is bypassed.
 * super_admin always bypasses (handled by Spatie's role gate).
 *
 * Usage in Resource:
 *   use ScopedByVisibility;
 *   protected static string $viewAllPermission = 'view_all_companies';
 *
 * Note: Ticket has its own scopeVisibleBy() — do NOT use this trait there.
 */
trait ScopedByVisibility
{
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (!$user) {
            return $query->whereRaw('1 = 0'); // unauthenticated → empty
        }

        if ($user->hasRole('super_admin')) {
            return $query;
        }

        $bypassPermission = static::$viewAllPermission ?? null;
        if ($bypassPermission && $user->can($bypassPermission)) {
            return $query;
        }

        return $query->where('created_by', $user->id);
    }
}
