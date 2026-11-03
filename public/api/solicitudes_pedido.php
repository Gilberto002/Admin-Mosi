<?php
// public/api/solicitudes_pedido.php
// Solicitudes de pedido generadas automáticamente por bajo stock
// (App\Vending\SolicitudPedidoGenerador, disparado desde SalidaRegistrador).
// GET ?id_maquina=  -> colaborador/admin, scoped a esa máquina (usado en maquina.php)
// GET (sin filtro)  -> admin, todas las solicitudes (usado en pedidos.php)
// POST              -> admin, actualiza id_estatus/notas de una solicitud
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;
use App\Security\Security;
use App\Vending\SolicitudPedidoGenerador;

// El driver PDO de SQL Server devuelve columnas numéricas como string (no
// int) en FETCH_ASSOC -- sin este cast, el front-end (pedidos.php) compara
// s.id_estatus === 1/2/3/4 con estricto === contra un string y SIEMPRE
// falla: los contadores marcan 0 y accionesFila() nunca renderiza ningún
// botón, aunque el badge de estatus sí se vea bien (los objetos JS
// coercionan la clave numérica a string al indexar, por eso ese caso
// puntual no delataba el bug). Se castea aquí, una sola vez, en vez de en
// cada front-end que consuma este endpoint.
function mapearSolicitud(array $fila): array
{
    foreach (['id_solicitud', 'id_maquina', 'id_carril', 'numero_carril', 'id_producto', 'cantidad_detectada', 'id_estatus', 'id_pedido_cedis1', 'resuelto_por'] as $campo) {
        if (array_key_exists($campo, $fila) && $fila[$campo] !== null) {
            $fila[$campo] = (int) $fila[$campo];
        }
    }
    return $fila;
}

