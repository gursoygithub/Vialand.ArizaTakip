<?php

namespace App\Models;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskStatusEnum;
use App\Models\Concerns\HasEmailStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Employee extends Model
{
    use HasEmailStatus, HasFactory, Notifiable, SoftDeletes, LogsActivity;

    protected $fillable = [
        'employee_id', 'tc_no', 'name', 'email', 'phone',
        'status', 'title', 'profession',
        'company_name', 'company_id',
        'created_by', 'updated_by', 'deleted_by',
        'performance_score',
        'current_threshold',
    ];

    protected $casts = [
        'status' => ActiveStatusEnum::class,
    ];

    // --- İlişkiler ---

    public function tickets()
    {
        return $this->hasMany(\App\Models\Ticket::class, 'employee_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'email', 'email')
            ->where('status', ActiveStatusEnum::ACTIVE)
            ->whereNotNull('username')
            ->whereNull('deleted_at');
    }

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function teams(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members')->withTimestamps();
    }

    /**
     * HR opens a new record each time someone moves to another company or
     * project, so one person can have several records (old ones inactive,
     * sometimes two active at once). The TC kimlik no stays the same, so it
     * is what makes them one person. Records stay as they are (history);
     * access, history and reports look at all of them together.
     *
     * @return array<int, int>
     */
    public function personIds(): array
    {
        if (blank($this->tc_no)) {
            return [(int) $this->getKey()];
        }

        return static::query()->where('tc_no', $this->tc_no)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Every record of the same person, newest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Employee>
     */
    public function personRecords(): \Illuminate\Database\Eloquent\Collection
    {
        return static::query()->with('company')->whereKey($this->personIds())->orderByDesc('status')->orderByDesc('id')->get();
    }

    /**
     * One row per person in pickers: the newest record of each TC among the
     * rows the query already allows (records without a TC stand alone).
     */
    public function scopeOnePerPerson(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query->whereIn("{$table}.id", static::query()
            ->toBase()
            ->selectRaw('MAX(id)')
            ->where('status', ActiveStatusEnum::ACTIVE->value)
            ->groupByRaw($this->getConnection()->getDriverName() === 'sqlite'
                ? "COALESCE(NULLIF(tc_no, ''), 'id-' || id)"
                : "COALESCE(NULLIF(tc_no, ''), CONCAT('id-', id))"));
    }

    public function supervisedTeams(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Team::class, 'supervisor_employee_id');
    }

    // --- Performans ve Kayıt İzleme ---

    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
    public function updatedBy() { return $this->belongsTo(User::class, 'updated_by'); }
    public function deletedBy() { return $this->belongsTo(User::class, 'deleted_by'); }

    protected static $logName = 'employees';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName(static::$logName);
    }


}