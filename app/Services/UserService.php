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

        // A supervisor picked on the Ekipler screen before their first login
        // gets the "Ekip Amiri" role now.
        app(SupervisorRoleService::class)->syncUser($user);

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

        $email = $ldapUser->getFirstAttribute('mail');

        // AD keeps the TC kimlik no in employeeID: it links the account to
        // the person even when the HR e-mail is missing or personal.
        $tcNo = self::trustedTc(
            self::tcFrom($ldapUser->getFirstAttribute('employeeid')),
            $email,
            $ldapUser->getFirstAttribute('samaccountname'),
        );

        $employee = ($tcNo ? \App\Models\Employee::where('tc_no', $tcNo)->orderByDesc('status')->orderByDesc('id')->first() : null)
            ?? ($email ? \App\Models\Employee::where('email', $email)->orderByDesc('status')->orderByDesc('id')->first() : null);

        return [
            'username' => $ldapUser->getFirstAttribute('samaccountname'),
            'email' => $email,
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
            'employee_id' => $employee?->employee_id,
            'tc_no' => $tcNo,
        ];
    }

    /**
     * AD's TC is not trusted when HR knows the account's e-mail as somebody
     * else: a wrong employeeID would hand over another person's requests and
     * rights. The account then links by e-mail, and the clash is logged
     * (without the TC itself).
     */
    public static function trustedTc(?string $tcNo, ?string $email, ?string $username = null): ?string
    {
        if ($tcNo === null || blank($email)) {
            return $tcNo;
        }

        $tcsOfEmail = \App\Models\Employee::query()
            ->where('email', $email)
            ->whereNotNull('tc_no')
            ->where('tc_no', '!=', '')
            ->pluck('tc_no');

        if ($tcsOfEmail->isEmpty() || $tcsOfEmail->contains($tcNo)) {
            return $tcNo;
        }

        \Illuminate\Support\Facades\Log::warning('AD employeeID ile e-posta farklı kişileri gösteriyor; hesap e-postayla eşleştirildi.', [
            'username' => $username,
        ]);

        return null;
    }

    /**
     * An 11-digit TC kimlik no, or null for anything else (empty, a sicil
     * number, spaces).
     */
    public static function tcFrom(mixed $value): ?string
    {
        $value = preg_replace('/\s+/', '', (string) $value);

        return preg_match('/^\d{11}$/', $value) ? $value : null;
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
        // assign 'default' role to new users but if role not exists, create it first
        if (!\Spatie\Permission\Models\Role::where('name', 'default')->exists()) {
            \Spatie\Permission\Models\Role::create(['name' => 'default']);
        }

        $user->assignRole('default');
    }
}
