<?php

namespace App\Services\Media;

final class MediaUrl
{
    public static function image(?string $name): ?string
    {
        return self::path($name);
    }

    public static function path(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $baseUrl = rtrim((string) config('media.public_url'), '/');
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));

        return $baseUrl
            ? $baseUrl . '/api/v1/files/' . $encodedPath
            : null;
    }
}
