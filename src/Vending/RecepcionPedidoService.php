<?php
// src/Vending/RecepcionPedidoService.php
// Replica, con sentencias preparadas, la logica real de recepcion de un
// pedido/traspaso de CEDIS que ya usa el resto de medical-life -- ver
// system/Cedis/Pedidos/recepcion pedido.php (la referencia exacta que
// proporciono el usuario 2026-09-03, usada aqui solo como referencia: no se
// modifico ese archivo, esta es una implementacion nueva para adminVending).
//
// Diferencia clave con PedidoCedisService::crearPedido(): ESTE metodo NO
// abre su propia transaccion. El llamador (resurtir_vendingm.php) necesita
// que la recepcion y el reabastecimiento fisico del carril queden en una
// sola transaccion "todo o nada" -- si el ajuste de stock fallara, la
// recepcion del pedido tampoco debe quedar registrada, y viceversa. Por eso
// aqui se asume que ya hay una transaccion abierta en $pdo.
//
// REDISEÑO 2026-09-04: originalmente esto solo aceptaba pedidos en estatus
// 22 ("en transito", con el envio formal ya generado por almacen). El
// usuario confirmo que EN LA PRACTICA el almacen ya no avanza los pedidos
// mas alla de 10 ("Surtiendo") -- ese es hoy el aviso final real, sin
// ningun otro registro digital de que el medicamento salio. Exigir 22
// bloqueaba TODA la transaccion (incluida la carga fisica del carril en
// resurtir_vendingm.php) para pedidos que nunca van a llegar ahi. Por eso
// ahora hay dos caminos segun el estatus real:
//   - 22 (en transito): flujo completo, replica recepcion pedido.php contra
//     el traspaso real (tbl_ReporteTraspasoCedisSucursal).
//   - 10/15 (Surtiendo / Surtido finalizado): recepcion directa a 23
//     (Recibido) -- el boton "Recibir pedido CEDIS" confirma una recepcion
//     real (el colaborador ya tiene el medicamento en la mano), no un mero
//     cierre administrativo, asi que el estatus final tiene que decir
//     "Recibido", no "Cerrado" (decision del usuario 2026-09-07: 11
//     ("Cerrado", via ajaxCerrarSurtido.php) hubiera sido semanticamente
//     incorrecto para esto). No hay traspaso real que marcar (almacen nunca
//     genero el envio), asi que no se toca tbl_ReporteTraspasoCedisSucursal
//     -- el pedido queda en 23 sin la fila de traspaso que normalmente lo
//     acompaña.
//
// ACTUALIZACION 2026-09-07 (misma sesion, YA REEMPLAZADA -- ver rediseño
// 2026-09-08 abajo): el camino directo llego a acreditar la sucursal REAL
// via TraspasoService::acreditarRecepcionPedidoCedis() (lote sintetico
// "PEDCEDIS-X"). Se dejo de usar al reemplazar el camino directo por el
// traspaso real generado por el propio sistema -- el metodo sigue en
// TraspasoService, sin llamador, documentado igual que otros mecanismos
// desconectados de esta clase (ver comentario ahi).
//
// REDISEÑO 2026-09-08: el usuario confirmo, tras descubrir el 2026-09-07 que
// un pedido real SI puede avanzar mas alla de Surtiendo (cadena real
// 9->10->16->17->18->19->20->..., ver hallazgo documentado en memoria),
// que este servicio debe generar el traspaso real el mismo en vez de asumir
// que nunca existira uno -- "aplica para todos los casos [10 al 22]... la
// responsabilidad es enteramente de administradores el confirmar por los
// medios que ellos consideren necesarios adicionales al sistema (no es
// perfecto)". Con esto YA NO hay un camino "directo a 23" -- CUALQUIER
// estatus recibible (10, 15, 16..21, 22) pasa primero por
// TraspasoService::generarTraspasoPedidoCedis() (genera el traspaso +
// detalle + clones de cat_TipoProducto inactivos si no existe ya uno para
// este pedido; si almacen ya genero uno a mano en el tramo 16-21, lo
// reutiliza en vez de duplicar), deja el pedido en 22 si no lo estaba ya, y
// termina llamando a recibirConTraspaso() (sin cambios) para completar
// 22->23. recibirDirecto() se elimino -- ya no aplica ningun camino que NO
// pase por un traspaso real.
//
// 9 ("Nuevo") y 11 ("Cerrado"/cancelado por almacen) siguen bloqueando --
// esas son las 2 unicas señales reales de que NO hay nada que recibir
// todavia o nunca lo habra; no son "estatus no reconocido", son reglas de
// negocio explicitas confirmadas antes del rediseño de hoy.
declare(strict_types=1);

