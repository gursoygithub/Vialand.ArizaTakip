<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use LdapRecord\Container;
use LdapRecord\Models\ActiveDirectory\User as LdapUser;

class AuthService
{
    public function __construct(protected UserService $userService)
    {
    }

    /**
     * Authenticate user via LDAP and sync to local database.
     *
     * @param  array{username: string, password: string}  $credentials
     * @return array{success: bool, message: string, user?: User}
     */
    public function login(array $credentials): array
    {
        $username = $credentials['username'];
        $password = $credentials['password'];

        // if username:env('APP_ADMIN_USERNAME') and password:env('APP_ADMIN_PASSWORD'), login as admin user without LDAP
        if ($username === env('APP_ADMIN_USERNAME') && $password === env('APP_ADMIN_PASSWORD')) {

            $user = User::where('username', $username)->first();

            if (!$user) {
                // create admin user if not exists
                $user = User::create([
                    'username' => $username,
                    'name' => 'Administrator',
                    'email' => env('APP_ADMIN_EMAIL', 'sa@app.com'),
                    'password' => bcrypt($password),
                ]);
                // assign super admin role
                $user->assignRole('super_admin');
            }

            Auth::login($user);
            return [
                'success' => true,
                'message' => __('ui.login_success'),
                'user' => $user,
            ];
        }

        $ldapUser = $this->findLdapUser($username);

        if (!$ldapUser) {
            return [
                'success' => false,
                'message' => __('ui.user_not_found'),
            ];
        }

        if (!$this->validateLdapCredentials($ldapUser, $password)) {
            return [
                'success' => false,
                'message' => __('ui.invalid_credentials'),
            ];
        }

        // if user is not member of required LDAP group, deny access
        $requiredGroup = env('LDAP_REQUIRED_GROUPS');
        if ($requiredGroup && !$this->isUserInGroup($ldapUser, $requiredGroup)) {
            return [
                'success' => false,
                'message' => __('ui.access_denied'),
            ];
        }

        $user = $this->userService->syncFromLdap($ldapUser, $password);

        Auth::login($user);

        return [
            'success' => true,
            'message' => __('ui.login_success'),
            'user' => $user,
        ];
    }

    /**
     * Find user in LDAP by sAMAccountName.
     */
    protected function findLdapUser(string $username): ?LdapUser
    {
        return LdapUser::findBy('samaccountname', $username);
    }

    /**
     * Validate LDAP credentials by attempting to bind.
     */
    protected function validateLdapCredentials(LdapUser $ldapUser, string $password): bool
    {
        $connection = Container::getDefaultConnection();

        return $connection->auth()->attempt($ldapUser->getDn(), $password);
    }

    /**
     * Check if user is member of required LDAP group.
     */
    protected function isUserInGroup(LdapUser $ldapUser, string $groupDn): bool
    {
        // Kullanıcının memberOf attribute'unu al
        $memberOf = $ldapUser->getAttribute('memberof');

        if (!$memberOf) {
            return false;
        }

        // memberOf array veya string olabilir
        if (!is_array($memberOf)) {
            $memberOf = [$memberOf];
        }

        // Grup DN'lerini karşılaştır (case-insensitive)
        foreach ($memberOf as $group) {
            if (strcasecmp($group, $groupDn) === 0) {
                return true;
            }
        }

        return false;
    }
}
