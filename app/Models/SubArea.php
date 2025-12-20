<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SubArea extends Model
{
    use Notifiable, SoftDeletes, LogsActivity;

    protected $fillable = [
        'area_id',
        'name',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    // Relation with Area model
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

    public function tasks()
    {
        return $this->hasMany(Task::class, 'sub_area_id');
    }


    public static function query()
    {
        $hasPermission = auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_sub_areas');

        if ($hasPermission) {
            return parent::query();
        } else {
            return parent::query()->where('created_by', auth()->id());
        }
    }

    // Activity log configuration
    protected static $logName = 'sub_areas';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName(static::$logName);
    }
}
