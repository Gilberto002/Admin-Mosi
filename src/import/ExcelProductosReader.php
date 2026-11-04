<?php
// src/Import/ExcelProductosReader.php
// Lee el Excel de carga masiva del catalogo de productos (public/productos.php,
// boton "Cargar Excel") -- separado de ExcelPlanimetriaReader porque ese lee
// stock/planimetria de una maquina (carriles, lotes, fechas), mientras que
// este solo lee catalogo (clave, nombre, presentacion, descripcion, precio,
// sustancia activa), sin nada de inventario.
//
// Encabezados tolerados vía EncabezadoAlias (mayusculas/acentos/guiones no
// importan) -- a propósito, para que el MISMO Excel real de planimetría
// (Clave, Código de Barras, Descripción, Sustancia, Cabina, Ubicación,
// Lote, Caducidad, Stock) también sirva aquí: este lector solo toma lo que
// le interesa (clave, código de barras, nombre/descripción, sustancia
// activa) e ignora cabina/ubicación/lote/caducidad/stock -- esas las usa
// el flujo de "Cargar stock" (ExcelPlanimetriaReader), no este. Decisión
// del usuario 2026-09-09.
declare(strict_types=1);

namespace App\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;

final class ExcelProductosReader
{
    private const ALIAS = [
        'clave'            => ['clave', 'clave salud', 'clave de salud', 'clave cbciss', 'clave cuadro basico'],
        'codigo_barras'    => ['codigo barras', 'codigo de barras', 'cod barras', 'codigo', 'barcode', 'upc'],
        // Si el archivo no trae una columna "Nombre" dedicada (caso del
        // Excel real de planimetría, que solo trae "Descripción"), nombre
        // se resuelve con el valor de descripcion -- ver normalizarRegistro().
        'nombre'           => ['nombre', 'nombre generico', 'producto'],
        'presentacion'     => ['presentacion'],
        'descripcion'      => ['descripcion'],
        'precio'           => ['precio'],
        'sustancia_activa' => ['sustancia activa', 'sustancia'],
    ];

    /** @return array<int, array{clave:string, codigo_barras:string, nombre:string, presentacion:string, descripcion:string, precio:?float, sustancia_activa:string}> */
    public function leer(string $rutaArchivo): array
    {
        $spreadsheet = IOFactory::load($rutaArchivo);
        $hoja = $spreadsheet->getActiveSheet();
        $filas = $hoja->toArray(null, true, true, false);

        $encabezadosCrudos = array_map(static fn($valor): string => trim((string) $valor), $filas[0] ?? []);
        $encabezados = EncabezadoAlias::resolver($encabezadosCrudos, self::ALIAS);

        // Mismo guard que ExcelPlanimetriaReader: dos columnas que caen en la
        // misma clave canónica hacen que array_combine() se quede con la
        // última y la otra se pierda en silencio (bug real 2026-11-04 con
        // "CANTIDAD" + "STOCK"). Se rechaza el archivo en vez de adivinar.
        $colisiones = EncabezadoAlias::colisiones($encabezadosCrudos, self::ALIAS);
        if ($colisiones !== []) {
            $detalle = [];
            foreach ($colisiones as $canonico => $crudos) {
                $detalle[] = '"' . implode('" y "', $crudos) . '" (las ' . count($crudos) . ' significan ' . $canonico . ')';
            }
            throw new \RuntimeException(
                'El Excel tiene columnas repetidas que significan lo mismo: ' . implode('; ', $detalle)
                . '. Deja solo una de cada grupo (borra o renombra la otra) y vuelve a subirlo.'
            );
        }

        $datos = [];
        for ($i = 1; $i < count($filas); $i++) {
            $fila = $filas[$i];
            if (empty(array_filter($fila, static fn($v) => trim((string) $v) !== ''))) {
                continue; // fila vacía
            }
            $registro = array_combine($encabezados, $fila);
            $datos[] = $this->normalizarRegistro($registro);
        }

        return $datos;
    }

    private function normalizarRegistro(array $r): array
    {
        $precioTexto = trim((string) ($r['precio'] ?? ''));
        $descripcion = trim((string) ($r['descripcion'] ?? ''));

        // Respaldo: si no hay columna "Nombre" propia (el Excel real de
        // planimetría no la trae, solo "Descripción"), se usa descripcion
        // como nombre -- así una fila nunca queda con nombre vacío solo
        // porque el archivo usa el otro layout.
        $nombre = trim((string) ($r['nombre'] ?? ''));
        if ($nombre === '') {
            $nombre = $descripcion;
        }

        return [
            'clave'            => trim((string) ($r['clave'] ?? '')),
            'codigo_barras'    => trim((string) ($r['codigo_barras'] ?? '')),
            'nombre'           => $nombre,
            'presentacion'     => trim((string) ($r['presentacion'] ?? '')),
            'descripcion'      => $descripcion,
            'precio'           => $precioTexto !== '' && is_numeric($precioTexto) ? (float) $precioTexto : null,
            'sustancia_activa' => trim((string) ($r['sustancia_activa'] ?? '')),
        ];
    }
}
