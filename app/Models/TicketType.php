<?php

namespace App\Models;

use App\Enums\LocationRequirementEnum;
use App\Models\Concerns\RecordsAuthors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A kind of request (Arıza, IT Destek, Elektrik, ...).
 *
 * @property array<int, array{key: string, label: string, type: string, required?: bool, options?: array<int, string>, cc_required_options?: array<int, string>, help?: string|null}>|null $form_fields
 */
class TicketType extends Model
{
    use HasFactory, LogsActivity, RecordsAuthors, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'icon',
        'color',
        'location_requirement',
        'form_fields',
        'cc_required',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'location_requirement' => LocationRequirementEnum::class,
            'form_fields' => 'array',
            'cc_required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function activeTeams(): HasMany
    {
        return $this->teams()->where('is_active', true);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Whether a request needs at least one person kept informed (CC): always
     * for this type, or when a select field holds one of the values marked
     * in its cc_required_options.
     *
     * @param  array<string, mixed>  $customFields
     */
    public function requiresWatchers(array $customFields): bool
    {
        if ($this->cc_required) {
            return true;
        }

        return collect($this->form_fields ?? [])->contains(fn (array $field): bool => filled($field['cc_required_options'] ?? null)
            && in_array($customFields[$field['key']] ?? null, $field['cc_required_options'], true));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->useLogName('ticket_types');
    }
}
