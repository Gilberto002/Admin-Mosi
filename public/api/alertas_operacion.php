<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$q = trim((string) ($_GET['q'] ?? ''));
$tipo = (string) ($_GET['tipo'] ?? 'todas');
$umbralStock = max(1, filter_input(INPUT_GET, 'umbral_stock', FILTER_VALIDATE_INT) ?: 5);
$capacidadCarril = max(1, filter_input(INPUT_GET, 'capacidad_carril', FILTER_VALIDATE_INT) ?: 10);
$diasCaducidad = max(1, filter_input(INPUT_GET, 'dias_caducidad', FILTER_VALIDATE_INT) ?: 30);
$page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
$pageSize = min(200, max(1, filter_input(INPUT_GET, 'page_size', FILTER_VALIDATE_INT) ?: 25));
$offset = ($page - 1) * $pageSize;

try {
    $pdo = Connection::get();

    $params = [
        'umbral_stock' => $umbralStock,
        'dias_caducidad' => $diasCaducidad,
    ];
    $porcentajeCapacidadSql = "(ca.cantidad_actual * 100.0 / {$capacidadCarril})";

    $base = "
        FROM vendingm.carriles ca
        INNER JOIN vendingm.maquinas m ON m.id_maquina = ca.id_maquina
        LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
        LEFT JOIN vendingm.productos p ON p.id_producto = l.id_producto
        WHERE ca.numero_carril % 10 <> 0
    ";
    $base .= Auth::machineScope('m', $params);

    if ($q !== '') {
        $base .= " AND (
            m.numero_serie LIKE :q1
            OR m.codigo_interno LIKE :q2
            OR m.nombre_ubicacion LIKE :q3
            OR p.nombre LIKE :q4
            OR p.codigo_barras LIKE :q5
            OR l.numero_lote LIKE :q6
        )";
        $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = $params['q5'] = $params['q6'] = "%{$q}%";
    }

    $condicionAlerta = "
        (
            ISNULL(ca.cantidad_actual, 0) = 0
            OR (ca.cantidad_actual > 0 AND {$porcentajeCapacidadSql} <= 30)
            OR (ca.cantidad_actual > 0 AND {$porcentajeCapacidadSql} <= 60)
            OR (ca.cantidad_actual > 0 AND ca.cantidad_actual <= :umbral_stock)
            OR (l.fecha_caducidad IS NOT NULL AND l.fecha_caducidad < CAST(SYSDATETIME() AS date))
            OR (l.fecha_caducidad IS NOT NULL AND DATEDIFF(day, CAST(SYSDATETIME() AS date), l.fecha_caducidad) BETWEEN 0 AND :dias_caducidad)
        )
    ";

    if ($tipo === 'vacios') {
        $base .= ' AND ISNULL(ca.cantidad_actual, 0) = 0';
    } elseif ($tipo === 'capacidad_30') {
        $base .= " AND ca.cantidad_actual > 0 AND {$porcentajeCapacidadSql} <= 30";
    } elseif ($tipo === 'capacidad_60') {
        $base .= " AND ca.cantidad_actual > 0 AND {$porcentajeCapacidadSql} > 30 AND {$porcentajeCapacidadSql} <= 60";
    } elseif ($tipo === 'stock_bajo') {
        $base .= ' AND ca.cantidad_actual > 0 AND ca.cantidad_actual <= :umbral_stock';
    } elseif ($tipo === 'caducados') {
        $base .= ' AND l.fecha_caducidad IS NOT NULL AND l.fecha_caducidad < CAST(SYSDATETIME() AS date)';
    } elseif ($tipo === 'por_caducar') {
        $base .= ' AND l.fecha_caducidad IS NOT NULL AND DATEDIFF(day, CAST(SYSDATETIME() AS date), l.fecha_caducidad) BETWEEN 0 AND :dias_caducidad';
    } else {
        $base .= " AND {$condicionAlerta}";
    }

    $stmtResumen = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN ISNULL(ca.cantidad_actual, 0) = 0 THEN 1 ELSE 0 END) AS carriles_vacios,
            SUM(CASE WHEN ca.cantidad_actual > 0 AND {$porcentajeCapacidadSql} <= 30 THEN 1 ELSE 0 END) AS capacidad_30,
            SUM(CASE WHEN ca.cantidad_actual > 0 AND {$porcentajeCapacidadSql} > 30 AND {$porcentajeCapacidadSql} <= 60 THEN 1 ELSE 0 END) AS capacidad_60,
            SUM(CASE WHEN ca.cantidad_actual > 0 AND ca.cantidad_actual <= :umbral_stock THEN 1 ELSE 0 END) AS stock_bajo,
            SUM(CASE WHEN l.fecha_caducidad IS NOT NULL AND l.fecha_caducidad < CAST(SYSDATETIME() AS date) THEN 1 ELSE 0 END) AS caducados,
            SUM(CASE WHEN l.fecha_caducidad IS NOT NULL AND DATEDIFF(day, CAST(SYSDATETIME() AS date), l.fecha_caducidad) BETWEEN 0 AND :dias_caducidad THEN 1 ELSE 0 END) AS por_caducar
         {$base}"
    );
    foreach ($params as $k => $v) {
        $stmtResumen->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmtResumen->execute();
    $resumen = $stmtResumen->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmtTotal = $pdo->prepare("SELECT COUNT(*) {$base}");
    foreach ($params as $k => $v) {
        $stmtTotal->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmtTotal->execute();
    $total = (int) $stmtTotal->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT
            m.id_maquina,
            m.numero_serie,
            m.codigo_interno,
            m.nombre_ubicacion,
            ca.id_carril,
            ca.numero_carril,
            ca.cantidad_actual,
            CAST({$porcentajeCapacidadSql} AS DECIMAL(6,2)) AS porcentaje_capacidad,
            p.nombre AS producto,
            p.codigo_barras,
            l.numero_lote,
            l.fecha_caducidad,
            DATEDIFF(day, CAST(SYSDATETIME() AS date), l.fecha_caducidad) AS dias_para_caducar,
            CASE
                WHEN ISNULL(ca.cantidad_actual, 0) = 0 THEN 'vacio'
                WHEN l.fecha_caducidad IS NOT NULL AND l.fecha_caducidad < CAST(SYSDATETIME() AS date) THEN 'caducado'
                WHEN ca.cantidad_actual > 0 AND {$porcentajeCapacidadSql} <= 30 THEN 'capacidad_30'
                WHEN ca.cantidad_actual > 0 AND {$porcentajeCapacidadSql} <= 60 THEN 'capacidad_60'
                WHEN ca.cantidad_actual > 0 AND ca.cantidad_actual <= :umbral_stock THEN 'stock_bajo'
                WHEN l.fecha_caducidad IS NOT NULL AND DATEDIFF(day, CAST(SYSDATETIME() AS date), l.fecha_caducidad) BETWEEN 0 AND :dias_caducidad THEN 'por_caducar'
                ELSE 'ok'
            END AS tipo_alerta
         {$base}
         ORDER BY
            CASE
                WHEN ISNULL(ca.cantidad_actual, 0) = 0 THEN 0
                WHEN l.fecha_caducidad IS NOT NULL AND l.fecha_caducidad < CAST(SYSDATETIME() AS date) THEN 1
                WHEN ca.cantidad_actual > 0 AND {$porcentajeCapacidadSql} <= 30 THEN 2
                WHEN ca.cantidad_actual > 0 AND {$porcentajeCapacidadSql} <= 60 THEN 3
                WHEN ca.cantidad_actual > 0 AND ca.cantidad_actual <= :umbral_stock THEN 4
                ELSE 5
            END,
            ca.cantidad_actual ASC,
            l.fecha_caducidad ASC,
            m.numero_serie ASC,
            ca.numero_carril ASC
         OFFSET :offset ROWS FETCH NEXT :page_size ROWS ONLY"
    );
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue('page_size', $pageSize, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode([
        'ok' => true,
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'resumen' => [
            'carriles_vacios' => (int) ($resumen['carriles_vacios'] ?? 0),
            'capacidad_30' => (int) ($resumen['capacidad_30'] ?? 0),
            'capacidad_60' => (int) ($resumen['capacidad_60'] ?? 0),
            'stock_bajo' => (int) ($resumen['stock_bajo'] ?? 0),
            'caducados' => (int) ($resumen['caducados'] ?? 0),
            'por_caducar' => (int) ($resumen['por_caducar'] ?? 0),
        ],
        'meta' => [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'umbral_stock' => $umbralStock,
            'capacidad_carril' => $capacidadCarril,
            'dias_caducidad' => $diasCaducidad,
            'tipo' => $tipo,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudieron calcular las alertas operativas.'], JSON_UNESCAPED_UNICODE);
}
