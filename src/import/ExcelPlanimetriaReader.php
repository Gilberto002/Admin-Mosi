<?php
// src/Import/ExcelPlanimetriaReader.php
declare(strict_types=1);

namespace App\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

final class ExcelPlanimetriaReader
{
    // Alias aceptados por columna, ya normalizados (ver normalizarEncabezado):
    // minusculas, sin acentos, sin guiones/guion_bajo. Los encabezados
    // "oficiales" de siempre (Código Barras, Nombre, UBICACIÓN, etc.) siguen
    // funcionando tal cual -- esto solo agrega tolerancia a mayusculas,
    // acentos, guiones/espacios y algunos sinonimos razonables, para que un
    // Excel con encabezados ligeramente distintos no rompa la columna en
    // silencio. Decision del usuario 2026-09-09 (carga de stock mas flexible).
    private const ALIAS = [
        'codigo_barras'    => ['codigo barras', 'codigo de barras', 'cod barras', 'codigo', 'barcode', 'upc'],
        // "clave" agregado 2026-09-09: Clave del Cuadro Basico/CIS (formato
        // 'NNN.NNN.NNNN.NN') -- pasa a ser la llave de identidad del
        // catalogo de vendingm.productos (ver CatalogoUpserter), no solo un
        // dato mas de catalogo.
        'clave'            => ['clave', 'clave salud', 'clave de salud', 'clave cbciss', 'clave cuadro basico'],
        // "descripcion" agregado 2026-09-09: el layout real recibido usa
        // "DESCRIPCIÓN" como el nombre del producto (no hay columna aparte
        // de nombre comercial en ese archivo).
        'nombre'           => ['nombre', 'nombre generico', 'producto', 'descripcion'],
        'nombre_comercial' => ['nombre comercial', 'comercial'],
        'sustancia_activa' => ['sustancia activa', 'sustancia'],
        'ubicacion_carril' => ['ubicacion', 'carril', 'numero carril', 'no carril', 'num carril'],
        'cabina'           => ['cabina'],
        'exhibicion'       => ['exhibicion'],
        'grupo'            => ['grupo'],
        'uso_terapeutico'  => ['uso terapeutico'],
        'numero_lote'      => ['lote', 'numero lote', 'no lote', 'num lote'],
        'fecha_caducidad'  => ['fecha caducidad', 'caducidad', 'fecha de caducidad'],
        // "stock" agregado 2026-09-09: mismo layout real, la columna de
        // cantidad se llama "STOCK".
        'cantidad'         => ['cantidad', 'cant', 'stock'],
    ];

    /** @return array<int, array<string, mixed>> filas normalizadas */
    public function leer(string $rutaArchivo): array
    {
        $spreadsheet = IOFactory::load($rutaArchivo);
        $hoja = $spreadsheet->getActiveSheet();
        $filas = $hoja->toArray(null, true, true, false);

        $encabezadosCrudos = array_map(static fn($valor): string => trim((string) $valor), $filas[0] ?? []);
        // Cada encabezado crudo se traduce a su clave canonica (ej. "Código
        // Barras" o "codigo_de_barras" -> "codigo_barras"); si no matchea
        // ningun alias conocido se deja tal cual (columna extra que
        // simplemente se ignora mas adelante, no rompe el resto).
        $encabezados = EncabezadoAlias::resolver($encabezadosCrudos, self::ALIAS);
        $this->validarSinColumnasDuplicadas($encabezadosCrudos);
        $this->validarColumnasRequeridas($encabezados);
        $colFecha = array_search('fecha_caducidad', $encabezados, true);

        $datos = [];

        for ($i = 1; $i < count($filas); $i++) {
            $fila = $filas[$i];
            if (empty(array_filter($fila))) {
                continue; // fila vacía
            }
            $registro = array_combine($encabezados, $fila);

            // La fecha se lee aparte, directo de la celda, porque toArray()
            // puede devolver el número serial de Excel en vez de una fecha
            // legible dependiendo del formato de la columna.
            $fechaCaducidad = null;
            if ($colFecha !== false) {
                $numeroFilaExcel = $i + 1; // toArray es 0-index, filas de Excel son 1-index
                $columnaLetra = Coordinate::stringFromColumnIndex($colFecha + 1);
                $celda = $hoja->getCell($columnaLetra . $numeroFilaExcel);
                $fechaCaducidad = $this->normalizarFecha($celda->getValue());
            }

            $datos[] = $this->normalizarRegistro($registro, $fechaCaducidad);
        }

        return $datos;
    }

