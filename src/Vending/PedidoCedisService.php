<?php
// src/Vending/PedidoCedisService.php
// Lógica compartida para crear un pedido real en el sistema de CEDIS que ya
// usa el resto de medical-life (tbl_PedidosCedis1/tbl_PedidosCedis1Detalle),
// alineada línea por línea con pedido.php (el generador real -- ver notas
// dentro de crearPedido()). Extraída de public/api/pedido_cedis.php para que
// tanto ese endpoint (envío por lote desde Pedidos) como
// public/api/solicitud_pedido_manual.php (envío directo desde el botón
// "Pedido" de Disponibilidad cercana, Resurtir) usen exactamente el mismo
// INSERT -- no se quiere mantener esta lógica duplicada en dos archivos.
declare(strict_types=1);

namespace App\Vending;

use PDO;

final class PedidoCedisService
{
    // Usuario reservado "Sistema Vending" en el esquema de operadores de
    // farmacia -- mismo id que ya usaba vencimientos.php.
    public const ID_OPERADOR_VENDING = 9999;

    // Estatus inicial que usa el generador semanal real de CEDIS. Se
    // reutiliza tal cual para que el pedido aparezca en el buscador normal
    // de almacén (catPedidos.php solo lista 9,10,11,13,14).
    public const ID_STATUS_PEDIDO_NUEVO = 9;

