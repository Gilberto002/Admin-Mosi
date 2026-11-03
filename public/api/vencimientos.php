<?php
// public/api/vencimientos.php
// Lotes vencidos/por vencer, 100% dentro de vendingm (lotes/carriles/maquinas)
// -- ya no depende de cat_SucursalClinica/id_SucursalOrigenVending. El stock
// de cada máquina se gestiona completo en vendingm; no hace falta reflejarlo
// en el esquema general de farmacia (ver decisión del 2026-08-24 en la
// memoria del proyecto). "Retirar" un lote vencido usa el mismo mecanismo
// transaccional que cualquier otra salida (App\Dispensacion\SalidaRegistrador,
// motivo TIPO_CADUCADO) en vez de un traspaso a una sucursal de cuarentena.
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Auth\Auth;
use App\Dispensacion\SalidaRegistrador;
use App\Http\JsonResponse;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Connection::get();
    $metodo = $_SERVER['REQUEST_METHOD'];

    if ($metodo === 'GET') {
        $dias = filter_input(INPUT_GET, 'dias', FILTER_VALIDATE_INT) ?: 30;
        $dias = max(1, min(365, $dias));

        $stmt = $pdo->prepare(
            "SELECT
                l.id_lote, l.id_producto, p.nombre AS nombre_producto, p.codigo_barras,
                l.numero_lote, l.fecha_caducidad, l.cantidad_actual,
                ca.id_carril, ca.numero_carril, m.id_maquina, m.numero_serie, m.nombre_ubicacion,
                DATEDIFF(day, CAST(SYSDATETIME() AS date), l.fecha_caducidad) AS dias_restantes
             FROM vendingm.lotes l
             INNER JOIN vendingm.carriles ca ON ca.id_carril = l.id_carril
             INNER JOIN vendingm.maquinas m ON m.id_maquina = ca.id_maquina
             LEFT JOIN vendingm.productos p ON p.id_producto = l.id_producto
             WHERE l.cantidad_actual > 0
               AND l.fecha_caducidad <= DATEADD(day, :dias, CAST(SYSDATETIME() AS date))
             ORDER BY l.fecha_caducidad ASC"
        );
        $stmt->bindValue('dias', $dias, PDO::PARAM_INT);
        $stmt->execute();

        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = $_POST;
        }

        $idLote = filter_var($body['id_lote'] ?? null, FILTER_VALIDATE_INT);
        $cantidad = filter_var($body['cantidad'] ?? null, FILTER_VALIDATE_INT);

        if (!$idLote || !$cantidad || $cantidad <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Faltan datos (id_lote, cantidad)'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmtLote = $pdo->prepare('SELECT id_carril, cantidad_actual FROM vendingm.lotes WHERE id_lote = :id');
        $stmtLote->execute(['id' => $idLote]);
        $lote = $stmtLote->fetch(PDO::FETCH_ASSOC);

        if (!$lote) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'No se encontró el lote indicado'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($cantidad > (int) $lote['cantidad_actual']) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Existencia insuficiente en el lote (disponible: ' . (int) $lote['cantidad_actual'] . ')'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $registrador = new SalidaRegistrador($pdo);
        $registrador->dispensar(
            (int) $lote['id_carril'],
            $cantidad,
            SalidaRegistrador::TIPO_CADUCADO,
            'Retiro por vencimiento (lote ' . $idLote . ')',
            Auth::user()['email'] ?? null
        );

        // Reconciliación con el ERP legado, best-effort y a propósito
        // DESPUÉS del retiro real (que ya se confirmó arriba, en su propia
        // transacción) -- confirmado con el usuario 2026-08-27: a
        // diferencia de venta/merma real (que ya NO se registra en el ERP,
        // ver SalidaRegistrador), el retiro por caducidad SÍ debe seguir
        // acreditándose a la sucursal virtual, como antes. Se hace aparte
        // (no anidado en la transacción de dispensar()) para no repetir el
        // problema de XACT_ABORT ya diagnosticado: si esto falla, el
        // retiro real en vendingm ya quedó a salvo, sin nada que revertir.
        try {
            $stmtInfo = $pdo->prepare(
                'SELECT m.id_sucursal_clinica, l.id_producto, p.codigo_barras
                 FROM vendingm.lotes l
                 INNER JOIN vendingm.carriles c ON c.id_carril = l.id_carril
                 INNER JOIN vendingm.maquinas m ON m.id_maquina = c.id_maquina
                 LEFT JOIN vendingm.productos p ON p.id_producto = l.id_producto
                 WHERE l.id_lote = :id'
            );
            $stmtInfo->execute(['id' => $idLote]);
            $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);

            $idSucursalOrigen = $info && $info['id_sucursal_clinica'] !== null ? (int) $info['id_sucursal_clinica'] : null;
            $codigoBarras = $info ? trim((string) ($info['codigo_barras'] ?? '')) : '';

            if ($idSucursalOrigen !== null && $codigoBarras !== '') {
                $idProductoAlmacen = \App\Vending\TraspasoService::resolverIdProductoAlmacenPorUpc($pdo, $codigoBarras);
                if ($idProductoAlmacen !== null) {
                    \App\Vending\TraspasoService::registrarDevolucionPorVencimiento(
                        $pdo, $idSucursalOrigen, $idProductoAlmacen, $cantidad, \App\Vending\TraspasoService::ID_OPERADOR_VENDING
                    );
                }
            }
        } catch (\Throwable $e) {
            \App\Security\Security::logException($e, 'vencimientos_reconciliar_erp');
        }

        echo json_encode(['ok' => true, 'data' => ['id_lote' => $idLote, 'cantidad' => $cantidad]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    JsonResponse::errorFromException($e);
}
