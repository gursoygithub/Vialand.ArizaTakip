<?php

namespace App\Helpers;

use Carbon\Carbon;

class DateHelper
{
    /**
     * Dışa aktarma (Export) için tarihi ve/veya saati Türkçe ay adlarıyla formatlar.
     * * * Bu metot, Carbon'ın yerel ayar desteğini kullanarak Türkçe ay adlarını garantiler.
     * * @param string|null $dateTimeString Çıktı alınacak tarih/saat dizesi.
     * @param string $format İstenen Carbon format dizesi (Örn: 'd F Y' veya 'd F Y - H:i').
     * @return string Formatlanmış tarih veya boş dize.
     */
    public static function formatForExport(?string $dateTimeString, string $format = 'd F Y'): string
    {
        if (empty($dateTimeString)) {
            return '';
        }

        $date = Carbon::parse($dateTimeString);

        // Carbon'ı Türkçe yerel ayarına zorla
        $date->setLocale('tr');

        // Carbon'un kendi formatlama metodu ile formatla
        // Carbon, F (Ay Adı) kodunu kullanırken yerel ayarı dikkate alacaktır.
        return $date->translatedFormat($format);
    }

    public function __construct()
    {
        //
    }
}