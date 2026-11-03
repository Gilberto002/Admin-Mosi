<?php
// src/Import/EncabezadoAlias.php
// Resolución de encabezados de Excel tolerante a mayúsculas/acentos/guiones
// y a sinónimos razonables, compartida entre ExcelPlanimetriaReader y
// ExcelProductosReader -- así el mismo archivo real (planimetría, con
// columnas como "CÓDIGO DE BARRAS"/"DESCRIPCIÓN"/"CLAVE"/etc.) puede pasar
// por cualquiera de los dos importadores sin que cada uno tenga su propia
// copia de esta lógica. Decisión del usuario 2026-09-09.
declare(strict_types=1);

namespace App\Import;

final class EncabezadoAlias
{
    /**
     * Traduce cada encabezado crudo de la fila 1 a su clave canónica según
     * $aliasPorCanonico (canonico => [alias normalizados...]). Un
     * encabezado que no matchea ningún alias se deja tal cual (columna
     * extra que el lector correspondiente simplemente ignora).
     *
     * @param list<string> $encabezadosCrudos
     * @param array<string, list<string>> $aliasPorCanonico
     * @return list<string>
     */
    public static function resolver(array $encabezadosCrudos, array $aliasPorCanonico): array
    {
        $mapa = [];
        foreach ($aliasPorCanonico as $canonico => $alias) {
            foreach ($alias as $a) {
                $mapa[$a] = $canonico;
            }
        }

        return array_map(
            static fn(string $h): string => $mapa[self::normalizar($h)] ?? $h,
            $encabezadosCrudos
        );
    }

    /**
     * Minusculas, sin acentos/diéresis, sin guiones ni guion_bajo (se tratan
     * como espacio), espacios colapsados. "Código Barras", "codigo_barras" y
     * "CODIGO-BARRAS" normalizan igual: "codigo barras".
     */
    public static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ñ' => 'n', 'ü' => 'u',
        ]);
        $texto = preg_replace('/[_\-]+/', ' ', $texto);
        $texto = preg_replace('/\s+/', ' ', $texto);
        return trim((string) $texto);
    }
}
