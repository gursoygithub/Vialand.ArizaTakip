<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

class SubArea extends Model
{
    use Notifiable, SoftDeletes;

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

//    public static function query()
//    {
//        $hasPermission = auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all sub_areas');
//
//        if ($hasPermission) {
//            return parent::query();
//        } else {
//            return parent::query()->where('created_by', auth()->id());
//        }
//    }
}
