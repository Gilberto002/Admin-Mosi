<?php

declare(strict_types=1);

namespace App\Config;

final class Features
{
    private function __construct()
    {
    }

    public static function enabled(string $name, bool $default = false): bool
    {
        $key = 'FEATURE_' . strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name));
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
