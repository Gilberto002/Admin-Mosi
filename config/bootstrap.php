<?php

declare(strict_types=1);

/**
 * Punto de arranque de la aplicacion.
 * Se incluye desde public/index.php y desde cualquier script en /scripts.
 *
 * Nota: este proyecto no requiere Composer para arrancar. Si existe vendor,
 * se carga; si no existe, funciona con el autoloader manual.
 */

require_once __DIR__ . '/autoload.php';

$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

require_once __DIR__ . '/env.php';

$raiz = dirname(__DIR__);
cargarEnv($raiz . '/.env');

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Mexico_City');

\App\Security\Security::configureSession();
\App\Security\Security::sendSecurityHeaders();

\App\Auth\Auth::start();
\App\Security\Security::ensureCsrfToken();

$esDev = ($_ENV['APP_ENV'] ?? 'production') === 'development';

ini_set('display_errors', $esDev ? '1' : '0');
error_reporting($esDev ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);
