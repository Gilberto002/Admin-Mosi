<?php
declare(strict_types=1);

namespace App\Maquinas;

use PDO;

final class SegmentoMaquina
{
    public const CLIENTE = 'cliente';

    public static function asegurarColumnas(PDO $pdo): void
    {
        $pdo->exec("
            IF COL_LENGTH('vendingm.maquinas', 'segmento_operacion') IS NULL
            BEGIN
                ALTER TABLE vendingm.maquinas ADD segmento_operacion NVARCHAR(20) NOT NULL
                    CONSTRAINT DF_maquinas_segmento_operacion DEFAULT 'cliente';
            END
        ");
    }

    public static function normalizar(?string $segmento): string
    {
        return self::CLIENTE;
    }

    public static function etiqueta(string $segmento): string
    {
        return 'Clientes';
    }

    public static function requeridoDesdeConstante(): ?string
    {
        if (!defined('APP_ANDROID_SEGMENTO_REQUERIDO')) {
            return null;
        }

        return self::normalizar((string) constant('APP_ANDROID_SEGMENTO_REQUERIDO'));
    }

    public static function requerirSiAplica(array $maquina): void
    {
        // La plataforma opera exclusivamente con maquinas de clientes.
    }
}
