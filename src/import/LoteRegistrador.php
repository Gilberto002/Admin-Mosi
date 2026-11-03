<?php
// src/Import/LoteRegistrador.php
declare(strict_types=1);

namespace App\Import;

use App\Vending\TraspasoService;
use PDO;

final class LoteRegistrador
{
    /**
     * Capacidades establecidas AUTOMÁTICAMENTE en la ÚLTIMA llamada a
     * registrar() -- solo ocurre para carriles nunca calibrados
     * (capacidad_maxima = 0), ver bloque de capacidad dentro del método. Se
     * reinicia al inicio de cada registrar() -- PlanimetriaImportador llama
     * registrar() una vez por cabina y debe leer/mezclar esto justo después
     * de cada llamada, igual que ya hace con el valor de retorno
     * ($sinTraspaso).
     *
     * @var list<array{numero_carril:int|string, capacidad_anterior:int, capacidad_nueva:int}>
     */
    private array $capacidadAjustada = [];

    /**
     * Ampliaciones de capacidad_maxima PENDIENTES en la ÚLTIMA llamada a
     * registrar() -- carril ya calibrado que recibió una carga que lo
     * excede; la carga se limitó al espacio disponible (cantidad_pendiente
     * es lo que NO se cargó) y la ampliación queda pendiente de que un
     * administrador la autorice desde la pantalla de "Cargar stock" (ver
     * public/api/autorizar_capacidad_carril.php, que reusa este mismo
     * registrar() para completar la carga UNA VEZ que ya subió
     * capacidad_maxima). No se persiste en ninguna columna nueva -- solo
     * vive en este arreglo, de aquí pasa al JSON de respuesta, y de ahí
     * el propio formulario de autorización manda de vuelta los datos que
     * necesita (numero_lote/fecha_caducidad incluidos, para no perder la
     * trazabilidad del lote real). Mismo patrón de reinicio por llamada
     * que $capacidadAjustada.
     *
     * @var list<array{id_carril:int, numero_carril:int|string, id_producto:int, numero_lote:string, fecha_caducidad:?string, cantidad_pendiente:int, capacidad_actual:int, capacidad_propuesta:int}>
     */
    private array $capacidadPendiente = [];

    /**
     * Carriles que ya estaban EXACTAMENTE en su capacidad_maxima (cero
     * espacio disponible) cuando llegó una carga nueva -- estado esperado/
     * correcto, no una discrepancia que necesite decidirse: no se pide
     * autorización ni se ofrece Autorizar/Descartar, solo se informa
     * (ver renderResultadoStock en admin.php, recuadro verde). Distinto de
     * $capacidadPendiente, donde SÍ cabía algo (recorte parcial) y por eso
     * sí hay una discrepancia real que ameriya decidir. Decisión del
     * usuario 2026-09-10. Mismo patrón de reinicio por llamada.
     *
     * @var list<array{numero_carril:int|string, id_producto:int, capacidad_maxima:int, cantidad_no_cargada:int}>
     */
    private array $carrilLleno = [];

    public function __construct(private PDO $pdo) {}

    /**
     * @return list<array{numero_carril:int|string, capacidad_anterior:int, capacidad_nueva:int}>
     */
    public function obtenerCapacidadAjustada(): array
    {
        return $this->capacidadAjustada;
    }

    /**
     * @return list<array{id_carril:int, numero_carril:int|string, id_producto:int, numero_lote:string, fecha_caducidad:?string, cantidad_pendiente:int, capacidad_actual:int, capacidad_propuesta:int}>
     */
    public function obtenerCapacidadPendiente(): array
    {
        return $this->capacidadPendiente;
    }

    /**
     * @return list<array{numero_carril:int|string, id_producto:int, capacidad_maxima:int, cantidad_no_cargada:int}>
     */
    public function obtenerCarrilLleno(): array
    {
        return $this->carrilLleno;
    }