    /**
     * Convierte el valor crudo de la celda de fecha a formato Y-m-d.
     * Acepta número serial de Excel, texto en d/m/Y, Y-m-d, o Y-m (año-mes
     * sin día, frecuente en planimetría: se toma el último día del mes).
     * Devuelve null si no se pudo interpretar (se reporta como error después).
     */
    private function normalizarFecha($valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (is_numeric($valor)) {
            $dt = ExcelDate::excelToDateTimeObject((float) $valor);
            return $dt->format('Y-m-d');
        }

        $texto = trim((string) $valor);

        // Caso frecuente en planimetría: la fecha viene como "2026-09"
        // (año-mes, sin día). Se toma el día 1 de ese mes.
        if (preg_match('/^(\d{4})-(\d{2})$/', $texto, $m)) {
            $dt = \DateTime::createFromFormat('Y-m-d', "{$m[1]}-{$m[2]}-01");
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        foreach (['d/m/Y', 'Y-m-d'] as $formato) {
            $dt = \DateTime::createFromFormat($formato, $texto);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * Rechaza el Excel si dos columnas distintas significan lo mismo.
     *
     * Bug real 2026-11-04: con "CANTIDAD" y "STOCK" en el mismo archivo
     * (las dos son alias de 'cantidad'), array_combine() en leer() se queda
     * con la ÚLTIMA. Si esa venía vacía, TODAS las cantidades entraban en 0
     * y la carga terminaba sin un solo error -- las filas se descartaban
     * calladas en LoteRegistrador ("Cantidad inválida en el Excel") y el
     * endpoint devolvía ok:true.
     *
     * Se rechaza en vez de elegir una: adivinar en silencio fue el bug.
     *
     * @param list<string> $encabezadosCrudos texto literal de la fila 1
     */
    private function validarSinColumnasDuplicadas(array $encabezadosCrudos): void
    {
        $colisiones = EncabezadoAlias::colisiones($encabezadosCrudos, self::ALIAS);
        if ($colisiones === []) {
            return;
        }

        $detalle = [];
        foreach ($colisiones as $canonico => $crudos) {
            $detalle[] = '"' . implode('" y "', $crudos) . '" (las ' . count($crudos) . ' significan ' . $canonico . ')';
        }

        throw new \RuntimeException(
            'El Excel tiene columnas repetidas que significan lo mismo: ' . implode('; ', $detalle)
            . '. Deja solo una de cada grupo (borra o renombra la otra) y vuelve a subirlo.'
        );
    }

    /**
     * Valida ANTES de leer ninguna fila que el Excel traiga (por su nombre
     * "oficial" o cualquier alias de self::ALIAS) las columnas que el resto
     * del flujo de "Cargar stock" necesita -- si falta alguna, el resto del
     * pipeline no tronaba, pero silenciosamente dejaba esos campos vacíos
     * en TODAS las filas (ver ExcelColumnasFaltantesException). "Código de
     * barras" y "Clave" se validan como grupo: basta con que exista una de
     * las dos (el Excel "solo stock" confía únicamente en clave).
     *
     * @param list<string> $encabezados ya traducidos a claves canónicas
     */
    private function validarColumnasRequeridas(array $encabezados): void
    {
        $faltantes = [];

        if (!in_array('codigo_barras', $encabezados, true) && !in_array('clave', $encabezados, true)) {
            $faltantes[] = 'Código de barras o Clave';
        }

        foreach ([
            'nombre'           => 'Nombre/Descripción',
            'ubicacion_carril' => 'Carril/Ubicación',
            'cantidad'         => 'Cantidad/Stock',
        ] as $clave => $etiqueta) {
            if (!in_array($clave, $encabezados, true)) {
                $faltantes[] = $etiqueta;
            }
        }

        if ($faltantes !== []) {
            throw new ExcelColumnasFaltantesException($faltantes);
        }
    }

    private function normalizarRegistro(array $r, ?string $fechaCaducidad): array
{
    // Claves canonicas (ya traducidas desde el encabezado real por
    // mapaAliasCanonico()/normalizarEncabezado() en leer()), no el texto
    // literal del encabezado -- así el Excel puede traer "Código Barras",
    // "codigo_de_barras" o "COD BARRAS" indistintamente.
    $cabina       = (int) trim((string)($r['cabina'] ?? 1));
    $ubicacionRaw = trim((string)($r['ubicacion_carril'] ?? ''));
    $carril       = $this->convertirCarril($ubicacionRaw, $cabina);

    return [
        'codigo_barras'     => trim((string)($r['codigo_barras'] ?? '')),
        'clave'             => trim((string)($r['clave'] ?? '')),
        'nombre'            => trim((string)($r['nombre'] ?? '')),
        'nombre_comercial'  => trim((string)($r['nombre_comercial'] ?? '')),
        'sustancia_activa'  => trim((string)($r['sustancia_activa'] ?? '')),
        'ubicacion_carril'  => $ubicacionRaw,   // se guarda tal cual vino, para trazabilidad/errores
        'cabina'            => $cabina,          // 1 = principal, 2 = esclava
        'numero_carril'     => $carril,          // ya normalizado (null si no se pudo interpretar)
        'exhibicion'        => trim((string)($r['exhibicion'] ?? '')),
        'grupo'             => trim((string)($r['grupo'] ?? '')),
        'uso_terapeutico'   => trim((string)($r['uso_terapeutico'] ?? '')),
        'numero_lote'       => trim((string)($r['numero_lote'] ?? '')),
        'fecha_caducidad'   => $fechaCaducidad,
        'cantidad'          => (int) trim((string)($r['cantidad'] ?? 0)),
    ];
}

/**
 * Cabina 1 (principal, 69 carriles): el número va tal cual (1-69).
 * Cabina 2 (esclava, 58 carriles): normalmente viene prefijado con 1
 * (101-158). Si detectamos ese prefijo lo quitamos (-100). Si ya viene
 * en rango 1-58 se deja igual.
 */
private function convertirCarril(string $ubicacionRaw, int $cabina): ?int
{
    $texto = trim($ubicacionRaw);
    // No usar filter_var(FILTER_VALIDATE_INT) aquí: rechaza strings con
    // cero a la izquierda (ej. "011") por ambigüedad con notación octal.
    if ($texto === '' || !ctype_digit($texto)) {
        return null;
    }
    $n = (int) $texto;
    if ($cabina === 2 && $n > 58) {
        $n -= 100;
    }
    return $n;
}
}
