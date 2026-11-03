<?php
// src/Import/CatalogoUpserter.php
declare(strict_types=1);

namespace App\Import;

use PDO;

final class CatalogoUpserter
{
    public function __construct(private PDO $pdo) {}

    /**
     * Procesa staging pendiente y hace upsert a productos.
     * Marca cada fila de staging como procesada.
     */
    public function procesar(int $idReabastecimiento, int $idCategoriaDefault): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM vendingm.staging_productos_excel
             WHERE id_reabastecimiento = :idr AND procesado = 0'
        );
        $stmt->execute(['idr' => $idReabastecimiento]);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Sin beginTransaction()/commit()/rollBack() propios a propósito:
        // procesar() ahora siempre corre dentro de la transacción externa
        // de PlanimetriaImportador::importar() (antes no existía tal
        // transacción externa, por eso esto se escribió con su propia
        // transacción por fila). PDO no soporta transacciones anidadas de
        // verdad -- este beginTransaction() dentro de una ya abierta
        // terminaba revirtiendo la transacción externa completa desde la
        // primera fila, dejando todo lo que seguía (asignación de
        // carriles, registro de lotes) corriendo sin ninguna transacción,
        // hasta que el commit() final de PlanimetriaImportador truena con
        // "There is no active transaction". Cada INSERT/UPDATE de
        // upsertProducto()/marcarProcesado() ya es atómico por sí solo, no
        // hace falta envolverlos aparte.
        foreach ($filas as $fila) {
            try {
                // "clave" (Cuadro Básico/CIS) ya es una identidad válida por
                // sí sola desde 2026-09-09 -- un Excel "solo stock" puede
                // confiar en que el producto ya fue cargado por clave desde
                // productos.php y no repetir codigo_barras en cada carga.
                // Solo se rechaza la fila si NINGUNO de los dos vino.
                if (empty($fila['codigo_barras']) && empty($fila['clave'])) {
                    throw new \RuntimeException('Código de barras y clave vacíos (no se puede identificar el producto)');
                }

                $idProducto = $this->upsertProducto($fila, $idCategoriaDefault);
                $this->marcarProcesado((int)$fila['id_staging'], null, $idProducto);
            } catch (\Throwable $e) {
                $this->marcarProcesado((int)$fila['id_staging'], $e->getMessage());
            }
        }
    }

    private function upsertProducto(array $fila, int $idCategoriaDefault): int
    {
        // null si la fila no trae GRUPO o no hay mapeo cargado todavía para
        // ese código -- a propósito, para poder distinguir en el UPDATE de
        // abajo "no venía categoría en esta fila" de "sí venía, y es la
        // default".
        $idCategoriaResuelta = $this->resolverCategoria($fila['grupo'] ?? '');
        // Recortado al ancho REAL de cada columna (leído en vivo de
        // INFORMATION_SCHEMA, ver AnchoColumnas -- no hardcodeado: ya se
        // comprobó que el esquema exportado puede estar desactualizado,
        // "clave" resultó ser VARCHAR(15) real y no NVARCHAR(20) como se
        // había asumido, causando SQLSTATE 22001 en producción
        // 2026-09-09). "descripcion" (el texto real de Cuadro Básico, a
        // veces largo) empezó a llenar "nombre" desde hoy (alias nuevo en
        // ExcelPlanimetriaReader) y una sola fila que se pasara del límite
        // tronaba con SQLSTATE 22001 -- y por el XACT_ABORT implícito del
        // driver (ver comentario en LoteRegistrador), esa fila envenenaba
        // la transacción completa y hacía fallar TODAS las filas
        // siguientes, no solo la culpable. Los segundos argumentos son
        // solo el valor por defecto si no se pudo leer el esquema real.
        $nombre = $this->limitarColumna('nombre', $fila['nombre_comercial'] ?: $fila['nombre'], 150);
        $presentacion = $this->limitarColumna('presentacion', $fila['nombre'], 255);
        $codigoBarrasCorto = $this->limitarColumna('codigo_barras', $fila['codigo_barras'], 50);
        $clave = ($fila['clave'] ?? '') !== '' ? $this->limitarColumna('clave', $fila['clave'], 15) : null;

        // "clave" (Cuadro Básico/CIS) es la llave de identidad del catálogo
        // de vendingm.productos -- decisión del usuario 2026-09-09: es un
        // identificador más estable del medicamento que codigo_barras
        // (que puede variar entre presentaciones/proveedores de una misma
        // compra). El Excel real trae ambos en cada fila.
        $idExistente = null;
        if ($clave !== null) {
            $stmt = $this->pdo->prepare('SELECT id_producto FROM vendingm.productos WHERE clave = :clave');
            $stmt->execute(['clave' => $clave]);
            $idExistente = $stmt->fetchColumn() ?: null;
        }

        // Respaldo por codigo_barras SOLO si no hubo match por clave --
        // cubre productos creados antes de hoy (clave todavía NULL) o filas
        // sin clave, para no duplicar lo que ya existía mientras se termina
        // de "rellenar" la clave de todo el catálogo viejo. Una vez que un
        // producto ya tiene clave, las cargas futuras lo encuentran directo
        // por el primer SELECT de arriba.
        if ($idExistente === null && $codigoBarrasCorto !== '') {
            $stmt = $this->pdo->prepare('SELECT id_producto FROM vendingm.productos WHERE codigo_barras = :codigo');
            $stmt->execute(['codigo' => $codigoBarrasCorto]);
            $idExistente = $stmt->fetchColumn() ?: null;
        }

        if ($idExistente) {
            // No pisar con vacío: varios layouts reales de planimetría (ej.
            // el que solo trae Código de Barras/Descripción/Sustancia/
            // Cabina/Ubicación/Lote/Caducidad/Stock, sin columna de Grupo)
            // no traen todos los campos de catálogo en cada carga -- antes,
            // reimportar ese archivo para solo actualizar stock borraba el
            // nombre/presentación/categoría que el producto ya tenía
            // (los dejaba vacíos o forzaba "Sin clasificar"). Ahora, si la
            // fila no trae un valor, se conserva el que ya existía.
            // codigo_barras SÍ se actualiza siempre que venga en la fila
            // (aunque el match haya sido por clave): así se mantiene en
            // sincronía con lo que trae el Excel, que es lo que después usa
            // AsignadorCarriles/LoteRegistrador para resolver hacia CEDIS
            // (ese cruce sigue siendo por codigo_barras, sin cambio).
            // Decisión del usuario 2026-09-09.
            $upd = $this->pdo->prepare(
                "UPDATE vendingm.productos
                 SET codigo_barras = COALESCE(NULLIF(:codigo, ''), codigo_barras),
                     clave = COALESCE(NULLIF(:clave, ''), clave),
                     nombre = COALESCE(NULLIF(:nombre, ''), nombre),
                     presentacion = COALESCE(NULLIF(:presentacion, ''), presentacion),
                     id_categoria = COALESCE(:categoria, id_categoria),
                     fecha_actualizacion = SYSDATETIME()
                 WHERE id_producto = :id"
            );
            $upd->execute([
                'codigo'       => $codigoBarrasCorto,
                'clave'        => $clave ?? '',
                'nombre'       => $nombre,
                'presentacion' => $presentacion,
                'categoria'    => $idCategoriaResuelta,
                'id'           => $idExistente,
            ]);
            return (int) $idExistente;
        }

        $ins = $this->pdo->prepare(
            'INSERT INTO vendingm.productos
             (codigo_barras, clave, nombre, presentacion, id_categoria)
             OUTPUT INSERTED.id_producto
             VALUES (:codigo, :clave, :nombre, :presentacion, :categoria)'
        );
        $ins->execute([
            'codigo'       => $codigoBarrasCorto,
            'clave'        => $clave,
            'nombre'       => $nombre,
            'presentacion' => $presentacion,
            'categoria'    => $idCategoriaResuelta ?? $idCategoriaDefault,
        ]);
        return (int) $ins->fetchColumn();
    }

    /**
     * Recorta $valor al ancho real de $columna en vendingm.productos (leído
     * de INFORMATION_SCHEMA vía AnchoColumnas); si no se pudo leer el
     * esquema real, usa $maxCaracteresDefecto. null pasa igual.
     */
    private function limitarColumna(string $columna, ?string $valor, int $maxCaracteresDefecto): ?string
    {
        if ($valor === null) {
            return null;
        }
        $anchos = AnchoColumnas::obtener($this->pdo, 'vendingm', 'productos');
        $max = $anchos[$columna] ?? $maxCaracteresDefecto;
        return mb_substr($valor, 0, $max, 'UTF-8');
    }

    /**
     * Resuelve id_categoria a partir del código numérico de "GRUPO" que viene
     * del Excel (columna 'grupo' ya normalizada por ExcelPlanimetriaReader),
     * usando la tabla de mapeo mapeo_grupo_categoria.grupo_excel (nvarchar,
     * guarda el código de grupo como texto, ej. "4").
     *
     * Devuelve null si el grupo viene vacío o no hay mapeo todavía cargado
     * para ese código (así procesar() cae al $idCategoriaDefault que ya
     * trae la llamada original).
     */
    private function resolverCategoria(string $grupo): ?int
    {
        $grupo = trim($grupo);
        if ($grupo === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id_categoria
             FROM vendingm.mapeo_grupo_categoria
             WHERE grupo_excel = :grupo'
        );
        $stmt->execute(['grupo' => $grupo]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    private function marcarProcesado(int $idStaging, ?string $error, ?int $idProducto = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE vendingm.staging_productos_excel
             SET procesado = 1, error_procesamiento = :error, id_producto = :id_producto
             WHERE id_staging = :id'
        );
        $stmt->execute(['error' => $error, 'id_producto' => $idProducto, 'id' => $idStaging]);
    }
}