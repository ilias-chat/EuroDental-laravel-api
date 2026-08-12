<?php

/** Write the production .env from GitHub Actions secrets. */
function envLine(string $key, string $value): string
{
    if ($value === '' || preg_match('/[\s#"\';\\\\]/', $value)) {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        return $key . '="' . $escaped . '"';
    }

    return $key . '=' . $value;
}

$required = [
    'APP_KEY', 'APP_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
    'MEDIA_SERVICE_URL', 'MEDIA_PUBLIC_URL', 'MEDIA_SERVICE_TOKEN',
    'MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
    'VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY',
];

foreach ($required as $name) {
    $value = getenv($name);
    if ($value === false || $value === '') {
        fwrite(STDERR, "Missing required environment variable: {$name}\n");
        exit(1);
    }
}

$appKey = trim(getenv('APP_KEY'));
$appKey = preg_replace('/^APP_KEY=/', '', $appKey);
$appKey = trim($appKey, " \t\n\r\0\x0B\"'");
if (! str_starts_with($appKey, 'base64:')) {
    fwrite(STDERR, "APP_KEY must start with base64: (paste only the key value in GitHub secrets)\n");
    exit(1);
}

$decoded = base64_decode(substr($appKey, 7), true);
if ($decoded === false || strlen($decoded) !== 32) {
    fwrite(STDERR, "APP_KEY must decode to 32 bytes. Check the secret value.\n");
    exit(1);
}

$lines = [
    'APP_NAME="EuroDental API"',
    'APP_ENV=production',
    envLine('APP_KEY', $appKey),
    'APP_DEBUG=false',
    envLine('APP_URL', getenv('APP_URL')),
    '',
    'L5_SWAGGER_API_TITLE="EuroDental CRM and Webshop API"',
    'L5_SWAGGER_GENERATE_ALWAYS=false',
    'L5_SWAGGER_UI_DARK_MODE=false',
    envLine('L5_SWAGGER_CONST_HOST', getenv('APP_URL')),
    '',
    'APP_LOCALE=fr',
    'APP_FALLBACK_LOCALE=fr',
    'APP_FAKER_LOCALE=fr_FR',
    'LOG_CHANNEL=stack',
    'LOG_LEVEL=error',
    '',
    'DB_CONNECTION=mysql',
    envLine('DB_HOST', getenv('DB_HOST')),
    envLine('DB_PORT', getenv('DB_PORT')),
    envLine('DB_DATABASE', getenv('DB_DATABASE')),
    envLine('DB_USERNAME', getenv('DB_USERNAME')),
    envLine('DB_PASSWORD', getenv('DB_PASSWORD')),
    '',
    'SESSION_DRIVER=array',
    'SESSION_LIFETIME=120',
    'QUEUE_CONNECTION=sync',
    'CACHE_STORE=file',
    'FILESYSTEM_DISK=media',
    '',
    envLine('MEDIA_SERVICE_URL', getenv('MEDIA_SERVICE_URL')),
    envLine('MEDIA_PUBLIC_URL', getenv('MEDIA_PUBLIC_URL')),
    envLine('MEDIA_SERVICE_TOKEN', getenv('MEDIA_SERVICE_TOKEN')),
    '',
    envLine('SANCTUM_STATEFUL_DOMAINS', getenv('SANCTUM_STATEFUL_DOMAINS') ?: ''),
    envLine('CORS_ALLOWED_ORIGINS', getenv('CORS_ALLOWED_ORIGINS') ?: ''),
    '',
    envLine('MAIL_MAILER', getenv('MAIL_MAILER')),
    envLine('MAIL_SCHEME', getenv('MAIL_SCHEME') ?: 'null'),
    envLine('MAIL_HOST', getenv('MAIL_HOST')),
    envLine('MAIL_PORT', getenv('MAIL_PORT')),
    envLine('MAIL_USERNAME', getenv('MAIL_USERNAME')),
    envLine('MAIL_PASSWORD', getenv('MAIL_PASSWORD')),
    envLine('MAIL_ENCRYPTION', getenv('MAIL_ENCRYPTION') ?: 'null'),
    envLine('MAIL_FROM_ADDRESS', getenv('MAIL_FROM_ADDRESS')),
    envLine('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME')),
    '',
    envLine('VAPID_PUBLIC_KEY', getenv('VAPID_PUBLIC_KEY')),
    envLine('VAPID_PRIVATE_KEY', getenv('VAPID_PRIVATE_KEY')),
    envLine('VAPID_SUBJECT', getenv('VAPID_SUBJECT') ?: 'mailto:contact@eurodental.ma'),
    '',
];

file_put_contents(__DIR__ . '/../.env', implode(PHP_EOL, $lines));
echo "Wrote .env for deploy.\n";
