<?php

declare(strict_types=1);

/**
 * Lector de archivo .env sin dependencias externas
 * (reemplaza a vlucas/phpdotenv, que requeriría Composer).
 *
 * Formato soportado: LINEA = "NOMBRE=valor", ignora líneas vacías
 * y líneas que empiezan con "#".
 */
function cargarEnv(string $rutaArchivo): void
{
    if (!is_file($rutaArchivo)) {
        return;
    }

    $lineas = file($rutaArchivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lineas as $linea) {
        $linea = trim($linea);

        if ($linea === '' || str_starts_with($linea, '#')) {
            continue;
        }

        if (!str_contains($linea, '=')) {
            continue;
        }

        [$nombre, $valor] = explode('=', $linea, 2);
        $nombre = trim($nombre);
        $valor = trim($valor);

        // Quita comillas envolventes si las tiene: "algo" o 'algo'
        if (strlen($valor) >= 2) {
            $primera = $valor[0];
            $ultima = $valor[strlen($valor) - 1];
            if (($primera === '"' && $ultima === '"') || ($primera === "'" && $ultima === "'")) {
                $valor = substr($valor, 1, -1);
            }
        }

        $_ENV[$nombre] = $valor;
        putenv("{$nombre}={$valor}");
    }
}
