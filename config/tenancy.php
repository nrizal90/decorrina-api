<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Resolusi Tenant untuk Jalur Publik
    |--------------------------------------------------------------------------
    |
    | Endpoint publik (katalog villa A2/A3) diakses tanpa login, sehingga tenant
    | tidak bisa diturunkan dari `users.tenant_id`. PublicTenantMiddleware
    | membacanya dari header `X-Tenant` (slug), dan jatuh ke `default_slug` bila
    | header tidak dikirim — supaya dev/demo satu klien jalan tanpa setup DNS.
    |
    | Saat pindah ke skema subdomain, cukup ganti isi PublicTenantMiddleware;
    | controller & resource tidak perlu diubah.
    |
    */

    'header' => env('TENANT_HEADER', 'X-Tenant'),

    'default_slug' => env('TENANT_DEFAULT_SLUG', 'decorinna'),

];