namespace App\Vending;

use PDO;

final class RecepcionPedidoService
{
    // Estatus reales de tbl_PedidosCedis1 (cat_StatusPedido), confirmados
    // contra recepcion pedido.php / ajaxCambiaStatusPedido.php /
    // ajaxCerrarSurtido.php (system/Cedis/Pedidos) y contra la BD real
    // (2026-09-04, folio VNDG0064189 confirmado en estatus 10 "Surtiendo").
    private const ID_STATUS_NUEVO = 9;
    private const ID_STATUS_SURTIENDO = 10;
    private const ID_STATUS_CERRADO = 11;
    private const ID_STATUS_SURTIDO_FINALIZADO = 15;
    private const ID_STATUS_EN_TRANSITO = 22;
    private const ID_STATUS_RECIBIDO = 23;

    /**
     * Confirma la recepcion real de un pedido de CEDIS. Bloquea solo si el
     * pedido sigue en Nuevo (9, almacen todavia no lo toca) o si almacen ya
     * lo cerro/cancelo (11) -- cualquier otro estatus (10, 15, 16..21, 22)
     * se considera recibible: si ya existe un traspaso real
     * (tbl_ReporteTraspasoCedisSucursal) para este pedido lo reutiliza, si
     * no lo genera el mismo (ver TraspasoService::generarTraspasoPedidoCedis()),
     * y siempre termina el flujo real 22->23 (recibirConTraspaso()) --
     * decision del usuario 2026-09-08, ver comentario de cabecera de este
     * archivo para el porque completo.
     *
     * @return array{pedido_cerrado:bool, id_status_pedido:int, traspasos_recibidos:list<int>}
     * @throws \RuntimeException si el pedido no existe, sigue en Nuevo, o
     *   fue cerrado/cancelado por almacen.
     */
    public static function recibirPedido(
        PDO $pdo,
        int $idPedidoCedis1,
        int $idOperador,
        ?string $observaciones = null
    ): array {
        $stmtPedido = $pdo->prepare(
            'SELECT TOP 1 id_Status, id_Sucursal FROM tbl_PedidosCedis1 WHERE id_PedidoCedis1 = :id'
        );
        $stmtPedido->execute(['id' => $idPedidoCedis1]);
        $pedido = $stmtPedido->fetch(PDO::FETCH_ASSOC);

        if (!$pedido) {
            throw new \RuntimeException("No se encontró el pedido CEDIS {$idPedidoCedis1}.");
        }
        $status = (int) $pedido['id_Status'];
        $idSucursal = (int) $pedido['id_Sucursal'];

        if ($status === self::ID_STATUS_NUEVO) {
            throw new \RuntimeException(
                'El pedido en CEDIS todavía no está listo para recepción (el almacén aún no genera '
                . 'la hoja de surtido). Vuelve a intentarlo más tarde.'
            );
        }

        // El almacén cerró/canceló este pedido por su cuenta (mismo botón
        // "Cerrar Surtido" que puede usarse para cancelar) -- nunca es un
        // éxito silencioso: si esto pasó, el medicamento nunca se recibió
        // realmente y no hay nada que la vending pueda cargar.
        if ($status === self::ID_STATUS_CERRADO) {
            throw new \RuntimeException(
                'Este pedido fue cerrado/cancelado por el almacén (estatus "Cerrado") y no se puede recibir.'
            );
        }

        // Ya se recibió en un intento anterior (ej. reintento tras fallar
        // el paso siguiente) -- idempotente, no repite nada.
        if ($status === self::ID_STATUS_RECIBIDO) {
            return ['pedido_cerrado' => true, 'id_status_pedido' => $status, 'traspasos_recibidos' => []];
        }

        // Cualquier otro estatus recibible (10, 15, 16..21, 22): genera (o
        // reutiliza) el traspaso real y completa la recepcion -- ver
        // comentario de cabecera de este archivo, rediseño 2026-09-08.
        return self::recibirGenerandoTraspaso($pdo, $idPedidoCedis1, $status, $idSucursal, $idOperador, $observaciones);
    }

