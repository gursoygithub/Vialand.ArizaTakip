<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Legacy ticket flow
    |--------------------------------------------------------------------------
    |
    | The pre-2026-11 area / unit / group / SLA-policy ticket flow. Its screens
    | (Arıza Talepleri, Performans Paneli, Birimler, SLA Politikaları,
    | Gruplar, Şirket Kurulumu and the old dashboard widgets) are hidden and
    | unreachable unless this is switched on. The code and data are kept.
    |
    */

    'enabled' => (bool) env('LEGACY_TICKETS_ENABLED', false),

];
