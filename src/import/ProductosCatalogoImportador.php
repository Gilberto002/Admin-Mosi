<?php
// src/Import/ProductosCatalogoImportador.php
// Orquesta la carga masiva de catalogo desde el boton "Cargar Excel" de
// public/productos.php: lee el Excel (ExcelProductosReader) y hace upsert
// a vendingm.productos usando "clave" (Cuadro Basico/CIS, formato
// 'NNN.NNN.NNNN.NN') como llave de coincidencia principal, con respaldo
// por codigo_barras si la fila no trae clave (mismo criterio que
// CatalogoUpserter) -- decision del usuario 2026-09-09: si ya existe un
// match, se actualiza ese producto; si no, se crea uno nuevo.
//
// Distinto de PlanimetriaImportador/CatalogoUpserter (que leen otro Excel,
// de stock/planimetria de una maquina especifica): este es solo catalogo,
// sin nada de carriles/lotes/traspasos. Comparten encabezados vía
// EncabezadoAlias a propósito: el MISMO Excel real de planimetría puede
// subirse aquí (solo toca catálogo) o por "Cargar stock" (además asigna
// carril y descuenta cantidad) -- decisión del usuario 2026-09-09.
declare(strict_types=1);

namespace App\Import;

use PDO;

final class ProductosCatalogoImportador
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{filas_leidas:int, creados:int, actualizados:int, errores:list<array{fila:int, clave:string, motivo:string}>}
     */
    public function importar(string $rutaExcel): array
    {
        $reader = new ExcelProductosReader();
        $filas = $reader->leer($rutaExcel);

        $creados = 0;
        $actualizados = 0;
        $errores = [];
        $idCategoriaDefault = null;

        foreach ($filas as $indice => $fila) {
            $numeroFila = $indice + 2; // +1 por el encabezado, +1 porque Excel es 1-index
            try {
                // "clave" ya no es la única identidad valida -- mismo
                // criterio que CatalogoUpserter::procesar() (el flujo de
                // "Cargar stock"): un archivo sin columna Clave (como el
                // layout real de planimetria, que solo trae Codigo de
                // Barras) debe poder identificar/crear el producto por
                // codigo_barras. Solo se rechaza la fila si NINGUNO de los
                // dos vino. Decision del usuario 2026-09-09 (bug real:
                // antes esto rechazaba el 100% de las filas de ese archivo
                // con "Clave vacía").
                if ($fila['clave'] === '' && $fila['codigo_barras'] === '') {
                    throw new \RuntimeException('Clave y código de barras vacíos (no se puede identificar el producto)');
                }
                if ($fila['nombre'] === '') {
                    throw new \RuntimeException('Nombre vacío');
                }
                if ($idCategoriaDefault === null) {
                    $idCategoriaDefault = $this->obtenerOCrearCategoriaSinClasificar();
                }
                $accion = $this->upsertProducto($fila, $idCategoriaDefault);
                if ($accion === 'creado') {
                    $creados++;
                } else {
                    $actualizados++;
                }
            } catch (\Throwable $e) {
                $errores[] = ['fila' => $numeroFila, 'clave' => $fila['clave'], 'motivo' => $e->getMessage()];
            }
        }

        return [
            'filas_leidas' => count($filas),
            'creados' => $creados,
            'actualizados' => $actualizados,
            'errores' => $errores,
        ];
    }

    private function upsertProducto(array $fila, int $idCategoriaDefault): string
    {
        // Recortado al ancho REAL de cada columna (leído en vivo de
        // INFORMATION_SCHEMA, ver AnchoColumnas -- no hardcodeado: ya se
        // comprobó que el esquema exportado puede estar desactualizado,
        // "clave" resultó ser VARCHAR(15) real y no NVARCHAR(20) como se
        // había asumido, causando SQLSTATE 22001 en producción
        // 2026-09-09). Los segundos argumentos son solo el valor por
        // defecto si por lo que sea no se pudo leer el esquema real.
        $clave = $this->limitarColumna('clave', $fila['clave'], 15);
        $nombre = $this->limitarColumna('nombre', $fila['nombre'], 150);
        $presentacion = $fila['presentacion'] !== '' ? $this->limitarColumna('presentacion', $fila['presentacion'], 255) : null;
        $descripcion = $fila['descripcion'] !== '' ? $this->limitarColumna('descripcion', $fila['descripcion'], 500) : null;
        $sustancia = $fila['sustancia_activa'] !== '' ? $this->limitarColumna('sustancia_activa', $fila['sustancia_activa'], 150) : null;
        // '' en vez de null para que NULLIF(:codigo, '') funcione igual que
        // con los demás campos de texto en el UPDATE de abajo.
        $codigoBarras = $this->limitarColumna('codigo_barras', $fila['codigo_barras'], 50);

        // Busca primero por clave; si la fila no trae clave (o no hay
        // ningún producto con ella todavía -- transición de catálogo viejo
        // sin clave), respaldo por codigo_barras. Mismo criterio que
        // CatalogoUpserter::upsertProducto() -- decisión del usuario
        // 2026-09-09.
        $idExistente = null;
        if ($clave !== '') {
            $stmt = $this->pdo->prepare('SELECT id_producto FROM vendingm.productos WHERE clave = :clave');
            $stmt->execute(['clave' => $clave]);
            $idExistente = $stmt->fetchColumn() ?: null;
        }
        if ($idExistente === null && $codigoBarras !== '') {
            $stmt = $this->pdo->prepare('SELECT id_producto FROM vendingm.productos WHERE codigo_barras = :codigo');
            $stmt->execute(['codigo' => $codigoBarras]);
            $idExistente = $stmt->fetchColumn() ?: null;
        }

        if ($idExistente) {
            $this->actualizarProducto((int) $idExistente, $codigoBarras, $clave, $nombre, $presentacion, $descripcion, $fila['precio'], $sustancia);
            return 'actualizado';
        }

        // Producto nuevo: si la fila trae codigo_barras real (ej. el mismo
        // Excel de planimetría, que sí lo trae) se usa ese. Si no (el
        // layout "solo catálogo" recomendado no lo incluye), se usa la
        // propia clave como placeholder temporal -- codigo_barras es NOT
        // NULL en el motor real, y queda visible/editable de inmediato en
        // el modal de "Editar producto" para reemplazarlo cuando se tenga.
        $codigoParaInsertar = $codigoBarras !== '' ? $codigoBarras : $clave;
        try {
            $ins = $this->pdo->prepare(
                'INSERT INTO vendingm.productos
                    (codigo_barras, clave, nombre, presentacion, descripcion, precio, sustancia_activa, activo, id_categoria)
                 VALUES (:codigo, :clave, :nombre, :presentacion, :descripcion, :precio, :sustancia, 1, :id_categoria)'
            );
            $ins->execute([
                'codigo' => $codigoParaInsertar,
                'clave' => $clave !== '' ? $clave : null,
                'nombre' => $nombre,
                'presentacion' => $presentacion,
                'descripcion' => $descripcion,
                'precio' => $fila['precio'],
                'sustancia' => $sustancia,
                'id_categoria' => $idCategoriaDefault,
            ]);
            return 'creado';
        } catch (\PDOException $e) {
            // Recuperación: si el INSERT chocó contra la restricción única
            // de codigo_barras es porque el producto SÍ existía y el SELECT
            // de arriba no lo encontró (por la razón que sea -- ej. tenía
            // otra clave y esta fila trae una distinta a la que se buscó).
            // En vez de perder la fila, se busca de nuevo por codigo_barras
            // y se actualiza ese producto -- así nunca se pierde una fila
            // solo porque el find-or-create se equivocó de camino. Bug real
            // detectado 2026-09-09 (SQLSTATE 23000 con archivo real de
            // stock, que repite el mismo codigo_barras en varias filas).
            if ($e->getCode() !== '23000' || $codigoParaInsertar === '') {
                throw $e;
            }
            $stmt = $this->pdo->prepare('SELECT id_producto FROM vendingm.productos WHERE codigo_barras = :codigo');
            $stmt->execute(['codigo' => $codigoParaInsertar]);
            $idRecuperado = $stmt->fetchColumn();
            if (!$idRecuperado) {
                throw $e; // no era ese el conflicto -- se relanza el error original
            }
            $this->actualizarProducto((int) $idRecuperado, $codigoBarras, $clave, $nombre, $presentacion, $descripcion, $fila['precio'], $sustancia);
            return 'actualizado';
        }
    }

    /**
     * No pisar con vacío -- el mismo Excel real de planimetría (sin
     * Presentacion/Precio/Grupo) puede reimportarse aquí solo para
     * catálogo; si esta fila no trae un dato, se conserva el que el
     * producto ya tenía en vez de vaciarlo. codigo_barras SÍ se sincroniza
     * cuando la fila lo trae (mismo criterio que CatalogoUpserter, para
     * que el cruce hacia CEDIS por codigo_barras -- sin cambio -- siga
     * funcionando si este mismo archivo también se sube por "Cargar
     * stock"). Decisión del usuario 2026-09-09.
     */
    private function actualizarProducto(int $idExistente, string $codigoBarras, string $clave, string $nombre, ?string $presentacion, ?string $descripcion, ?float $precio, ?string $sustancia): void
    {
        $upd = $this->pdo->prepare(
            "UPDATE vendingm.productos
             SET codigo_barras = COALESCE(NULLIF(:codigo, ''), codigo_barras),
                 clave = COALESCE(NULLIF(:clave, ''), clave),
                 nombre = COALESCE(NULLIF(:nombre, ''), nombre),
                 presentacion = COALESCE(:presentacion, presentacion),
                 descripcion = COALESCE(:descripcion, descripcion),
                 precio = COALESCE(:precio, precio),
                 sustancia_activa = COALESCE(:sustancia, sustancia_activa),
                 fecha_actualizacion = SYSDATETIME()
             WHERE id_producto = :id"
        );
        $upd->execute([
            'codigo' => $codigoBarras,
            'clave' => $clave,
            'nombre' => $nombre,
            'presentacion' => $presentacion,
            'descripcion' => $descripcion,
            'precio' => $precio,
            'sustancia' => $sustancia,
            'id' => $idExistente,
        ]);
    }

    /**
     * Mismo criterio de categoría por defecto que ya usa
     * PlanimetriaImportador::obtenerOCrearCategoriaSinClasificar() --
     * reutiliza la categoría "Sin clasificar" (la crea si no existe todavía).
     */
    private function obtenerOCrearCategoriaSinClasificar(): int
    {
        $nombre = 'Sin clasificar';

        $stmt = $this->pdo->prepare('SELECT id_categoria FROM vendingm.categorias_producto WHERE nombre = :nombre');
        $stmt->execute(['nombre' => $nombre]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $ins = $this->pdo->prepare(
            'INSERT INTO vendingm.categorias_producto
             (nombre, requiere_receta, es_control_especial, activo)
             OUTPUT INSERTED.id_categoria
             VALUES (:nombre, 0, 0, 1)'
        );
        $ins->execute(['nombre' => $nombre]);
        return (int) $ins->fetchColumn();
    }

    /**
     * Recorta $valor al ancho real de $columna en vendingm.productos (leído
     * de INFORMATION_SCHEMA vía AnchoColumnas); si no se pudo leer el
     * esquema real, usa $maxCaracteresDefecto.
     */
    private function limitarColumna(string $columna, string $valor, int $maxCaracteresDefecto): string
    {
        $anchos = AnchoColumnas::obtener($this->pdo, 'vendingm', 'productos');
        $max = $anchos[$columna] ?? $maxCaracteresDefecto;
        return mb_substr($valor, 0, $max, 'UTF-8');
    }
}
