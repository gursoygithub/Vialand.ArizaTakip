<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\BooleanStatusEnum;
use App\Enums\ManagerStatusEnum;
use App\Enums\UserStatusEnum;
use App\Enums\UserTypeEnum;
use App\Mail\SendPasswordToUser;
use App\Notifications\UserCreated;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\HasDatabaseNotifications;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use App\Enums\ActiveStatusEnum;
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;
    use SoftDeletes;
    use HasRoles;
    use LogsActivity;
    use HasDatabaseNotifications;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'tc_no',
        'name',
        'email',
        'phone',
        'status',
        'is_manager',
        'title',
        'profession',
        'password',
        'project_id',
        'project_name',
        'created_by',
        'updated_by',
        'deleted_by',

        //ldap attributes
        'first_name',
        'last_name',
        'department',
        'office',
        'address',
        'username',
        'ldap_guid',
        'ldap_dn',
        'ldap_groups',
        'last_ldap_sync',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatusEnum::class,
            'is_manager' => BooleanStatusEnum::class,
            'ldap_groups' => 'array',
            'last_ldap_sync' => 'datetime',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status->is(UserStatusEnum::ACTIVE);
    }

    // created_by relation
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // updated_by relation
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // deleted_by relation
    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    // relation with areas
    public function areas()
    {
        return $this->hasMany(Area::class, 'created_by');
    }

    // relation with sub_areas
    public function subAreas()
    {
        return $this->hasMany(SubArea::class, 'created_by');
    }

    // relation with tasks
    public function tasks()
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    public static function query()
    {
        // dont return sa user
        return parent::query()->where('username', '!=', env('APP_ADMIN_USERNAME', 'sa'));
//        $hasPermission = auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_users');
//
//        if ($hasPermission) {
//            return parent::query();
//        } else {
//            return parent::query()->where('created_by', auth()->id());
//        }
    }

    protected static $logName = 'users';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName(static::$logName);
    }

    //relation with employee
    public function employee()
    {
        return $this->hasOne(Employee::class, 'email', 'email');
    }


    // send mail to user after creation
//    protected static function booted()
//    {
//        static::created(function ($user) {
//            $password = Str::substr($user->phone, -8);
//            //$password =  Str::mask($user->phone, '*', 0, strlen($user->tc_no) - 4);
//            //$password =  Str::mask($user->tc_no, '*', 0, strlen($user->tc_no) - 4);
//            // Send welcome email or notification
//            Mail::to($user->email)->send(new SendPasswordToUser($user, $password));
//        });
//    }
}
