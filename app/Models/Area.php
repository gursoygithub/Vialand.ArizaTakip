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
        'company_id',
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

    public function scopeAccessibleByUser($query, $user)
    {
        if ($user->hasRole('super_admin') || $user->can('view_all_areas')) {
            return $query;
        }

        $employee = $user->employee;

        if (!$employee) {
            return $query->whereRaw('1 = 0');
        }

        $areaIds = $employee->accessibleAreaIds();

        if ($areaIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('id', $areaIds);
    }

//    public static function query()
//    {
//        $hasPermission = auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_areas');
//
//        if ($hasPermission) {
//            return parent::query();
//        } else {
//            return parent::query()->where('created_by', auth()->id());
//        }
//    }

    // Activity Log Options
    protected static $logName = 'areas';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName(static::$logName);
    }

    // relation with company
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    // relation with group
    public function groups()
    {
        return $this->hasMany(Group::class);
    }

    public function slaPolicies()
    {
        return $this->hasMany(SlaPolicy::class, 'area_id');
    }
}