$metodo = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = Connection::get();
    SolicitudPedidoGenerador::asegurarTablas($pdo);

    if ($metodo === 'GET') {
        $idMaquina = filter_input(INPUT_GET, 'id_maquina', FILTER_VALIDATE_INT);

        if ($idMaquina) {
            Auth::requireLogin();
            if (!Auth::canAccessMachine($pdo, $idMaquina)) {
                JsonResponse::error('No tienes acceso a esta maquina', 403);
            }

            $stmt = $pdo->prepare(
                "SELECT sp.id_solicitud, sp.id_maquina, sp.id_carril, sp.id_producto,
                        p.nombre AS producto, p.sustancia_activa, sp.cantidad_detectada, sp.id_estatus,
                        es.nombre AS estatus_nombre, sp.fecha_creacion, sp.fecha_resuelta, sp.notas,
                        CASE WHEN sp.id_pedido_cedis1 IS NOT NULL
                            THEN 'MDM' + UPPER(ISNULL(suc.st_Prefix, '')) + RIGHT('0000000' + CAST(sp.id_pedido_cedis1 AS VARCHAR(10)), 7)
                            ELSE NULL END AS folio_cedis
                 FROM vendingm.solicitudes_pedido sp
                 LEFT JOIN vendingm.productos p ON p.id_producto = sp.id_producto
                 LEFT JOIN vendingm.estatus_solicitud_pedido es ON es.id_estatus = sp.id_estatus
                 LEFT JOIN tbl_PedidosCedis1 pc ON pc.id_PedidoCedis1 = sp.id_pedido_cedis1
                 LEFT JOIN cat_SucursalClinica suc ON suc.id_SucursalClinica = pc.id_Sucursal
                 WHERE sp.id_maquina = :id_maquina
                 ORDER BY sp.fecha_creacion DESC"
            );
            $stmt->execute(['id_maquina' => $idMaquina]);
            JsonResponse::ok(array_map('mapearSolicitud', $stmt->fetchAll(PDO::FETCH_ASSOC)));
        }

        Auth::requireAdmin();

        $idEstatus = filter_input(INPUT_GET, 'id_estatus', FILTER_VALIDATE_INT);
        $where = '';
        $params = [];
        if ($idEstatus) {
            $where = ' WHERE sp.id_estatus = :id_estatus';
            $params['id_estatus'] = $idEstatus;
        }

        $stmt = $pdo->prepare(
            "SELECT sp.id_solicitud, sp.id_maquina, m.numero_serie, m.codigo_interno, m.nombre_ubicacion,
                    sp.id_carril, ca.numero_carril, sp.id_producto, p.nombre AS producto, p.sustancia_activa,
                    sp.cantidad_detectada, sp.id_estatus, es.nombre AS estatus_nombre,
                    sp.id_pedido_cedis1,
                    sp.fecha_creacion, sp.fecha_resuelta, sp.resuelto_por, sp.notas,
                    -- Mismo cálculo de folio que PedidoCedisService::crearPedido()
                    -- (MDM + prefijo en mayúsculas + id relleno a 7 dígitos, decisión
                    -- del usuario 2026-09-08) -- el folio nunca se guarda como columna
                    -- propia, así que se recalcula aquí para que la tabla de Pedidos
                    -- pueda mostrarlo sin depender de que el admin lo haya visto en el
                    -- momento de crearlo.
                    CASE WHEN sp.id_pedido_cedis1 IS NOT NULL
                        THEN 'MDM' + UPPER(ISNULL(suc.st_Prefix, '')) + RIGHT('0000000' + CAST(sp.id_pedido_cedis1 AS VARCHAR(10)), 7)
                        ELSE NULL END AS folio_cedis
             FROM vendingm.solicitudes_pedido sp
             INNER JOIN vendingm.maquinas m ON m.id_maquina = sp.id_maquina
             INNER JOIN vendingm.carriles ca ON ca.id_carril = sp.id_carril
             LEFT JOIN vendingm.productos p ON p.id_producto = sp.id_producto
             LEFT JOIN vendingm.estatus_solicitud_pedido es ON es.id_estatus = sp.id_estatus
             LEFT JOIN tbl_PedidosCedis1 pc ON pc.id_PedidoCedis1 = sp.id_pedido_cedis1
             LEFT JOIN cat_SucursalClinica suc ON suc.id_SucursalClinica = pc.id_Sucursal
             {$where}
             ORDER BY sp.id_estatus ASC, sp.fecha_creacion DESC"
        );
        $stmt->execute($params);
        JsonResponse::ok(array_map('mapearSolicitud', $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    if ($metodo === 'POST') {
        Auth::requireAdmin();

        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::error('JSON invalido', 400);
        }

        $idSolicitud = filter_var($body['id_solicitud'] ?? null, FILTER_VALIDATE_INT);
        $idEstatus = filter_var($body['id_estatus'] ?? null, FILTER_VALIDATE_INT);
        $notas = trim((string) ($body['notas'] ?? ''));

        $estatusValidos = [
            SolicitudPedidoGenerador::ESTATUS_PENDIENTE,
            SolicitudPedidoGenerador::ESTATUS_ENVIADO,
            SolicitudPedidoGenerador::ESTATUS_ENTREGADO,
            SolicitudPedidoGenerador::ESTATUS_CANCELADO,
        ];
        if (!$idSolicitud || !in_array($idEstatus, $estatusValidos, true)) {
            JsonResponse::error('Faltan campos obligatorios o id_estatus invalido', 400);
        }

        // Regresar a Pendiente solo aplica a solicitudes que quedaron
        // "Enviado" sin un pedido real de CEDIS detrás (ej. por el extinto
        // botón manual "Marcar enviado", que solo cambiaba el estatus local
        // sin llamar a PedidoCedisService) -- es la vía de recuperación para
        // que puedan reintentarse por el flujo real ("Enviar a CEDIS"). Si
        // ya tienen un pedido real ligado, regresarlas a Pendiente
        // desincronizaría nuestro registro del pedido que sí existe en CEDIS.
        if ($idEstatus === SolicitudPedidoGenerador::ESTATUS_PENDIENTE) {
            $stmtActual = $pdo->prepare('SELECT id_estatus, id_pedido_cedis1 FROM vendingm.solicitudes_pedido WHERE id_solicitud = :id');
            $stmtActual->execute(['id' => $idSolicitud]);
            $actual = $stmtActual->fetch(PDO::FETCH_ASSOC);
            if (!$actual) {
                JsonResponse::error('Solicitud no encontrada', 404);
            }
            if ((int) $actual['id_estatus'] !== SolicitudPedidoGenerador::ESTATUS_ENVIADO || $actual['id_pedido_cedis1'] !== null) {
                JsonResponse::error('Esta solicitud no se puede regresar a pendiente', 422);
            }
        }

        $esTerminal = in_array($idEstatus, [
            SolicitudPedidoGenerador::ESTATUS_ENTREGADO,
            SolicitudPedidoGenerador::ESTATUS_CANCELADO,
        ], true);

        $stmt = $pdo->prepare(
            "UPDATE vendingm.solicitudes_pedido
             SET id_estatus = :id_estatus,
                 notas = :notas,
                 fecha_resuelta = CASE WHEN :es_terminal = 1 THEN SYSDATETIME() ELSE fecha_resuelta END,
                 resuelto_por = CASE WHEN :es_terminal2 = 1 THEN :usuario ELSE resuelto_por END
             WHERE id_solicitud = :id"
        );
        $stmt->execute([
            'id_estatus' => $idEstatus,
            'notas' => $notas !== '' ? $notas : null,
            'es_terminal' => $esTerminal ? 1 : 0,
            'es_terminal2' => $esTerminal ? 1 : 0,
            'usuario' => Auth::id(),
            'id' => $idSolicitud,
        ]);

        if ($stmt->rowCount() === 0) {
            JsonResponse::error('Solicitud no encontrada', 404);
        }

        JsonResponse::ok(['id_solicitud' => $idSolicitud, 'id_estatus' => $idEstatus]);
    }

    JsonResponse::error('Metodo no permitido', 405);
} catch (\Throwable $e) {
    Security::logException($e, 'solicitudes_pedido');
    JsonResponse::error('No se pudo procesar la solicitud de pedido.', 500);
}
