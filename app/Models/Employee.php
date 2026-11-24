<?php

namespace App\Models;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskStatusEnum;
use App\Support\CompanyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Employee extends Model
{
    use HasFactory, Notifiable, SoftDeletes, LogsActivity;

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