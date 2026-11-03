<?php
// src/Import/AnchoColumnas.php
// Lee el ancho REAL (en caracteres) de las columnas de texto de una tabla
// desde INFORMATION_SCHEMA.COLUMNS, en vez de asumir/hardcodear tamaños --
// ya se comprobó que ese esquema exportado (docs/esquema_db.csv, del
// 2026-08-24) puede estar desactualizado: "clave" resultó ser VARCHAR(15)
// en la BD real, no NVARCHAR(20) como se había asumido, y eso causó un
// SQLSTATE 22001 en producción. Se usa para truncar defensivamente antes
// de mandar texto a SQL. Decision del usuario 2026-09-09.
declare(strict_types=1);

namespace App\Import;

use PDO;

final class AnchoColumnas
{
    /** @var array<string, array<string, int>> cache por "esquema.tabla", vive solo mientras dure el request */
    private static array $cache = [];

    /**
     * @return array<string, int> columna => CHARACTER_MAXIMUM_LENGTH (caracteres, no bytes -- ver comentario de ProductoCatalogo::asegurarColumnas() sobre por que COL_LENGTH no sirve para esto)
     */
    public static function obtener(PDO $pdo, string $esquema, string $tabla): array
    {
        $clave = "{$esquema}.{$tabla}";
        if (isset(self::$cache[$clave])) {
            return self::$cache[$clave];
        }

        $anchos = [];
        try {
            $stmt = $pdo->prepare(
                'SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = :esquema AND TABLE_NAME = :tabla
                   AND CHARACTER_MAXIMUM_LENGTH IS NOT NULL AND CHARACTER_MAXIMUM_LENGTH > 0'
            );
            $stmt->execute(['esquema' => $esquema, 'tabla' => $tabla]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                $anchos[$fila['COLUMN_NAME']] = (int) $fila['CHARACTER_MAXIMUM_LENGTH'];
            }
        } catch (\Throwable) {
            // Si por lo que sea falla la consulta al esquema, se cae a los
            // valores por defecto que pase el llamador -- nunca debe
            // tumbar la carga completa solo por no poder leerlo.
        }

        self::$cache[$clave] = $anchos;
        return $anchos;
    }
}
