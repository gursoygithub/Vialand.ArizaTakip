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
    use Notifiable, SoftDeletes, InteractsWithMedia, LogsActivity;

    protected $fillable = [
        'area_id',
        'priority',
        'resolution_time_hours',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'response_time' => 'integer',
        'resolution_time' => 'integer',
    ];

     public function area()
    {
        return $this->belongsTo(Area::class);
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
        static::creating(function ($task) {
            $task->created_by = auth()->id();

        });

        static::updating(function ($task) {
            $task->updated_by = auth()->id();
        });

        static::deleting(function ($task) {
            $task->deleted_by = auth()->id();
            $task->save();
        });
    }
}
