<?php

namespace App\Support;

use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Support\HtmlString;

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

    /**
     * Banner for the signed-in user when their own address cannot receive
     * notification mail (empty, mistyped or personal), or null.
     */
    public static function ownWarning(?User $user): ?HtmlString
    {
        $status = $user ? self::status($user->email) : self::CORPORATE;

        if ($status === self::CORPORATE) {
            return null;
        }

        $message = $status === self::MISSING
            ? __('ui.own_email_missing')
            : __('ui.own_email_not_company', ['email' => $user->email]);

        return new HtmlString(
            '<div style="display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:10px;'
            .'border:1px solid rgba(217,119,6,.45);border-left:4px solid #d97706;background:rgba(217,119,6,.08);">'
            .'<span aria-hidden="true">⚠️</span><span>'.e($message).' '.e(__('ui.own_email_follow_bell')).'</span></div>'
        );
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
