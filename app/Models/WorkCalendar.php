<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuthors;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Working hours a team's SLA clock follows.
 *
 * @property array<string, array{start: string, end: string}>|null $working_hours keyed by ISO weekday (1 = Monday)
 */
class WorkCalendar extends Model
{
    use HasFactory, LogsActivity, RecordsAuthors, SoftDeletes;

    protected $fillable = [
        'name',
        'is_24_7',
        'working_hours',
        'observes_holidays',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_24_7' => 'boolean',
            'working_hours' => 'array',
            'observes_holidays' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * Opening hours for an ISO weekday, or null when closed that day.
     *
     * @return array{start: string, end: string}|null
     */
    public function hoursFor(int $isoWeekday): ?array
    {
        if ($this->is_24_7) {
            return ['start' => '00:00', 'end' => '24:00'];
        }

        $hours = $this->working_hours[(string) $isoWeekday] ?? null;

        if (empty($hours['start']) || empty($hours['end'])) {
            return null;
        }

        return ['start' => $hours['start'], 'end' => $hours['end']];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->useLogName('work_calendars');
    }
}
