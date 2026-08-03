<?php

$productionMediaUrl = env('APP_ENV') === 'production'
    ? 'https://media.eurodental.ma'
    : null;

return [
    'service_url' => env('MEDIA_SERVICE_URL', $productionMediaUrl),
    'public_url' => env('MEDIA_PUBLIC_URL', env('MEDIA_SERVICE_URL', $productionMediaUrl)),
    'token' => env('MEDIA_SERVICE_TOKEN'),
];
