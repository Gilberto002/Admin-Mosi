<?php
// src/Import/PlanimetriaImportador.php
declare(strict_types=1);

namespace App\Import;

use App\Catalogo\ProductoCatalogo;
use App\Maquinas\CarrilesFisicos;
use App\Vending\TraspasoService;
use PDO;

/**
 * Orquesta el flujo completo de importación de planimetría/stock desde un
 * Excel para una máquina principal (y su esclava, si tiene). Es usado tanto
 * por el script de consola (scripts/importar_planimetria.php) como por el
 * endpoint web (public/api/maquina_importar_stock.php), para no duplicar
 * la lógica en dos lugares.
 */
final class PlanimetriaImportador
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{
     *   id_reabastecimiento:int,
     *   id_maquina_principal:int,
     *   id_maquina_esclava:?int,
     *   filas_leidas:int,
     *   no_asignados:array,
     *   sin_fecha:array,
     *   sin_carril:array,
     *   sin_traspaso:array,
     *   errores:array
     * }
     */
    public function importar(string $rutaExcel, string $numeroSerialPrincipal, string $usuario = 'sistema', ?int $idOperador = null): array
    {
        if (!file_exists($rutaExcel)) {
            throw new \RuntimeException("Archivo no encontrado: {$rutaExcel}");
        }

        // Asegura que "clave" (Cuadro Básico/CIS) exista en vendingm.productos
        // y en el staging antes de leer/escribir nada -- decisión del
        // usuario 2026-09-09 (clave pasa a ser la llave de identidad del
        // catálogo, ver CatalogoUpserter).
        ProductoCatalogo::asegurarColumnas($this->pdo);
        (new StagingLoader($this->pdo))->asegurarColumnas();

        // 1. Resolver máquina principal y su esclava (si existe)
        $stmt = $this->pdo->prepare(
            'SELECT id_maquina, rol_maquina, id_sucursal_clinica FROM vendingm.maquinas WHERE numero_serie = :serial'
        );
        $stmt->execute(['serial' => $numeroSerialPrincipal]);
        $maquina = $stmt->fetch();

        if (!$maquina) {
            throw new \RuntimeException("Máquina con serial '{$numeroSerialPrincipal}' no existe en vendingm.maquinas.");
        }
        if ((int) $maquina['rol_maquina'] !== 1) {
            throw new \RuntimeException('El serial dado corresponde a una máquina ESCLAVA. Usa el serial de la PRINCIPAL.');
        }
        $idMaquinaPrincipal = (int) $maquina['id_maquina'];
        $idSucursalOrigen = (int) ($maquina['id_sucursal_clinica'] ?? 0);
        if ($idSucursalOrigen <= 0) {
            throw new \RuntimeException('La máquina principal no tiene una sucursal vinculada. Edita la máquina y asigna su sucursal antes de cargar stock.');
        }
        // Cargar stock es un traspaso real DIRECTO desde la sucursal (un
        // solo salto, sin sucursal virtual intermedia -- confirmado con el
        // usuario 2026-08-27), así que no hace falta pre-validar que exista
        // una sucursal virtual configurada.
        $idOperador = $idOperador ?: TraspasoService::ID_OPERADOR_VENDING;

        $stmt = $this->pdo->prepare(
            'SELECT id_maquina FROM vendingm.maquinas WHERE id_maquina_padre = :padre AND rol_maquina = 2'
        );
        $stmt->execute(['padre' => $idMaquinaPrincipal]);
        $idMaquinaEsclava = $stmt->fetchColumn();
        $idMaquinaEsclava = $idMaquinaEsclava ? (int) $idMaquinaEsclava : null;

        // 2. Leer Excel
        $reader = new ExcelPlanimetriaReader();
        $filas = $reader->leer($rutaExcel);
        if (count($filas) === 0) {
            throw new \RuntimeException('El Excel no tiene filas válidas.');
        }

        // 3. Staging
        // 3-7. Todo lo que escribe en BD se ejecuta en una sola transacción
        // -- LoteRegistrador::registrar() (paso 6) necesita una transacción
        // ya abierta para poder usar SAVE TRANSACTION por lote (savepoint
        // para no perder el resto del lote si un solo producto falla). Sin
        // esta transacción exterior, SAVE TRANSACTION truena con "Cannot
        // issue SAVE TRANSACTION when there is no active transaction".
        $this->pdo->beginTransaction();
        try {
            $loader = new StagingLoader($this->pdo);
            $idReabastecimiento = $loader->crearReabastecimiento(basename($rutaExcel), $usuario);
            $loader->insertarFilas($idReabastecimiento, $filas);

            // 4. Upsert a catálogo de productos
            $idCategoriaDef = $this->obtenerOCrearCategoriaSinClasificar();
            $upserter = new CatalogoUpserter($this->pdo);
            $upserter->procesar($idReabastecimiento, $idCategoriaDef);

            // 5. Asignar carriles (vendingm.carriles.id_producto) desde staging.
            // Se llama una vez por cabina: cabina=1 para la principal, cabina=2
            // para la esclava (si existe). AsignadorCarriles::asignar() aplica
            // internamente el -100 al numero_carril cuando cabina=2.
            $asignador = new AsignadorCarriles($this->pdo);
            $noAsignados = $asignador->asignar($idReabastecimiento, $idMaquinaPrincipal, 1);
            if ($idMaquinaEsclava !== null) {
                $noAsignados = array_merge(
                    $noAsignados,
                    $asignador->asignar($idReabastecimiento, $idMaquinaEsclava, 2)
                );
            }

            // 6. Registrar lotes (cantidad_actual + fecha_caducidad reales).
            // Igual que en el paso 5, se procesa por separado cada cabina para
            // poder aplicar la conversión de numero_carril de la esclava y para
            // seguir reportando sin_fecha / sin_carril de cada una.
            $registrador = new LoteRegistrador($this->pdo);
            $sinFecha = [];
            $sinCarril = [];
            $sinTraspaso = [];
            $capacidadAjustada = [];
            $capacidadPendiente = [];
            $carrilLleno = [];

            foreach ([1 => $idMaquinaPrincipal, 2 => $idMaquinaEsclava] as $cabina => $idMaquina) {
                if ($idMaquina === null) {
                    continue;
                }

                [$lotesConFecha, $sinFechaC] = $this->separarLotesSinFecha(
                    $this->construirLotesDesdeStaging($idReabastecimiento, $cabina),
                    $cabina
                );
                [$lotesConCarril, $sinCarrilC] = $this->separarLotesSinCarril($lotesConFecha, $idMaquina);

                $sinTraspaso = array_merge(
                    $sinTraspaso,
                    $registrador->registrar($idMaquina, $idReabastecimiento, $lotesConCarril, $idSucursalOrigen, $idOperador)
                );
                // Ajustes de capacidad_maxima auto-calibrados en ESTA llamada
                // (ver LoteRegistrador::registrar()) -- hay que leerlos justo
                // aquí, antes de la siguiente iteración del foreach (cabina
                // esclava), porque registrar() los reinicia en cada llamada.
                $capacidadAjustada = array_merge($capacidadAjustada, $registrador->obtenerCapacidadAjustada());
                $capacidadPendiente = array_merge($capacidadPendiente, $registrador->obtenerCapacidadPendiente());
                $carrilLleno = array_merge($carrilLleno, $registrador->obtenerCarrilLleno());

                $sinFecha = array_merge($sinFecha, $sinFechaC);
                $sinCarril = array_merge($sinCarril, $sinCarrilC);
            }

            // 6.5 Actualizar fecha de último mantenimiento/carga (maestra + esclava)
            $idsAActualizar = array_filter([$idMaquinaPrincipal, $idMaquinaEsclava]);
            $stmtFecha = $this->pdo->prepare(
                'UPDATE vendingm.maquinas SET fecha_ultimo_mantenimiento = SYSDATETIME() WHERE id_maquina = :id'
            );
            foreach ($idsAActualizar as $id) {
                $stmtFecha->execute(['id' => $id]);
            }

            // 7. Errores reportados en staging
            $stmt = $this->pdo->prepare(
                'SELECT id_staging, codigo_barras, error_procesamiento
                 FROM vendingm.staging_productos_excel
                 WHERE id_reabastecimiento = :idr AND error_procesamiento IS NOT NULL'
            );
            $stmt->execute(['idr' => $idReabastecimiento]);
            $errores = $stmt->fetchAll();

            $this->pdo->commit();
        } catch (\Throwable $e) {
            // El driver SQL Server de esta conexión corre con XACT_ABORT ON
            // de forma implícita: un error SQL genuino ya tumbó la
            // transacción del lado del motor antes de llegar aquí, aunque
            // $this->pdo->inTransaction() (que solo refleja el estado del
            // lado de PHP) todavía diga true. Intentar rollBack() en ese
            // caso lanza una SEGUNDA excepción ("There is no active
            // transaction") que tapa la real -- se descarta esa segunda
            // excepción a propósito y se relanza siempre la original.
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (\Throwable) {
                    // Ignorado: la transacción ya no existía del lado del
                    // motor. $e (la excepción original) es la que importa.
                }
            }
            throw $e;
        }

        return [
            'id_reabastecimiento'  => $idReabastecimiento,
            'id_maquina_principal' => $idMaquinaPrincipal,
            'id_maquina_esclava'   => $idMaquinaEsclava,
            'filas_leidas'         => count($filas),
            'no_asignados'         => $noAsignados,
            'sin_fecha'            => $sinFecha,
            'sin_carril'           => $sinCarril,
            'sin_traspaso'         => $sinTraspaso,
            'capacidad_ajustada'   => $capacidadAjustada,
            'capacidad_pendiente'  => $capacidadPendiente,
            'carril_lleno'         => $carrilLleno,
            'errores'              => $errores,
        ];
    }

    /**
     * Arma el arreglo de lotes esperado por LoteRegistrador::registrar()
     * a partir de staging ya procesado sin error. Usa directamente
     * s.id_producto (lo que CatalogoUpserter::procesar() ya resolvió por
     * clave o por codigo_barras y guardó en staging) -- ya no vuelve a
     * cruzar por codigo_barras contra vendingm.productos, porque eso
     * fallaba para filas sin codigo_barras (Excel "solo stock" que confía
     * en la clave). Decisión del usuario 2026-09-09. Filtra por cabina
     * (1=principal, 2=esclava).
     *
     * @return array<int, array{codigo_barras:string, id_producto:int, numero_carril_raw:string, numero_lote:string, fecha_caducidad:?string, cantidad:int}>
     */
    private function construirLotesDesdeStaging(int $idReabastecimiento, int $cabina): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.codigo_barras, s.ubicacion_carril, s.numero_lote, s.fecha_caducidad, s.cantidad, s.id_producto
             FROM vendingm.staging_productos_excel s
             WHERE s.id_reabastecimiento = :idr
               AND s.procesado = 1
               AND s.error_procesamiento IS NULL
               AND s.id_producto IS NOT NULL
               AND s.cabina = :cabina'
        );
        $stmt->execute(['idr' => $idReabastecimiento, 'cabina' => $cabina]);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $f) => [
            'codigo_barras'     => $f['codigo_barras'],
            'id_producto'       => (int) $f['id_producto'],
            'numero_carril_raw' => (string) $f['ubicacion_carril'],
            'numero_lote'       => $f['numero_lote'],
            'fecha_caducidad'   => $f['fecha_caducidad'] ?: null,
            'cantidad'          => (int) $f['cantidad'],
        ], $filas);
    }

    /**
     * Separa las filas sin fecha_caducidad válida (LoteRegistrador no las
     * puede insertar) del resto, ya con numero_carril convertido a int.
     * Si cabina=2, aplica el -100 correspondiente a los carriles de la
     * esclava (101-158 -> 1-58).
     *
     * @return array{0: array, 1: array} [lotesConFecha, sinFecha]
     */
    private function separarLotesSinFecha(array $filas, int $cabina): array
    {
        $lotes = [];
        $sinFecha = [];

        foreach ($filas as $f) {
            if (empty($f['fecha_caducidad'])) {
                $sinFecha[] = ['codigo_barras' => $f['codigo_barras']];
                continue;
            }

            // No usar filter_var(FILTER_VALIDATE_INT) aquí: rechaza strings
            // con cero a la izquierda (ej. "001") por ambigüedad con
            // notación octal -- exactamente el formato real de los
            // carriles en el Excel, rechazaba el 100% de las filas. Mismo
            // criterio que ExcelPlanimetriaReader::convertirCarril()/
            // AsignadorCarriles::asignar(). Bug real detectado 2026-09-10.
            $textoCarril = trim((string) $f['numero_carril_raw']);
            $numeroCarril = ($textoCarril !== '' && ctype_digit($textoCarril)) ? (int) $textoCarril : false;
            if ($numeroCarril === false || $numeroCarril <= 0) {
                // Sin carril numérico interpretable: se reporta junto con
                // "sin_carril" más adelante (mismo criterio que AsignadorCarriles).
                $lotes[] = $f + ['numero_carril' => null];
                continue;
            }

            if ($cabina === 2 && $numeroCarril > 58) {
                $numeroCarril -= 100;
            }

            if (!CarrilesFisicos::esNumeroValido($numeroCarril, PHP_INT_MAX)) {
                $lotes[] = $f + ['numero_carril' => null];
                continue;
            }

            $lotes[] = $f + ['numero_carril' => $numeroCarril];
        }

        return [$lotes, $sinFecha];
    }

    /**
     * Verifica contra vendingm.carriles cuáles numero_carril existen
     * realmente para $idMaquina, ya que LoteRegistrador::registrar()
     * descarta en silencio (continue) los que no encuentra.
     *
     * @return array{0: array, 1: array} [lotesListosParaRegistrar, sinCarril]
     */
    private function separarLotesSinCarril(array $lotes, int $idMaquina): array
    {
        $numeros = array_unique(array_filter(array_column($lotes, 'numero_carril'), fn ($n) => $n !== null));

        $existentes = [];
        if ($numeros) {
            $placeholders = implode(',', array_fill(0, count($numeros), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT numero_carril FROM vendingm.carriles WHERE id_maquina = ? AND numero_carril IN ({$placeholders})"
            );
            $stmt->execute(array_merge([$idMaquina], array_values($numeros)));
            $existentes = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }

        $listos = [];
        $sinCarril = [];

        foreach ($lotes as $l) {
            if ($l['numero_carril'] !== null && isset($existentes[$l['numero_carril']])) {
                $listos[] = [
                    'numero_carril'   => $l['numero_carril'],
                    'id_producto'     => $l['id_producto'],
                    'numero_lote'     => $l['numero_lote'],
                    'fecha_caducidad' => $l['fecha_caducidad'],
                    'cantidad'        => $l['cantidad'],
                ];
            } else {
                $sinCarril[] = [
                    'id_producto'   => $l['id_producto'],
                    'numero_carril' => $l['numero_carril'] ?? $l['numero_carril_raw'],
                ];
            }
        }

        return [$listos, $sinCarril];
    }

    /**
     * Busca la categoría "Sin clasificar" por nombre; si no existe, la crea.
     */
    private function obtenerOCrearCategoriaSinClasificar(): int
    {
        $nombre = 'Sin clasificar';

        $stmt = $this->pdo->prepare(
            'SELECT id_categoria FROM vendingm.categorias_producto WHERE nombre = :nombre'
        );
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
}
