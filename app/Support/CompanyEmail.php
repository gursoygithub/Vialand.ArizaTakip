<?php

namespace App\Support;

use App\Settings\GeneralSettings;

/**
 * Classifies an address against the company domains on Sistem Ayarları.
 * Mail is only ever sent to "corporate" addresses.
 */
class CompanyEmail
{
    public const MISSING = 'missing';

    public const INVALID = 'invalid';

    public const PERSONAL = 'personal';

    public const CORPORATE = 'corporate';

    public static function status(?string $email): string
    {
        $email = trim((string) $email);

        if ($email === '') {
            return self::MISSING;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return self::INVALID;
        }

        $domain = strtolower(substr(strrchr($email, '@'), 1));

        return in_array($domain, self::domains(), true) ? self::CORPORATE : self::PERSONAL;
    }

    public static function isCorporate(?string $email): bool
    {
        return self::status($email) === self::CORPORATE;
    }

    /**
     * @return array<int, string>
     */
    public static function domains(): array
    {
        return array_map('strtolower', app(GeneralSettings::class)->mail_allowed_domains ?? []);
    }
}
