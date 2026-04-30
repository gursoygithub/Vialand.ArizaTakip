<?php

namespace App\Ldap;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use LdapRecord\Models\Model;

class User extends Model implements FilamentUser
{
    /**
     * The object classes of the LDAP model.
     */
    public static array $objectClasses = [];
    /**
     * @var false|mixed|string
     */
    public mixed $ldap_groups;
    /**
     * @var \Carbon\CarbonInterface|\Illuminate\Support\Carbon|mixed
     */
    public mixed $last_ldap_sync;

    protected array $casts = [
        'name' => 'array',
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

//    public function getFilamentName(): string
//    {
//
//
//        dd($this->getAttributes());
//        return $this->getAttribute('cn', 'No Name');
//    }

}
