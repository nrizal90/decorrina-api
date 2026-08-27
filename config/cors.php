<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Origin frontend (Vite/React) diizinkan memanggil REST API ini. Daftar
    | origin diambil dari env `CORS_ALLOWED_ORIGINS` (dipisah koma) agar beda
    | environment (dev/staging/prod) tinggal ganti .env tanpa ubah kode.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:5173')))
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Bearer-token auth (Sanctum personal access token) tidak butuh cookie,
    // jadi credentials dibiarkan false. Set true hanya bila pindah ke mode
    // SPA cookie/session.
    'supports_credentials' => false,

];
