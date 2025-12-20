<?php

namespace App\Models;

use App\Enums\ActiveStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Area extends Model
{
    use Notifiable, SoftDeletes, LogsActivity;
    protected $fillable = [
        'name',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'status' => ActiveStatusEnum::class,
    ];

    // Relation with SubArea model
    public function subAreas()
    {
        return $this->hasMany(SubArea::class);
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

    public static function query()
    {
        $hasPermission = auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_areas');

        if ($hasPermission) {
            return parent::query();
        } else {
            return parent::query()->where('created_by', auth()->id());
        }
    }

    // Activity Log Options
    protected static $logName = 'areas';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName(static::$logName);
    }
}