    /**
     * Crea un pedido real (tbl_PedidosCedis1 + tbl_PedidosCedis1Detalle) para
     * una sucursal, con una o más líneas de producto+cantidad, en una sola
     * transacción (begin/commit/rollback los maneja este método -- el
     * llamador NO debe tener ya una transacción abierta en $pdo).
     *
     * NO replica el candado de "pedido abierto" que sí tiene pedido.php (el
     * generador real): ese flujo es un pedido semanal por sucursal, pero
     * aquí las solicitudes llegan sueltas (una por medicamento, en momentos
     * distintos) y bloquear el envío tumbaría el uso normal de Resurtir/
     * Pedidos. Decisión del usuario 2026-09-02.
     *
     * @param array<int, array{id_producto_almacen:int, cantidad:int}> $lineas
     *   Cada línea, indexada por su id_solicitud en vendingm.solicitudes_pedido
     *   -- ese id_estatus se actualiza a ENVIADO dentro de esta misma
     *   transacción (todo o nada: si el pedido a CEDIS no se pudo crear, la
     *   solicitud se queda tal cual estaba, nunca queda a medias). Si dos
     *   líneas comparten el mismo id_producto_almacen (mismo medicamento en
     *   más de un carril), el detalle que se manda a CEDIS las suma en una
     *   sola línea -- cada solicitud se sigue marcando ENVIADO por separado.
     * @return array{folio:string, id_pedido_cedis:int, ids:list<int>}
     * @throws \Throwable si cualquier paso falla -- la transacción ya queda
     *   revertida antes de relanzar, el llamador solo necesita reportar el
     *   error, no limpiar nada.
     */
    public static function crearPedido(PDO $pdo, int $idSucursal, array $lineas): array
    {
        if (!$lineas) {
            throw new \InvalidArgumentException('No hay líneas que enviar a CEDIS');
        }

        // Garantiza que exista id_pedido_cedis1 en vendingm.solicitudes_pedido
        // antes de intentar escribirlo mas abajo -- ver comentario junto a esa
        // columna en SolicitudPedidoGenerador::asegurarTablas().
        SolicitudPedidoGenerador::asegurarTablas($pdo);

        $pdo->beginTransaction();
        try {
            $stmtPrefix = $pdo->prepare('SELECT st_Prefix FROM cat_SucursalClinica WHERE id_SucursalClinica = :id');
            $stmtPrefix->execute(['id' => $idSucursal]);
            $prefix = $stmtPrefix->fetchColumn();
            // El folio real (prefijo + id relleno a 7 dígitos, mismo formato
            // que usa arrayPedidos.php) siempre va en mayúsculas -- pedido
            // explícito del usuario 2026-09-03, para que no dependa de cómo
            // haya quedado capturado st_Prefix en cat_SucursalClinica.
            $prefix = $prefix !== false && $prefix !== null ? strtoupper((string) $prefix) : null;

            // Mismo período que calcula pedido.php (el generador real) para
            // dt_FechaInicioPeriodo/dt_FechaFinPeriodo: primer día del mes
            // de hace 3 meses, hasta el último día del mes de hace 1 mes.
            // No lo usamos para sugerir cantidad (ya la trae la solicitud
            // de vendingm), pero se llena para que el resto de las
            // herramientas de almacén que sí lo esperan no lo vean vacío.
            $hoy = new \DateTimeImmutable('now');
            $inicioPeriodo = $hoy->modify('-3 months')->modify('first day of this month')->format('Y-m-d');
            $finPeriodo = $hoy->modify('-1 months')->modify('last day of this month')->format('Y-m-d');

            $insPedido = $pdo->prepare(
                'INSERT INTO tbl_PedidosCedis1
                    (id_Sucursal, id_Status, dt_FechaCierreFarmacia, id_Operador, st_Prefijo,
                     dt_FechaInicioPeriodo, dt_FechaFinPeriodo)
                 OUTPUT INSERTED.id_PedidoCedis1
                 VALUES (:sucursal, :status, GETDATE(), :operador, :prefijo, :inicio, :fin)'
            );
            $insPedido->execute([
                'sucursal' => $idSucursal,
                'status' => self::ID_STATUS_PEDIDO_NUEVO,
                'operador' => self::ID_OPERADOR_VENDING,
                'prefijo' => $prefix,
                'inicio' => $inicioPeriodo,
                'fin' => $finPeriodo,
            ]);
            $idPedidoCedis1 = (int) $insPedido->fetchColumn();

            // Folio: "MDM" + st_Prefix de la sucursal + id_PedidoCedis1 relleno
            // a 7 dígitos (decisión del usuario 2026-09-08). Ojo: esto ya NO
            // coincide con el formato que usa arrayPedidos.php (el buscador
            // real de almacén, que solo usa prefijo + 7 dígitos, sin "MDM") --
            // divergencia asumida a propósito para este flujo.
            $folio = 'MDM' . ($prefix ?? '') . str_pad((string) $idPedidoCedis1, 7, '0', STR_PAD_LEFT);

            // Log de auditoría de estatus, mismo patrón que pedido.php: el
            // pedido "nace" en 14 y transiciona a 9 (Nuevo) -- sin esto,
            // catPedidos.php/reportes de almacén verían el pedido sin
            // historial de creación.
            $insEvento = $pdo->prepare(
                'INSERT INTO tbl_CEDEvStatusPedido (id_PedidoCedis1, id_StatusOld, id_StatusNew, id_Operador)
                 VALUES (:pedido, :old, :new, :operador)'
            );
            $insEvento->execute(['pedido' => $idPedidoCedis1, 'old' => 0, 'new' => 14, 'operador' => self::ID_OPERADOR_VENDING]);
            $insEvento->execute(['pedido' => $idPedidoCedis1, 'old' => 14, 'new' => self::ID_STATUS_PEDIDO_NUEVO, 'operador' => self::ID_OPERADOR_VENDING]);

            // i_Maximo/i_PuntoOrden/i_Minimo/i_CantidadRequerida los cuatro
            // llevan la misma cantidad en pedido.php (tbl1.i_Cantidad) -- no
            // solo tres de los cuatro como había quedado en una primera
            // versión de esto.
            $insDetalle = $pdo->prepare(
                'INSERT INTO tbl_PedidosCedis1Detalle
                    (id_PedidoCedis1, id_Sucursal, id_ProductoAlmacen, st_Cantidad, id_Operador,
                     i_Maximo, i_PuntoOrden, i_Minimo, i_Existencias, i_Negados,
                     i_FaltantesPedidoAnterior, i_CategoriaPedido, i_CantidadRequerida,
                     i_CantidadLastMonth, i_VentaPeriodo, st_ClaveVER, i_PromedioMensual, i_StockProbable)
                 VALUES (:pedido, :sucursal, :producto, :cantidad, :operador, :cantidad2, :cantidad3, :cantidad5, 0, 0,
                     0, 1, :cantidad4, 0, 0, :claveVer, 0, 0)'
            );

            // Se actualiza vendingm.solicitudes_pedido en la MISMA
            // transacción que el pedido a CEDIS -- todo o nada, para que
            // nunca quede un pedido creado con su solicitud origen todavía
            // en PENDIENTE (o viceversa).
            $updSolicitud = $pdo->prepare(
                "UPDATE vendingm.solicitudes_pedido
                 SET id_estatus = :enviado, notas = :notas, id_pedido_cedis1 = :pedidoCedis
                 WHERE id_solicitud = :id"
            );

            // Si el mismo medicamento viene de más de un carril (más de una
            // solicitud, ej. carril 1 y carril 2 con el mismo producto),
            // CEDIS debe ver UNA sola línea con la cantidad sumada, no una
            // línea duplicada por carril -- decisión del usuario 2026-09-04.
            // Las solicitudes siguen siendo registros separados (una por
            // carril) y cada una se marca ENVIADO por su cuenta más abajo;
            // solo el detalle que se manda a CEDIS se agrupa por producto.
            $porProducto = [];
            foreach ($lineas as $datos) {
                $idProductoAlmacen = $datos['id_producto_almacen'];
                $porProducto[$idProductoAlmacen] = ($porProducto[$idProductoAlmacen] ?? 0) + $datos['cantidad'];
            }

            foreach ($porProducto as $idProductoAlmacen => $cantidadTotal) {
                $insDetalle->execute([
                    'pedido' => $idPedidoCedis1,
                    'sucursal' => $idSucursal,
                    'producto' => $idProductoAlmacen,
                    'cantidad' => $cantidadTotal,
                    'operador' => self::ID_OPERADOR_VENDING,
                    'cantidad2' => $cantidadTotal,
                    'cantidad3' => $cantidadTotal,
                    'cantidad4' => $cantidadTotal,
                    'cantidad5' => $cantidadTotal,
                    'claveVer' => 'VER',
                ]);
            }

            foreach ($lineas as $idSolicitud => $datos) {
                $updSolicitud->execute([
                    'enviado' => SolicitudPedidoGenerador::ESTATUS_ENVIADO,
                    'notas' => "Pedido CEDIS folio {$folio}",
                    'pedidoCedis' => $idPedidoCedis1,
                    'id' => $idSolicitud,
                ]);
            }

            $pdo->commit();

            return ['folio' => $folio, 'id_pedido_cedis' => $idPedidoCedis1, 'ids' => array_keys($lineas)];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
