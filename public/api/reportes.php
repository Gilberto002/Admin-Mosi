<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Dispensacion\SalidaRegistrador;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$tipo = (string) ($_GET['tipo'] ?? 'maquinas_estado');
$q = trim((string) ($_GET['q'] ?? ''));
$desde = trim((string) ($_GET['desde'] ?? ''));
$hasta = trim((string) ($_GET['hasta'] ?? ''));
$dias = filter_input(INPUT_GET, 'dias', FILTER_VALIDATE_INT);
$limit = min(5000, max(1, filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 1000));

function fechaValida(string $fecha): bool
{
    return $fecha === '' || (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha);
}

function responder(array $columns, array $data, array $meta = []): void
{
    echo json_encode([
        'ok' => true,
        'columns' => $columns,
        'data' => $data,
        'meta' => $meta,
    ], JSON_UNESCAPED_UNICODE);
}

if (!fechaValida($desde) || !fechaValida($hasta)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Formato de fecha invalido. Usa YYYY-MM-DD.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Connection::get();
    SalidaRegistrador::asegurarTablas($pdo);
    $params = [];

    if ($tipo === 'resumen_red') {
        $where = 'WHERE 1 = 1' . Auth::machineScope('vw', $params);
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_maquinas,
                SUM(CASE WHEN lotes_ya_caducados > 0 THEN 1 ELSE 0 END) AS maquinas_con_caducados,
                SUM(CASE WHEN carriles_vacios > 0 THEN 1 ELSE 0 END) AS maquinas_con_vacios,
                SUM(lotes_ya_caducados) AS lotes_caducados_total,
                SUM(lotes_por_caducar_30d) AS lotes_por_caducar_30d_total,
                SUM(carriles_vacios) AS carriles_vacios_total,
                SUM(total_carriles) AS carriles_totales
             FROM vendingm.vw_resumen_maquina vw
             {$where}"
        );
        $stmt->execute($params);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $labels = [
            'total_maquinas' => 'Total maquinas',
            'maquinas_con_caducados' => 'Maquinas con caducados',
            'maquinas_con_vacios' => 'Maquinas con carriles vacios',
            'lotes_caducados_total' => 'Lotes caducados',
            'lotes_por_caducar_30d_total' => 'Lotes por caducar 30 dias',
            'carriles_vacios_total' => 'Carriles vacios',
            'carriles_totales' => 'Carriles totales',
        ];
        $data = [];
        foreach ($labels as $key => $label) {
            $data[] = [$label, $fila[$key] ?? 0];
        }
        responder(
            ['Indicador', 'Valor'],
            $data
        );
        exit;
    }

    if ($tipo === 'maquinas_estado') {
        $cond = [];
        if ($q !== '') {
            $cond[] = 'vw.numero_serie LIKE :q';
            $params['q'] = "%{$q}%";
        }
        $authCond = Auth::machineCondition('vw', $params);
        if ($authCond) {
            $cond[] = $authCond;
        }
        $where = $cond ? ('WHERE ' . implode(' AND ', $cond)) : '';
        $stmt = $pdo->prepare(
            "SELECT TOP (:limit)
                vw.numero_serie,
                CASE WHEN vw.rol_maquina = 2 THEN 'Esclava' ELSE 'Maestra' END AS rol,
                vw.total_carriles,
                vw.carriles_vacios,
                vw.lotes_ya_caducados,
                vw.lotes_por_caducar_30d,
                vw.productos_control_especial_distintos,
                CASE
                    WHEN vw.lotes_ya_caducados > 0 THEN 'Atencion: caducados'
                    WHEN vw.carriles_vacios > 0 THEN 'Atencion: vacios'
                    ELSE 'OK'
                END AS estado
             FROM vendingm.vw_resumen_maquina vw
             {$where}
             ORDER BY vw.lotes_ya_caducados DESC, vw.carriles_vacios DESC, vw.numero_serie ASC"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        responder(
            ['Serie', 'Rol', 'Carriles', 'Carriles vacios', 'Lotes caducados', 'Por caducar 30d', 'Control especial', 'Estado'],
            $stmt->fetchAll(PDO::FETCH_NUM),
            ['tipo' => $tipo, 'limit' => $limit]
        );
        exit;
    }

    if ($tipo === 'stock_actual' || $tipo === 'caducidades') {
        $cond = ['ca.numero_carril % 10 <> 0'];
        if ($tipo === 'caducidades') {
            $cond[] = 'l.cantidad_actual > 0';
            if ($dias !== null && $dias !== false) {
                $cond[] = 'DATEDIFF(day, CAST(SYSDATETIME() AS date), l.fecha_caducidad) <= :dias';
                $params['dias'] = $dias;
            }
        }
        if ($q !== '') {
            $cond[] = '(m.numero_serie LIKE :q1 OR p.nombre LIKE :q2 OR p.codigo_barras LIKE :q3 OR l.numero_lote LIKE :q4)';
            $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = "%{$q}%";
        }
        $authCond = Auth::machineCondition('m', $params);
        if ($authCond) {
            $cond[] = $authCond;
        }
        $where = $cond ? ('WHERE ' . implode(' AND ', $cond)) : '';

        $stmt = $pdo->prepare(
            "SELECT TOP (:limit)
                m.numero_serie,
                ca.numero_carril,
                p.nombre AS producto,
                p.codigo_barras,
                c.nombre_categoria AS categoria,
                l.numero_lote,
                l.fecha_caducidad,
                DATEDIFF(day, CAST(SYSDATETIME() AS date), l.fecha_caducidad) AS dias_para_caducar,
                ca.cantidad_actual AS stock_carril,
                CASE
                    WHEN ca.cantidad_actual IS NULL OR ca.cantidad_actual <= 0 THEN 'Vacio'
                    WHEN l.fecha_caducidad < CAST(SYSDATETIME() AS date) THEN 'Caducado'
                    WHEN DATEDIFF(day, CAST(SYSDATETIME() AS date), l.fecha_caducidad) <= 30 THEN 'Por caducar'
                    ELSE 'OK'
                END AS estado
             FROM vendingm.carriles ca
             INNER JOIN vendingm.maquinas m ON m.id_maquina = ca.id_maquina
             LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
             LEFT JOIN vendingm.productos p ON p.id_producto = l.id_producto
             LEFT JOIN vendingm.categorias c ON c.id_categoria = p.id_categoria
             {$where}
             ORDER BY
                CASE WHEN l.fecha_caducidad IS NULL THEN 1 ELSE 0 END,
                l.fecha_caducidad ASC,
                m.numero_serie ASC,
                ca.numero_carril ASC"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        responder(
            ['Serie', 'Carril', 'Producto', 'Codigo', 'Categoria', 'Lote', 'Caducidad', 'Dias para caducar', 'Stock', 'Estado'],
            $stmt->fetchAll(PDO::FETCH_NUM),
            ['tipo' => $tipo, 'limit' => $limit, 'dias' => $dias]
        );
        exit;
    }

    if ($tipo === 'medicamentos_movimiento') {
        $where = 'WHERE 1 = 1' . Auth::machineScope('mv', $params);
        if ($desde !== '') {
            $where .= ' AND mv.fecha_movimiento >= :desde';
            $params['desde'] = $desde;
        }
        if ($hasta !== '') {
            $where .= ' AND mv.fecha_movimiento < DATEADD(day, 1, :hasta)';
            $params['hasta'] = $hasta;
        }
        if ($q !== '') {
            $where .= ' AND (p.nombre LIKE :q1 OR p.codigo_barras LIKE :q2)';
            $params['q1'] = $params['q2'] = "%{$q}%";
        }

        $stmt = $pdo->prepare(
            "SELECT TOP (:limit)
                p.nombre AS producto,
                p.codigo_barras,
                c.nombre_categoria AS categoria,
                SUM(CASE WHEN mv.fecha_movimiento >= DATEADD(day, -7, SYSDATETIME()) THEN mv.cantidad ELSE 0 END) AS unidades_7d,
                SUM(CASE WHEN mv.fecha_movimiento >= DATEADD(day, -30, SYSDATETIME()) THEN mv.cantidad ELSE 0 END) AS unidades_30d,
                SUM(mv.cantidad) AS unidades_periodo,
                COUNT(DISTINCT mv.id_maquina) AS maquinas_distintas
             FROM vendingm.movimientos_salida mv
             INNER JOIN vendingm.productos p ON p.id_producto = mv.id_producto
             LEFT JOIN vendingm.categorias c ON c.id_categoria = p.id_categoria
             {$where}
             GROUP BY p.nombre, p.codigo_barras, c.nombre_categoria
             ORDER BY unidades_periodo DESC"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        responder(
            ['Producto', 'Codigo', 'Categoria', 'Unidades 7d', 'Unidades 30d', 'Unidades periodo', 'Maquinas distintas'],
            $stmt->fetchAll(PDO::FETCH_NUM),
            ['tipo' => $tipo, 'limit' => $limit, 'desde' => $desde, 'hasta' => $hasta]
        );
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Tipo de reporte no soportado.'], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo generar el reporte.'], JSON_UNESCAPED_UNICODE);
}
