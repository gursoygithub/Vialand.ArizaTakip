<?php

namespace App\Services;

use App\Enums\UserStatusEnum;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use LdapRecord\Models\ActiveDirectory\User as LdapUser;

class UserService
{
    /**
     * Sync user from LDAP to local database (create or update).
     */
    public function syncFromLdap(LdapUser $ldapUser, string $password): User
    {
        $guid = $ldapUser->getConvertedGuid();

        $user = User::query()
            ->where('ldap_guid', $guid)
            ->orWhere('username', $ldapUser->getFirstAttribute('samaccountname'))
            ->first();

        $attributes = $this->mapLdapAttributes($ldapUser, $password);

        $isNewUser = false;

        if ($user) {
            $user->update($attributes);
        } else {
            $user = User::create($attributes);
            $isNewUser = true;
        }

        $this->syncLdapGroups($user, $ldapUser);

        // İlk defa giriş yapan kullanıcıya manager rolü ata
        if ($isNewUser) {
            $this->assignDefaultRole($user);
        }

        return $user;
    }

    /**
     * Map LDAP attributes to local user attributes.
     *
     * @return array<string, mixed>
     */
    protected function mapLdapAttributes(LdapUser $ldapUser, string $password): array
    {
        $firstName = $ldapUser->getFirstAttribute('givenname') ?? '';
        $lastName = $ldapUser->getFirstAttribute('sn') ?? '';
        $displayName = $ldapUser->getFirstAttribute('displayname')
            ?? $ldapUser->getFirstAttribute('cn')
            ?? trim("{$firstName} {$lastName}");

        return [
            'username' => $ldapUser->getFirstAttribute('samaccountname'),
            'email' => $ldapUser->getFirstAttribute('mail'),
            'name' => $displayName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'department' => $ldapUser->getFirstAttribute('department'),
            'office' => $ldapUser->getFirstAttribute('physicaldeliveryofficename'),
            'title' => $ldapUser->getFirstAttribute('title'),
            'phone' => $ldapUser->getFirstAttribute('telephonenumber'),
            'address' => $ldapUser->getFirstAttribute('streetaddress'),
            'ldap_guid' => $ldapUser->getConvertedGuid(),
            'ldap_dn' => $ldapUser->getDn(),
            'last_ldap_sync' => now(),
            'panel_user' => true,
            'status' => UserStatusEnum::ACTIVE,
            'password' => Hash::make($password),
        ];
    }

    /**
     * Sync LDAP groups to local user.
     */
    protected function syncLdapGroups(User $user, LdapUser $ldapUser): void
    {
        $memberOf = $ldapUser->getAttribute('memberof') ?? [];

        $user->update([
            'ldap_groups' => $memberOf,
        ]);
    }

    /**
     * Assign default role to new user.
     */
    protected function assignDefaultRole(User $user): void
    {
        // assign 'manager' role to new users but if role not exists, create it first
        if (!\Spatie\Permission\Models\Role::where('name', 'manager')->exists()) {
            \Spatie\Permission\Models\Role::create(['name' => 'manager']);
        }

        $user->assignRole('manager');
    }
}
