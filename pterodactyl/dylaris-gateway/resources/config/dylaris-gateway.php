<?php

// In resources/: p:extension:pack ships only routes, database, resources, dist, vendor and the autoload folders.
// Read through config(), so it survives `php artisan config:cache` (env() returns null for .env values once the config
// is cached). Only for DYLARIS' own tests against another provider; it must be an https:// URL.
return [
    'provider_url' => env('DYLARIS_GATEWAY_PROVIDER_URL'),
];
