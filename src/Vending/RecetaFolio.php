<?php
declare(strict_types=1);

namespace App\Vending;

use PDO;

final class RecetaFolio
{
    public const LONGITUD = 10;

    public static function asegurarColumnas(PDO $pdo): void
    {
        $pdo->exec("
            IF OBJECT_ID('vendingm.recetas', 'U') IS NOT NULL
               AND COL_LENGTH('vendingm.recetas', 'codigo_receta') IS NULL
            BEGIN
                ALTER TABLE vendingm.recetas ADD codigo_receta NVARCHAR(10) NULL;
            END
        ");

        $pdo->exec("
            IF OBJECT_ID('vendingm.recetas', 'U') IS NOT NULL
               AND COL_LENGTH('vendingm.recetas', 'tipo_receta') IS NULL
            BEGIN
                ALTER TABLE vendingm.recetas ADD tipo_receta NVARCHAR(30) NOT NULL
                    CONSTRAINT DF_recetas_tipo_receta DEFAULT 'cliente';
            END
        ");

        $pdo->exec("
            IF OBJECT_ID('vendingm.recetas', 'U') IS NOT NULL
               AND COL_LENGTH('vendingm.recetas', 'codigo_receta') IS NOT NULL
               AND NOT EXISTS (
                    SELECT 1
                    FROM sys.indexes
                    WHERE object_id = OBJECT_ID('vendingm.recetas')
                      AND name = 'UX_recetas_codigo_receta'
               )
            BEGIN
                CREATE UNIQUE INDEX UX_recetas_codigo_receta
                ON vendingm.recetas (codigo_receta)
                WHERE codigo_receta IS NOT NULL;
            END
        ");
    }

    public static function siguiente(PDO $pdo): string
    {
        self::asegurarColumnas($pdo);

        $stmt = $pdo->query("
            SELECT ISNULL(MAX(CAST(codigo_receta AS BIGINT)), 0) + 1
            FROM vendingm.recetas WITH (TABLOCKX, HOLDLOCK)
            WHERE codigo_receta IS NOT NULL
              AND LEN(codigo_receta) = " . self::LONGITUD . "
              AND codigo_receta NOT LIKE '%[^0-9]%'
        ");
        $siguiente = (int) $stmt->fetchColumn();

        return str_pad((string) $siguiente, self::LONGITUD, '0', STR_PAD_LEFT);
    }
}
