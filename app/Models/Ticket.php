<?php

namespace App\Models;

use App\Enums\TaskStatusEnum;
use App\Notifications\TaskAssigned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Illuminate\Support\Facades\Cache;

class Ticket extends Model implements HasMedia
{
    use HasFactory, Notifiable, SoftDeletes, InteractsWithMedia, LogsActivity;

    protected $table = 'tickets';

    protected $fillable = [
        'ticket_no',
        'title',
        'description',
        'user_id',
        'area_id',
        'group_id',
        'sub_area_id',
        'unit_id',
        'unit_description',
        'type_id',
        'priority',
        'status',
        'employee_id',
        'assigned_person_type_id',
        'task_date',
        'completed_by',
        'due_date',
        'resolution_notes',
        'reopen_reason',
        'reopened_by',
        'reopened_at',
        'sla_outcome',
        'sla_deadline',
        'sla_breached',
        'assigned_at',
        'resolved_at',
        'closed_at',
        'closed_by',
        'created_by',
        'updated_by',
        'deleted_by',
        'updated_at',
    ];

    protected $casts = [
        'task_date'    => 'date',
        'due_date'     => 'datetime',
        'sla_deadline' => 'datetime',
        'assigned_at'  => 'datetime',
        'resolved_at'  => 'datetime',
        'closed_at'    => 'datetime',
        'reopened_at'  => 'datetime',
        'sla_breached' => 'boolean',
        'type_id'      => \App\Enums\TaskTypeEnum::class,
        'status'       => \App\Enums\TaskStatusEnum::class,
        'priority'     => \App\Enums\TaskPriorityEnum::class,
        'assigned_person_type_id' => \App\Enums\AssignedPersonTypeEnum::class,
    ];

    // --- Relationships ---

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function subcontractorEmployee()
    {
        return $this->belongsTo(SubcontractorEmployee::class, 'employee_id', 'id')
            ->with('subcontractor');
    }

    public function area()
    {
        return $this->belongsTo(Area::class, 'area_id')
            ->with(['company', 'subAreas']);
    }

    public function subArea()
    {
        return $this->belongsTo(SubArea::class, 'sub_area_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id')
            ->with(['company', 'area', 'unit', 'manager', 'members']);
    }

    public function statusHistories()
    {
        return $this->hasMany(TicketStatusHistory::class)->orderBy('created_at', 'asc');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopenedBy()
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    // --- Media ---

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('task_attachments')
            ->useDisk('s3')
            ->singleFile();
    }

    // --- SLA Accessors ---

    public function getTargetDateAttribute()
    {
        return Cache::remember("ticket_target_{$this->id}", 3600, function () {
            if ($this->sla_deadline) {
                return $this->sla_deadline;
            }

            $policy = SlaPolicy::where('area_id', $this->area_id)
                ->where('sub_area_id', $this->sub_area_id)
                ->where('unit_id', $this->unit_id)
                ->where('priority', $this->priority)
                ->first();

            if (!$policy) {
                $policy = SlaPolicy::where('area_id', $this->area_id)
                    ->where('priority', $this->priority)
                    ->whereNull('sub_area_id')
                    ->first();
            }

            if (!$policy || !$policy->deadline_minutes) {
                return null;
            }

            return $this->created_at->addMinutes($policy->deadline_minutes);
        });
    }

    public function getSlaStatusAttribute(): string
    {
        if (!empty($this->sla_outcome)) {
            return $this->sla_outcome;
        }

        $target = $this->target_date;
        if (!$target) {
            return 'NO_SLA';
        }

        $completedAt = $this->closed_at ?? $this->due_date;

        if (!$completedAt) {
            return now() > $target ? 'SLA_BREACHED' : 'IN_PROGRESS';
        }

        return $completedAt <= $target ? 'SUCCESS' : 'FAILED';
    }

    public function getSlaPercentRemainingAttribute(): ?float
    {
        $deadline = $this->sla_deadline;
        if (!$deadline || !$this->created_at) {
            return null;
        }

        $total = $this->created_at->diffInSeconds($deadline);
        if ($total <= 0) {
            return 0;
        }

        $remaining = now()->diffInSeconds($deadline, false);

        return max(0, min(100, ($remaining / $total) * 100));
    }

    // --- Query Scope (permission-aware) ---

    public static function query()
    {
        $user = auth()->user();

        if (!$user) {
            return parent::query()->whereRaw('1=0');
        }

        if ($user->hasRole('super_admin') || $user->can('ticket.view.all') || $user->can('view_all_tasks')) {
            return parent::query();
        }

        $employeeId = $user->employee?->id;

        if ($user->can('ticket.view.group')) {
            $areaIds = Group::where('employee_id', $employeeId)->pluck('area_id');

            return parent::query()->whereIn('area_id', $areaIds);
        }

        // ticket.view.own or default
        return parent::query()
            ->where(function ($query) use ($user, $employeeId) {
                $query->where('created_by', $user->id)
                    ->orWhere('employee_id', $employeeId);
            });
    }

    // --- Boot ---

    protected static function booted(): void
    {
        static::creating(function (Ticket $ticket) {
            $ticket->created_by = auth()->id();

            if (empty($ticket->ticket_no)) {
                $year  = now()->year;
                $count = static::withTrashed()->whereYear('created_at', $year)->count() + 1;
                $ticket->ticket_no = 'TKT-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
            }

            if (empty($ticket->status)) {
                $ticket->status = TaskStatusEnum::OPEN;
            }
        });

        static::saving(function (Ticket $ticket) {
            if ($ticket->due_date || $ticket->closed_at) {
                $closedAt = $ticket->closed_at ?? $ticket->due_date;

                $policy = SlaPolicy::where([
                    'area_id'     => $ticket->area_id,
                    'sub_area_id' => $ticket->sub_area_id,
                    'unit_id'     => $ticket->unit_id,
                    'priority'    => $ticket->priority,
                ])->first();

                if (!$policy) {
                    $policy = SlaPolicy::where([
                        'area_id'  => $ticket->area_id,
                        'unit_id'  => $ticket->unit_id,
                        'priority' => $ticket->priority,
                    ])->first();
                }

                if ($policy && $policy->deadline_minutes) {
                    $startTime  = $ticket->created_at ?? now();
                    $targetDate = $startTime->copy()->addMinutes($policy->deadline_minutes);
                    $ticket->sla_outcome = $closedAt <= $targetDate ? 'SUCCESS' : 'FAILED';
                } else {
                    $ticket->sla_outcome = null;
                }
            }
        });

        static::saved(function (Ticket $ticket) {
            Cache::forget('dashboard_stats_overview');
            Cache::forget("ticket_target_{$ticket->id}");

            if ($ticket->employee_id && $ticket->employee) {
                Cache::forget("emp_perf_{$ticket->employee_id}");
                $ticket->employee->refreshPerformanceMetrics();
            }
        });

        static::updating(function (Ticket $ticket) {
            $ticket->updated_by = auth()->id();

            if ($ticket->isDirty('employee_id') && $ticket->employee_id) {
                $employee = Employee::find($ticket->employee_id);
                if ($employee) {
                    $employee->notify(new TaskAssigned($ticket));
                }
            }
        });

        static::deleting(function (Ticket $ticket) {
            $ticket->deleted_by = auth()->id();
            $ticket->save();
        });
    }

    protected static $logName = 'tickets';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName(static::$logName);
    }
}