    /**
     * Genera (o reutiliza, si almacen ya generó uno a mano en el tramo
     * 16-21) el traspaso real del pedido -- ver
     * TraspasoService::generarTraspasoPedidoCedis() -- deja el pedido en 22
     * si no lo estaba ya (con su auditoría en tbl_CEDEvStatusPedido), y
     * termina llamando a recibirConTraspaso() para completar el flujo real
     * 22->23. Reemplaza el antiguo camino "directo a 23" -- decisión del
     * usuario 2026-09-08.
     */
    private static function recibirGenerandoTraspaso(
        PDO $pdo,
        int $idPedidoCedis1,
        int $statusActual,
        int $idSucursal,
        int $idOperador,
        ?string $observaciones
    ): array {
        TraspasoService::generarTraspasoPedidoCedis($pdo, $idPedidoCedis1, $idSucursal, $idOperador);

        if ($statusActual !== self::ID_STATUS_EN_TRANSITO) {
            $pdo->prepare(
                'INSERT INTO tbl_CEDEvStatusPedido (id_PedidoCedis1, id_StatusOld, id_StatusNew, id_Operador)
                 VALUES (:pedido, :old, :new, :operador)'
            )->execute([
                'pedido' => $idPedidoCedis1,
                'old' => $statusActual,
                'new' => self::ID_STATUS_EN_TRANSITO,
                'operador' => $idOperador,
            ]);
            $pdo->prepare(
                'UPDATE tbl_PedidosCedis1 SET id_Status = :new WHERE id_PedidoCedis1 = :id'
            )->execute(['new' => self::ID_STATUS_EN_TRANSITO, 'id' => $idPedidoCedis1]);
        }

        return self::recibirConTraspaso($pdo, $idPedidoCedis1, $idSucursal, $idOperador, $observaciones);
    }

