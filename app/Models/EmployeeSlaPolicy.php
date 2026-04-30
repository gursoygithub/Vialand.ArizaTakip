<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class EmployeeSlaPolicy extends Pivot
{
    use Notifiable, LogsActivity;

    protected $table = 'employee_sla_policies';

    public $incrementing = true;

    protected $fillable = [
        'employee_id',
        'sla_policy_id',
        //'status',
        'created_by',
        'updated_by',
    ];

//    protected $casts = [
//        'status' => \App\Enums\ActiveStatusEnum::class,
//    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function slaPolicy()
    {
        return $this->belongsTo(SlaPolicy::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected static $logName = 'employee_sla_policies';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName(static::$logName);
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            if (auth()->check()) {
                $model->created_by = auth()->id();
            }

            //$model->status = \App\Enums\ActiveStatusEnum::ACTIVE;
        });

        static::updating(function ($model) {
            if (auth()->check()) {
                $model->updated_by = auth()->id();
            }
        });
    }
}