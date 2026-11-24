<?php

namespace App\Models\Concerns;

use App\Support\CompanyEmail;
use Illuminate\Database\Eloquent\Builder;

/**
 * E-mail quality filter for models with an `email` column (Employee, User).
 */
trait HasEmailStatus
{
    /**
     * Filter by e-mail quality, the same classes as CompanyEmail::status():
     * corporate / personal / invalid / missing, plus "duplicate" (the same
     * address on more than one record).
     */
    public function scopeEmailStatus(Builder $query, string $status): Builder
    {
        $missing = fn (Builder $q) => $q->whereNull('email')->orWhere('email', '');
        $invalid = fn (Builder $q) => $q->where('email', 'not like', '%_@_%._%')->orWhere('email', 'like', '% %');
        $corporate = fn (Builder $q) => CompanyEmail::domains() === []
            ? $q->whereRaw('1 = 0')
            : collect(CompanyEmail::domains())->each(fn (string $domain) => $q->orWhere('email', 'like', '%@'.$domain));

        return match ($status) {
            CompanyEmail::MISSING => $query->where($missing),
            CompanyEmail::INVALID => $query->whereNot($missing)->where($invalid),
            CompanyEmail::CORPORATE => $query->whereNot($missing)->whereNot($invalid)->where($corporate),
            CompanyEmail::PERSONAL => $query->whereNot($missing)->whereNot($invalid)->whereNot($corporate),
            'duplicate' => $query->whereNot($missing)->whereIn('email', static::query()
                ->select('email')->where('email', '!=', '')->groupBy('email')->havingRaw('COUNT(*) > 1')),
            default => $query,
        };
    }
}
