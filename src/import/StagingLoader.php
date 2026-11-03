<?php
// src/Import/StagingLoader.php
declare(strict_types=1);

namespace App\Import;

use PDO;

final class StagingLoader
{
    public function __construct(private PDO $pdo) {}

    /**
     * Agrega la columna "clave" a vendingm.staging_productos_excel si
     * todavía no existe -- mismo patrón de auto-migración que
     * App\Catalogo\ProductoCatalogo::asegurarColumnas(). Necesario desde
     * que CatalogoUpserter empezó a usar "clave" (Cuadro Básico/CIS) como
     * llave de identidad del catálogo de vendingm (decisión del usuario
     * 2026-09-09).
     */
    public function asegurarColumnas(): void
    {
        // Cada sentencia en su propio exec() -- no todas juntas en un solo
        // lote -- mas facil de diagnosticar si alguna falla.
        //
        // "clave" (Cuadro Basico/CIS) es exactamente 15 caracteres (ej.
        // '010.000.5165.00') -- VARCHAR(15) es el tamano correcto, igual
        // que en vendingm.productos (ver
        // App\Catalogo\ProductoCatalogo::asegurarColumnas() para el
        // detalle: un intento anterior la ensancho a 30 por asumir mal que
        // ahi estaba el truncamiento SQLSTATE 22001 visto en produccion
        // 2026-09-09; confirmado despues que no, la clave siempre cabe
        // justa en 15). No volver a ensanchar sin evidencia real.
        $this->pdo->exec("
            IF COL_LENGTH('vendingm.staging_productos_excel', 'clave') IS NULL
                ALTER TABLE vendingm.staging_productos_excel ADD clave VARCHAR(15) NULL;
        ");
        // CatalogoUpserter::procesar() guarda aquí el id_producto que
        // resolvió (por clave o por codigo_barras) para cada fila -- así
        // AsignadorCarriles/LoteRegistrador ya no necesitan volver a
        // cruzar por codigo_barras (que puede venir vacío en un Excel de
        // solo stock que confía en la clave). Decisión del usuario
        // 2026-09-09.
        $this->pdo->exec("
            IF COL_LENGTH('vendingm.staging_productos_excel', 'id_producto') IS NULL
                ALTER TABLE vendingm.staging_productos_excel ADD id_producto INT NULL;
        ");
    }

    public function crearReabastecimiento(string $nombreArchivo, string $usuario): int
    {
        // Riesgo bajo (nombreArchivo es un nombre temporal generado por
        // nosotros, usuario es un correo) pero se recorta igual, por
        // consistencia con el resto de la cadena -- ver comentario en
        // insertarFilas().
        $anchos = AnchoColumnas::obtener($this->pdo, 'vendingm', 'reabastecimientos');
        $stmt = $this->pdo->prepare(
            'INSERT INTO vendingm.reabastecimientos (nombre_archivo_origen, usuario_operador)
             OUTPUT INSERTED.id_reabastecimiento
             VALUES (:archivo, :usuario)'
        );
        $stmt->execute([
            'archivo' => mb_substr($nombreArchivo, 0, $anchos['nombre_archivo_origen'] ?? 255, 'UTF-8'),
            'usuario' => mb_substr($usuario, 0, $anchos['usuario_operador'] ?? 255, 'UTF-8'),
        ]);
        return (int) $stmt->fetchColumn();
    }

    public function insertarFilas(int $idReabastecimiento, array $filas): void
    {
        $sql = 'INSERT INTO vendingm.staging_productos_excel
        (id_reabastecimiento, codigo_barras, clave, nombre, nombre_comercial,
         sustancia_activa, ubicacion_carril, cabina, exhibicion, grupo, uso_terapeutico,
         numero_lote, fecha_caducidad, cantidad)
        VALUES (:idr, :codigo, :clave, :nombre, :comercial, :sustancia, :ubicacion, :cabina, :exhibicion, :grupo, :uso,
                :lote, :caducidad, :cantidad)';
$stmt = $this->pdo->prepare($sql);

foreach ($filas as $fila) {
    // Recortado al ancho REAL de cada columna de
    // vendingm.staging_productos_excel (leído en vivo, ver AnchoColumnas)
    // -- este es el PRIMER insert de toda la cadena de "Cargar stock", así
    // que si un texto real (ej. una DESCRIPCIÓN larga de Cuadro Básico)
    // se pasa del ancho de columna aquí, truena con SQLSTATE 22001 antes
    // de que el recorte que ya tiene CatalogoUpserter (para
    // vendingm.productos, con sus propios anchos, distintos a los de
    // esta tabla) tenga oportunidad de actuar. Bug real detectado
    // 2026-09-10.
    $stmt->execute([
        'idr'        => $idReabastecimiento,
        'codigo'     => $this->limitarColumna('codigo_barras', $fila['codigo_barras'] ?: null, 50),
        'clave'      => $this->limitarColumna('clave', $fila['clave'] ?: null, 15),
        'nombre'     => $this->limitarColumna('nombre', $fila['nombre'] ?: null, 200),
        'comercial'  => $this->limitarColumna('nombre_comercial', $fila['nombre_comercial'] ?: null, 200),
        'sustancia'  => $this->limitarColumna('sustancia_activa', $fila['sustancia_activa'] ?: null, 200),
        'ubicacion'  => $this->limitarColumna('ubicacion_carril', $fila['ubicacion_carril'] ?: null, 20),
        'cabina'     => $fila['cabina'] ?? 1,
        'exhibicion' => $this->limitarColumna('exhibicion', $fila['exhibicion'] ?: null, 20),
        'grupo'      => $this->limitarColumna('grupo', $fila['grupo'] ?: null, 20),
        'uso'        => $this->limitarColumna('uso_terapeutico', $fila['uso_terapeutico'] ?: null, 150),
        'lote'       => $this->limitarColumna('numero_lote', $fila['numero_lote'] ?: null, 50),
        'caducidad'  => $fila['fecha_caducidad'] ?: null,
        'cantidad'   => $fila['cantidad'] ?: null,
    ]);
}
    }

    /**
     * Recorta $valor al ancho real de $columna en
     * vendingm.staging_productos_excel (leído de INFORMATION_SCHEMA vía
     * AnchoColumnas); si no se pudo leer el esquema real, usa
     * $maxCaracteresDefecto. null pasa igual.
     */
    private function limitarColumna(string $columna, ?string $valor, int $maxCaracteresDefecto): ?string
    {
        if ($valor === null) {
            return null;
        }
        $anchos = AnchoColumnas::obtener($this->pdo, 'vendingm', 'staging_productos_excel');
        $max = $anchos[$columna] ?? $maxCaracteresDefecto;
        return mb_substr($valor, 0, $max, 'UTF-8');
    }
}