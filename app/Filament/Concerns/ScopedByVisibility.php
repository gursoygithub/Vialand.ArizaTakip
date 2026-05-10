<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait ScopedByVisibility
{
    /**
     * Apply visibility scope to a query.
     * Use this when the resource needs to chain additional WHERE clauses
     * after the visibility scope.
     */
    public static function applyVisibilityScope(Builder $query): Builder
    {
        $user = auth()->user();

        if (!$user) {
            return $query->whereRaw('1 = 0');
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

    public static function getEloquentQuery(): Builder
    {
        return static::applyVisibilityScope(parent::getEloquentQuery());
    }
}
