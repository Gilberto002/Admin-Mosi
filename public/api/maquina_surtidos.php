<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Api\AndroidApi;
use App\Auth\Auth;
use App\Database\Connection;
use App\Dispensacion\SalidaRegistrador;
use App\Vending\RecetaFolio;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$idMaquina = filter_input(INPUT_GET, 'id_maquina', FILTER_VALIDATE_INT);
if (!$idMaquina) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta id_maquina']);
    exit;
}

try {
    $pdo = Connection::get();
    if (!Auth::canAccessMachine($pdo, $idMaquina)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No tienes acceso a esta maquina']);
        exit;
    }

    AndroidApi::asegurarTablas($pdo);
    SalidaRegistrador::asegurarTablas($pdo);
    RecetaFolio::asegurarColumnas($pdo);
    $pdo->exec("
        IF COL_LENGTH('vendingm.detalle_receta', 'id_maquina') IS NULL
            ALTER TABLE vendingm.detalle_receta ADD id_maquina INT NULL
    ");

    $stmtResumen = $pdo->prepare(
        "SELECT COUNT(*) AS total_recetas,
                SUM(CASE WHEN x.id_estatus = 1 THEN 1 ELSE 0 END) AS pendientes,
                SUM(CASE WHEN x.id_estatus = 3 THEN 1 ELSE 0 END) AS en_proceso,
                SUM(CASE WHEN x.id_estatus = 4 THEN 1 ELSE 0 END) AS parciales,
                SUM(CASE WHEN x.id_estatus = 5 THEN 1 ELSE 0 END) AS fallidas,
                SUM(x.cantidad_solicitada) AS piezas_solicitadas,
                SUM(x.cantidad_entregada) AS piezas_entregadas
         FROM (
             SELECT r.id_receta, r.id_estatus,
                    SUM(ISNULL(dr.cantidad_solicitada, 0)) AS cantidad_solicitada,
                    SUM(ISNULL(dr.cantidad_entregada, 0)) AS cantidad_entregada
             FROM vendingm.recetas r
             INNER JOIN vendingm.detalle_receta dr ON dr.id_receta = r.id_receta
             WHERE COALESCE(dr.id_maquina, r.id_maquina) = :id
             GROUP BY r.id_receta, r.id_estatus
         ) x"
    );
    $stmtResumen->execute(['id' => $idMaquina]);
    $resumen = $stmtResumen->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmtRecetas = $pdo->prepare(
        "SELECT TOP 30 r.id_receta,
                COALESCE(r.codigo_receta, CONVERT(varchar(36), r.folio)) AS folio,
                r.id_estatus, COALESCE(er.nombre, 'Sin estatus') AS estatus_nombre,
                r.nombre_paciente, r.origen, r.fecha_creacion, r.fecha_entregada,
                SUM(ISNULL(dr.cantidad_solicitada, 0)) AS cantidad_solicitada,
                SUM(ISNULL(dr.cantidad_entregada, 0)) AS cantidad_entregada
         FROM vendingm.recetas r
         INNER JOIN vendingm.detalle_receta dr ON dr.id_receta = r.id_receta
         LEFT JOIN vendingm.estatus_receta er ON er.id_estatus = r.id_estatus
         WHERE COALESCE(dr.id_maquina, r.id_maquina) = :id
         GROUP BY r.id_receta, r.codigo_receta, r.folio, r.id_estatus, er.nombre,
                  r.nombre_paciente, r.origen, r.fecha_creacion, r.fecha_entregada
         ORDER BY r.fecha_creacion DESC, r.id_receta DESC"
    );
    $stmtRecetas->execute(['id' => $idMaquina]);
    $recetas = [];
    foreach ($stmtRecetas->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $fila['id_receta'] = (int) $fila['id_receta'];
        $fila['id_estatus'] = (int) $fila['id_estatus'];
        $fila['cantidad_solicitada'] = (int) $fila['cantidad_solicitada'];
        $fila['cantidad_entregada'] = (int) $fila['cantidad_entregada'];
        $fila['cantidad_pendiente'] = max(0, $fila['cantidad_solicitada'] - $fila['cantidad_entregada']);
        $fila['porcentaje'] = $fila['cantidad_solicitada'] > 0
            ? min(100, round(($fila['cantidad_entregada'] / $fila['cantidad_solicitada']) * 100, 1))
            : 0;
        $fila['items'] = [];
        $recetas[$fila['id_receta']] = $fila;
    }

    if ($recetas) {
        $placeholders = implode(',', array_fill(0, count($recetas), '?'));
        $sqlItems =
            "SELECT dr.id_receta, dr.numero_carril, dr.id_producto, p.nombre AS producto_nombre,
                    p.codigo_barras, ISNULL(dr.cantidad_solicitada, 0) AS cantidad_solicitada,
                    ISNULL(dr.cantidad_entregada, 0) AS cantidad_entregada
             FROM vendingm.detalle_receta dr
             INNER JOIN vendingm.recetas r ON r.id_receta = dr.id_receta
             LEFT JOIN vendingm.productos p ON p.id_producto = dr.id_producto
             WHERE COALESCE(dr.id_maquina, r.id_maquina) = ?
               AND dr.id_receta IN ({$placeholders})
             ORDER BY dr.id_receta DESC, dr.numero_carril";
        $stmtItems = $pdo->prepare($sqlItems);
        $stmtItems->execute(array_merge([$idMaquina], array_keys($recetas)));
        foreach ($stmtItems->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $idReceta = (int) $item['id_receta'];
            unset($item['id_receta']);
            $item['numero_carril'] = (int) $item['numero_carril'];
            $item['id_producto'] = (int) $item['id_producto'];
            $item['cantidad_solicitada'] = (int) $item['cantidad_solicitada'];
            $item['cantidad_entregada'] = (int) $item['cantidad_entregada'];
            $item['cantidad_pendiente'] = max(0, $item['cantidad_solicitada'] - $item['cantidad_entregada']);
            $recetas[$idReceta]['items'][] = $item;
        }
    }

    $stmtMovimientos = $pdo->prepare(
        "SELECT TOP 50 ms.id_movimiento, ms.fecha_movimiento, ms.cantidad,
                ca.numero_carril, p.nombre AS producto_nombre, p.codigo_barras,
                l.numero_lote, tm.nombre AS tipo_movimiento, ms.usuario, ms.observaciones
         FROM vendingm.movimientos_salida ms
         INNER JOIN vendingm.carriles ca ON ca.id_carril = ms.id_carril
         LEFT JOIN vendingm.productos p ON p.id_producto = ms.id_producto
         LEFT JOIN vendingm.lotes l ON l.id_lote = ms.id_lote
         LEFT JOIN vendingm.tipos_movimiento tm ON tm.id_tipo_movimiento = ms.id_tipo_movimiento
         WHERE ms.id_maquina = :id
         ORDER BY ms.fecha_movimiento DESC, ms.id_movimiento DESC"
    );
    $stmtMovimientos->execute(['id' => $idMaquina]);
    $movimientos = array_map(static function (array $fila): array {
        $fila['id_movimiento'] = (int) $fila['id_movimiento'];
        $fila['numero_carril'] = (int) $fila['numero_carril'];
        $fila['cantidad'] = (int) $fila['cantidad'];
        return $fila;
    }, $stmtMovimientos->fetchAll(PDO::FETCH_ASSOC));

    $stmtErrores = $pdo->prepare(
        "SELECT TOP 30 id_error, folio, numero_carril, codigo_barras, codigo_error,
                mensaje, request_id, fecha_creacion
         FROM vendingm.api_errores_surtido
         WHERE id_maquina = :id
         ORDER BY fecha_creacion DESC, id_error DESC"
    );
    $stmtErrores->execute(['id' => $idMaquina]);
    $errores = array_map(static function (array $fila): array {
        $fila['id_error'] = (int) $fila['id_error'];
        $fila['numero_carril'] = $fila['numero_carril'] !== null ? (int) $fila['numero_carril'] : null;
        return $fila;
    }, $stmtErrores->fetchAll(PDO::FETCH_ASSOC));

    foreach ($resumen as $clave => $valor) {
        $resumen[$clave] = (int) ($valor ?? 0);
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'resumen' => $resumen,
            'recetas' => array_values($recetas),
            'movimientos' => $movimientos,
            'errores' => $errores,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')]);
}