    /**
     * Flujo completo para un pedido que SÍ llegó a "en tránsito" (22, con el
     * envío formal ya generado por almacén) -- replica recepcion pedido.php
     * contra el traspaso real. Marca como recibidos TODOS los
     * traspasos/líneas pendientes del pedido (no hay UI de vending para
     * confirmar producto por producto como sí tiene recepcion pedido.php --
     * el colaborador confirma la carga completa de la máquina, así que se
     * recibe el traspaso completo).
     *
     * @throws \RuntimeException si no hay ningún traspaso pendiente de
     *   recibir (inconsistencia que no debería darse, pero se valida en vez
     *   de asumir).
     */
    private static function recibirConTraspaso(
        PDO $pdo,
        int $idPedidoCedis1,
        int $idSucursal,
        int $idOperador,
        ?string $observaciones
    ): array {
        $stmtTraspasos = $pdo->prepare(
            'SELECT id_ReporteTraspasoCedisSucursal
             FROM tbl_ReporteTraspasoCedisSucursal
             WHERE id_PedidoCedis1 = :id AND i_Recibido = 0'
        );
        $stmtTraspasos->execute(['id' => $idPedidoCedis1]);
        $idsTraspaso = array_map('intval', $stmtTraspasos->fetchAll(PDO::FETCH_COLUMN));

        if (!$idsTraspaso) {
            throw new \RuntimeException(
                "El pedido {$idPedidoCedis1} está en tránsito pero no tiene ningún traspaso pendiente de recibir."
            );
        }

        $updTraspaso = $pdo->prepare(
            'UPDATE tbl_ReporteTraspasoCedisSucursal
             SET i_Recibido = 1, st_ObservacionesRecibido = :obs, dt_FechaRecibido = GETDATE(), id_OperadorRecepcion = :op
             WHERE id_ReporteTraspasoCedisSucursal = :idTraspaso AND id_PedidoCedis1 = :idPedido'
        );
        $updDetalle = $pdo->prepare(
            'UPDATE tbl_ReporteTraspasoCedisSucursalDetail SET i_Recibido = 1
             WHERE id_ReporteTraspasoCedisSucursal = :idTraspaso'
        );
        $updCatalogoTraspaso = $pdo->prepare(
            'UPDATE cat_TipoProducto SET id_Status = 1, dt_FechaRegistro = GETDATE()
             WHERE id_ReporteTraspasoCedisSucursal = :idTraspaso'
        );
        $selUpcsTraspaso = $pdo->prepare(
            'SELECT id_UPC FROM cat_TipoProducto WHERE id_ReporteTraspasoCedisSucursal = :idTraspaso'
        );
        $contarUpcActivo = $pdo->prepare(
            'SELECT COUNT(*) FROM cat_TipoProducto WHERE id_Sucursal = :sucursal AND id_UPC = :upc AND id_Status = 1'
        );
        $activarUpcTraspaso = $pdo->prepare(
            'UPDATE cat_TipoProducto SET id_Status = 1
             WHERE id_Sucursal = :sucursal AND id_UPC = :upc AND id_ReporteTraspasoCedisSucursal = :idTraspaso'
        );
        $updTmp = $pdo->prepare(
            'UPDATE tbl_PedidosCedis1Tmp SET id_Status = 0 WHERE id_Traspaso = :idTraspaso'
        );
        $updIngreso = $pdo->prepare(
            'UPDATE tbl_ProductoIngreso SET dt_FechaRegistro = GETDATE()
             WHERE id_ReporteTraspasoCedisSucursal = :idTraspaso'
        );

        foreach ($idsTraspaso as $idTraspaso) {
            $updTraspaso->execute([
                'obs' => $observaciones ?? '',
                'op' => $idOperador,
                'idTraspaso' => $idTraspaso,
                'idPedido' => $idPedidoCedis1,
            ]);
            $updDetalle->execute(['idTraspaso' => $idTraspaso]);
            $updCatalogoTraspaso->execute(['idTraspaso' => $idTraspaso]);

            // Mismo doble chequeo que recepcion pedido.php: si el UPC no
            // esta ya activo en OTRO registro de esta sucursal, activa
            // puntualmente el de este traspaso. cat_TipoProducto ya quedo
            // en id_Status=1 para este traspaso en el UPDATE de arriba, asi
            // que en la practica es redundante -- se replica igual porque
            // se pidio seguir la referencia tal cual, no solo su intencion.
            $selUpcsTraspaso->execute(['idTraspaso' => $idTraspaso]);
            foreach ($selUpcsTraspaso->fetchAll(PDO::FETCH_ASSOC) as $filaUpc) {
                $upc = $filaUpc['id_UPC'];
                $contarUpcActivo->execute(['sucursal' => $idSucursal, 'upc' => $upc]);
                if ((int) $contarUpcActivo->fetchColumn() === 0) {
                    $activarUpcTraspaso->execute(['sucursal' => $idSucursal, 'upc' => $upc, 'idTraspaso' => $idTraspaso]);
                }
            }

            $updTmp->execute(['idTraspaso' => $idTraspaso]);
            $updIngreso->execute(['idTraspaso' => $idTraspaso]);
        }

        // Unifica precios del medicamento en la sucursal receptora -- mismo
        // stored procedure que corre recepcion pedido.php, aplicado igual
        // aqui porque la sucursal destino sigue siendo una sucursal real de
        // farmacia (la vinculada a la maquina), no algo exclusivo de vending.
        $pdo->prepare('EXEC sp_FARMUnificarPreciosMedicamentoSucursal :sucursal')
            ->execute(['sucursal' => $idSucursal]);

        // Solo cierra el pedido completo (22 -> 23) si YA NO queda ningun
        // traspaso pendiente -- un pedido puede repartirse en traspasos a
        // mas de una sucursal, y hasta que todos esten recibidos no se
        // considera recibido en su totalidad. Igual que recepcion pedido.php.
        $stmtPendientes = $pdo->prepare(
            "SELECT COUNT(*) AS conteoNoRecibidos
             FROM (
                 SELECT id_ReporteTraspasoCedisSucursal
                 FROM tbl_ReporteTraspasoCedisSucursal
                 WHERE id_PedidoCedis1 = :id1 AND i_Recibido = 0
                 UNION ALL
                 SELECT id_ReporteTraspasoSucursalSucursal
                 FROM tbl_ReporteTraspasoSucursalSucursal
                 WHERE id_PedidoCedis1 = :id2 AND i_Recibido = 0
             ) tb1"
        );
        $stmtPendientes->execute(['id1' => $idPedidoCedis1, 'id2' => $idPedidoCedis1]);
        $pendientes = (int) $stmtPendientes->fetchColumn();

        $pedidoCerrado = $pendientes === 0;
        if ($pedidoCerrado) {
            $pdo->prepare(
                'INSERT INTO tbl_CEDEvStatusPedido (id_PedidoCedis1, id_StatusOld, id_StatusNew, id_Operador)
                 VALUES (:pedido, :old, :new, :operador)'
            )->execute([
                'pedido' => $idPedidoCedis1,
                'old' => self::ID_STATUS_EN_TRANSITO,
                'new' => self::ID_STATUS_RECIBIDO,
                'operador' => $idOperador,
            ]);
            $pdo->prepare(
                'UPDATE tbl_PedidosCedis1 SET id_Status = :new WHERE id_PedidoCedis1 = :id'
            )->execute(['new' => self::ID_STATUS_RECIBIDO, 'id' => $idPedidoCedis1]);
        }

        return [
            'pedido_cerrado' => $pedidoCerrado,
            'id_status_pedido' => $pedidoCerrado ? self::ID_STATUS_RECIBIDO : self::ID_STATUS_EN_TRANSITO,
            'traspasos_recibidos' => $idsTraspaso,
        ];
    }
}
