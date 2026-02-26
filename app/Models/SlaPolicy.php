<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\InteractsWithMedia;

class SlaPolicy extends Model
{
    use Notifiable, SoftDeletes, LogsActivity;

    protected $fillable = [
        'area_id',
        'sub_area_id',
        'unit_id',
        'priority',
        'deadline_minutes',
        'success_threshold',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'priority' => \App\Enums\TaskPriorityEnum::class,
    ];

     public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function subArea()
    {
        return $this->belongsTo(SubArea::class);
    }

     public function unit()
    {
        return $this->belongsTo(Unit::class);
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

    protected static $logName = 'sla_policies';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName(static::$logName);
    }

    protected static function booted()
    {
        static::creating(function ($slaPolicy) {
            $slaPolicy->created_by = auth()->id();
        });

        static::updating(function ($slaPolicy) {
            $slaPolicy->updated_by = auth()->id();
        });

        static::deleting(function ($slaPolicy) {
            $slaPolicy->deleted_by = auth()->id();
            $slaPolicy->save();
        });
    }
}
