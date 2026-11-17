<?php

namespace App\Models;

use App\Enums\TaskPriorityEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Response and resolution targets of one team for one priority.
 */
class TeamSlaPolicy extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'team_id',
        'priority',
        'response_minutes',
        'resolution_minutes',
        'success_threshold',
    ];

    protected function casts(): array
    {
        return [
            'priority' => TaskPriorityEnum::class,
            'response_minutes' => 'integer',
            'resolution_minutes' => 'integer',
            'success_threshold' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->useLogName('team_sla_policies');
    }
}
