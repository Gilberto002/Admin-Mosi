<?php

declare(strict_types=1);

/**
 * Autoloader manual (reemplaza a "composer install" + vendor/autoload.php).
 * No necesitas Composer para nada de esto: solo sube los archivos por FTP.
 *
 * Convierte:  App\Database\Connection  ->  src/Database/Connection.php
 */

spl_autoload_register(function (string $clase): void {
    $prefijo = 'App\\';
    $directorioBase = dirname(__DIR__) . '/src/';

    if (!str_starts_with($clase, $prefijo)) {
        return; // no es una clase nuestra, la ignoramos
    }

    $rutaRelativa = substr($clase, strlen($prefijo));
    $rutaArchivo = $directorioBase . str_replace('\\', '/', $rutaRelativa) . '.php';

    if (is_file($rutaArchivo)) {
        require $rutaArchivo;
    }
});
