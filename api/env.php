<?php
// api/env.php — demo mode: loadEnv() is a no-op; credentials come from demo/db-config.php
declare(strict_types=1);

function loadEnv(string $path): void {
    // No-op in demo. All config is loaded from demo/db-config.php.
    // The real .env (payment/.env) has been removed from this repo.
}

if (!function_exists('envv')) {
    function envv(string $k, string $default = ''): string {
        return $default;
    }
}
