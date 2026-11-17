<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuthors;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Holiday extends Model
{
    use HasFactory, RecordsAuthors;

    protected $fillable = [
        'date',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    /**
     * Store a plain Y-m-d so the unique rule (and the DB unique index) compare
     * like with like on every driver.
     */
    protected function date(): Attribute
    {
        return Attribute::make(
            set: fn ($value): ?string => $value === null ? null : Carbon::parse($value)->toDateString(),
        );
    }
}
