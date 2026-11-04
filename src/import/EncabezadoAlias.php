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
     * Detecta encabezados distintos que caen en la MISMA clave canónica.
     *
     * Bug real 2026-11-04: un Excel con las columnas "CANTIDAD" y "STOCK"
     * (ambas alias de 'cantidad') pasaba la validación de columnas
     * requeridas -- que solo mira que la clave exista -- y después
     * array_combine() en el lector se quedaba con la ÚLTIMA, así que si esa
     * venía vacía TODAS las cantidades entraban en 0 sin un solo error
     * visible. Lo mismo aplica a 'nombre', que acepta "NOMBRE", "PRODUCTO"
     * y "DESCRIPCIÓN" a la vez.
     *
     * No se elige una por el lector a propósito: adivinar en silencio fue
     * justo lo que produjo el bug. El archivo se rechaza y el admin decide
     * cuál columna quitar o renombrar.
     *
     * @param list<string> $encabezadosCrudos
     * @param array<string, list<string>> $aliasPorCanonico
     * @return array<string, list<string>> canonico => [encabezados crudos que chocan]
     */
    public static function colisiones(array $encabezadosCrudos, array $aliasPorCanonico): array
    {
        $resueltos = self::resolver($encabezadosCrudos, $aliasPorCanonico);

        $porCanonico = [];
        foreach ($resueltos as $i => $canonico) {
            // Solo interesan las claves canónicas conocidas: un encabezado
            // que no matcheó ningún alias se queda con su texto crudo y dos
            // columnas con el mismo nombre literal no son una colisión de
            // alias (esas las ignora el lector igual).
            if (!isset($aliasPorCanonico[$canonico])) {
                continue;
            }
            $porCanonico[$canonico][] = $encabezadosCrudos[$i];
        }

        return array_filter($porCanonico, static fn(array $crudos): bool => count($crudos) > 1);
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