    /**
     * Registra cada lote en vendingm.lotes/carriles como un traspaso real
     * desde $idSucursalOrigen (App\Vending\TraspasoService), no como un
     * reabastecimiento "de la nada" -- cierra la brecha de trazabilidad que
     * antes solo cubría resurtir_carril.php (Intecco): cargar stock a una
     * máquina vendingm, ya sea por Excel o por el ajuste manual de
     * Resurtir, descuenta el inventario real de una sucursal.
     *
     * El llamador es responsable de validar por adelantado (antes de leer
     * el Excel completo) que $idSucursalOrigen exista con
     * TraspasoService::verificarSucursalConfigurada() -- aquí solo se
     * valida por fila, para no tumbar el resto del lote si un producto en
     * particular no tiene mapeo a UPC o no hay existencia suficiente.
     *
     * @param array $lotes cada item: ['numero_carril'=>int,'id_producto'=>int,'numero_lote'=>string,'fecha_caducidad'=>string Y-m-d,'cantidad'=>int]
     * @return list<array{numero_carril:int|string, id_producto:int, motivo:string}> filas que NO se pudieron traspasar (y por lo tanto no se cargaron)
     */
    public function registrar(int $idMaquina, int $idReabastecimiento, array $lotes, int $idSucursalOrigen, int $idOperador): array
    {
        $this->capacidadAjustada = [];
        $this->capacidadPendiente = [];
        $this->carrilLleno = [];
        $sinTraspaso = [];

        foreach ($lotes as $l) {
            $stmt = $this->pdo->prepare(
                'SELECT id_carril FROM vendingm.carriles WHERE id_maquina = :m AND numero_carril = :c'
            );
            $stmt->execute(['m' => $idMaquina, 'c' => $l['numero_carril']]);
            $idCarril = $stmt->fetchColumn();
            if (!$idCarril) {
                continue;
            }
            $idCarril = (int) $idCarril;

            $stmtProd = $this->pdo->prepare('SELECT codigo_barras FROM vendingm.productos WHERE id_producto = :id');
            $stmtProd->execute(['id' => $l['id_producto']]);
            $codigoBarras = (string) $stmtProd->fetchColumn();

            $idProductoAlmacen = $codigoBarras !== ''
                ? TraspasoService::resolverIdProductoAlmacenPorUpc($this->pdo, $codigoBarras)
                : null;

            if ($idProductoAlmacen === null) {
                $sinTraspaso[] = [
                    'numero_carril' => $l['numero_carril'],
                    'id_producto' => $l['id_producto'],
                    'motivo' => 'El producto no tiene un código de barras mapeado al catálogo de almacén (UPC)',
                ];
                continue;
            }

            // Cantidad inválida (vacía, 0 o negativa) se descarta ANTES de
            // llamar a cargarStockFisico() -- ese método lanza
            // InvalidArgumentException si cantidad <= 0, y como el resto de
            // este bucle se deja propagar sin capturar (ver comentario de
            // abajo, por el XACT_ABORT del driver), una sola fila con
            // cantidad mala tumbaba TODO el archivo completo en vez de
            // reportarse como error de esa fila nada más. Esta validación es
            // puro PHP, antes de tocar la base de datos, así que es segura
            // de "atajar" sin arriesgar enmascarar un error real de SQL.
            $cantidadSolicitada = (int) $l['cantidad'];
            if ($cantidadSolicitada <= 0) {
                $sinTraspaso[] = [
                    'numero_carril' => $l['numero_carril'],
                    'id_producto' => $l['id_producto'],
                    'motivo' => 'Cantidad inválida en el Excel (vacía, cero o negativa) -- fila omitida',
                ];
                continue;
            }

            // Capacidad física del carril (vendingm.carriles.capacidad_maxima)
            // -- la carga NUNCA se bloquea, pero ampliar capacidad_maxima sí
            // requiere autorización explícita de un administrador (decisión
            // del usuario 2026-09-10, revisando el primer intento -- que
            // subía capacidad_maxima automáticamente -- para que la edición
            // "se mantenga en espera"). Dos casos, misma relectura FRESCA
            // aquí (no desde el SELECT de arriba, porque una fila ANTERIOR
            // de este mismo Excel puede haber cargado ya a este mismo
            // carril -- caso real visto en producción 2026-09-10):
            //   1. Carril nunca calibrado (capacidad_maxima = 0, el default
            //      de la columna): no hay nada previo que "sobrescribir",
            //      así que la primera carga real SÍ establece la capacidad
            //      máxima directamente, sin autorización (ver
            //      obtenerCapacidadAjustada()).
            //   2. Carril ya calibrado, cero espacio disponible (ya está
            //      EXACTAMENTE en su capacidad_maxima): esto es un estado
            //      esperado/correcto, no una discrepancia -- se informa
            //      nada más (ver obtenerCarrilLleno()), sin pedir
            //      autorización.
            //   3. Carril ya calibrado, cabe ALGO pero no todo: aquí sí hay
            //      una discrepancia real entre lo declarado y lo que llegó
            //      -- la carga se LIMITA al espacio que sí cabe hoy y lo
            //      que no cupo queda pendiente (ver obtenerCapacidadPendiente())
            //      -- capacidad_maxima solo cambia, y lo pendiente solo se
            //      carga, cuando un administrador lo autoriza desde la
            //      pantalla de "Cargar stock"
            //      (public/api/autorizar_capacidad_carril.php).
            $stmtCarril = $this->pdo->prepare(
                'SELECT capacidad_maxima, cantidad_actual FROM vendingm.carriles WHERE id_carril = :id'
            );
            $stmtCarril->execute(['id' => $idCarril]);
            $estadoCarril = $stmtCarril->fetch(PDO::FETCH_ASSOC);
            $capacidadMaxima = (int) ($estadoCarril['capacidad_maxima'] ?? 0);
            $cantidadActualCarril = (int) ($estadoCarril['cantidad_actual'] ?? 0);

            if ($capacidadMaxima === 0) {
                $cantidadResultante = $cantidadActualCarril + $cantidadSolicitada;
                $updCap = $this->pdo->prepare(
                    'UPDATE vendingm.carriles SET capacidad_maxima = :cap WHERE id_carril = :id'
                );
                $updCap->execute(['cap' => $cantidadResultante, 'id' => $idCarril]);
                $this->capacidadAjustada[] = [
                    'numero_carril' => $l['numero_carril'],
                    'capacidad_anterior' => 0,
                    'capacidad_nueva' => $cantidadResultante,
                ];
            } else {
                $espacioDisponible = $capacidadMaxima - $cantidadActualCarril;

                if ($espacioDisponible <= 0) {
                    $this->carrilLleno[] = [
                        'numero_carril' => $l['numero_carril'],
                        'id_producto' => $l['id_producto'],
                        'capacidad_maxima' => $capacidadMaxima,
                        'cantidad_no_cargada' => $cantidadSolicitada,
                    ];
                    continue;
                }

                if ($cantidadSolicitada > $espacioDisponible) {
                    $cantidadOriginal = $cantidadSolicitada;
                    $cantidadSolicitada = $espacioDisponible;
                    $cantidadPendiente = $cantidadOriginal - $cantidadSolicitada;

                    $this->capacidadPendiente[] = [
                        'id_carril' => $idCarril,
                        'numero_carril' => $l['numero_carril'],
                        'id_producto' => $l['id_producto'],
                        'numero_lote' => $l['numero_lote'],
                        'fecha_caducidad' => $l['fecha_caducidad'],
                        'cantidad_pendiente' => $cantidadPendiente,
                        'capacidad_actual' => $capacidadMaxima,
                        'capacidad_propuesta' => $capacidadMaxima + $cantidadPendiente,
                    ];
                    $sinTraspaso[] = [
                        'numero_carril' => $l['numero_carril'],
                        'id_producto' => $l['id_producto'],
                        'motivo' => "Cantidad limitada de {$cantidadOriginal} a {$cantidadSolicitada} (capacidad máxima actual: {$capacidadMaxima}) -- {$cantidadPendiente} unidad(es) pendientes de autorización",
                    ];
                }
            }

            // Cargar stock a vendingm es un traspaso real completo en una
            // sola llamada (ver comentario de clase de TraspasoService):
            // descuenta FIFO directo de la sucursal real Y acredita a su
            // sucursal virtual, sin depender de que esta última ya tenga
            // existencia previa.
            //
            // Sin SAVE TRANSACTION/ROLLBACK TRANSACTION a propósito (se
            // usó antes, pero no sirve con este driver): el driver SQL
            // Server que usa esta conexión corre con XACT_ABORT ON de
            // forma implícita, así que un error SQL genuino dentro de
            // cargarStockFisico() tumba TODA la transacción externa, no
            // solo un savepoint -- intentar "aislar" el fallo con un
            // ROLLBACK TRANSACTION ahí solo producía un segundo error
            // ("There is no active transaction") que tapaba el real. Por
            // eso "existencia insuficiente" NO lanza (ver
            // TraspasoService::cargarStockFisico(), devuelve
            // completo:false) -- solo una excepción genuina llega aquí, y
            // se deja propagar sin capturarla: aborta toda la carga con
            // el error real y claro, en vez de fingir que un lote se
            // puede recuperar cuando el motor de base de datos ya decidió
            // que no.
            $resultado = TraspasoService::cargarStockFisico(
                $this->pdo, $idProductoAlmacen, $cantidadSolicitada, $idSucursalOrigen, $idOperador,
                "Carga Excel: maquina {$idMaquina}, carril {$l['numero_carril']}, reabastecimiento {$idReabastecimiento}"
            );

            $cantidadACargar = $resultado['cantidad_movida'];
            if ($cantidadACargar <= 0) {
                $sinTraspaso[] = [
                    'numero_carril' => $l['numero_carril'],
                    'id_producto' => $l['id_producto'],
                    'motivo' => 'Sin existencia en la sucursal para traspasar este producto',
                ];
                continue;
            }

            // Solo se carga a vendingm exactamente lo que sí se traspasó de
            // verdad (puede ser menos que lo solicitado si el traspaso
            // quedó incompleto) -- así el lote nunca refleja más stock del
            // que realmente salió de la sucursal.
            //
            // numero_lote se recorta al ancho REAL de la columna (ver
            // AnchoColumnas) -- este INSERT corre DESPUÉS de que
            // cargarStockFisico() ya ejecutó el traspaso real (descontó la
            // sucursal); si truena aquí sin protección, el XACT_ABORT
            // implícito del driver revierte TODA la transacción externa de
            // PlanimetriaImportador -- incluyendo traspasos de filas
            // anteriores que sí habían salido bien. Mismo bug (SQLSTATE
            // 22001) ya visto en CatalogoUpserter/StagingLoader, aquí
            // faltaba cubrirlo. Bug real detectado 2026-09-10.
            $numeroLote = $this->limitarColumna('numero_lote', $l['numero_lote'], 50);
            $ins = $this->pdo->prepare(
                'INSERT INTO vendingm.lotes
                (id_carril, id_producto, numero_lote, fecha_caducidad, cantidad_inicial, cantidad_actual, id_reabastecimiento)
                OUTPUT INSERTED.id_lote
                VALUES (:carril, :producto, :lote, :caducidad, :cantidadInicial, :cantidadActual, :idr)'
            );
            $ins->execute([
                'carril'          => $idCarril,
                'producto'        => $l['id_producto'],
                'lote'            => $numeroLote,
                'caducidad'       => $l['fecha_caducidad'],
                'cantidadInicial' => $cantidadACargar,
                'cantidadActual'  => $cantidadACargar,
                'idr'             => $idReabastecimiento,
            ]);
            $idLote = (int) $ins->fetchColumn();

            $upd = $this->pdo->prepare(
                'UPDATE vendingm.carriles
                 SET cantidad_actual = cantidad_actual + :cant,
                     id_lote_actual = :idLote,
                     fecha_actualizacion = SYSDATETIME()
                 WHERE id_carril = :id'
            );
            $upd->execute(['cant' => $cantidadACargar, 'idLote' => $idLote, 'id' => $idCarril]);

            if (!$resultado['completo']) {
                $sinTraspaso[] = [
                    'numero_carril' => $l['numero_carril'],
                    'id_producto' => $l['id_producto'],
                    'motivo' => "Traspaso parcial: se cargaron {$cantidadACargar} de {$l['cantidad']} solicitadas (sin existencia suficiente en la sucursal)",
                ];
            }
        }

        return $sinTraspaso;
    }

    /**
     * Recorta $valor al ancho real de $columna en vendingm.lotes (leído de
     * INFORMATION_SCHEMA vía AnchoColumnas); si no se pudo leer el esquema
     * real, usa $maxCaracteresDefecto.
     */
    private function limitarColumna(string $columna, string $valor, int $maxCaracteresDefecto): string
    {
        $anchos = AnchoColumnas::obtener($this->pdo, 'vendingm', 'lotes');
        $max = $anchos[$columna] ?? $maxCaracteresDefecto;
        return mb_substr($valor, 0, $max, 'UTF-8');
    }
}
