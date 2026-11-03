<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Vending\SolicitudPedidoGenerador;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$q = trim((string) ($_GET['q'] ?? ''));

try {
    $pdo = Connection::get();

    // Concentrado por medicamento (clave de salud), sumando TODAS las maquinas
    // que el operador puede ver -- a diferencia de stock_maquinas.php (que
    // agrupa por maquina+producto), aqui se agrupa solo por producto, sin
    // desglosar por maquina.
    $params = [];
    $condiciones = ['ca.numero_carril % 10 <> 0', 'p.id_producto IS NOT NULL'];

    $authCond = Auth::machineCondition('m', $params);
    if ($authCond) {
        $condiciones[] = $authCond;
    }
    if ($q !== '') {
        $condiciones[] = '(p.nombre LIKE :q1 OR p.clave LIKE :q2 OR p.codigo_barras LIKE :q3)';
        $params['q1'] = $params['q2'] = $params['q3'] = "%{$q}%";
    }

    $where = 'WHERE ' . implode(' AND ', $condiciones);

    $stmt = $pdo->prepare(
        "SELECT
            p.id_producto,
            p.clave,
            p.nombre AS producto,
            p.codigo_barras,
            p.presentacion,
            SUM(ISNULL(ca.cantidad_actual, 0)) AS restante_total,
            SUM(ISNULL(ca.capacidad_maxima, 0)) AS capacidad_maxima_total,
            COUNT(DISTINCT m.id_maquina) AS maquinas_con_producto,
            MIN(CASE WHEN ca.capacidad_maxima > 0
                     THEN CAST(ISNULL(ca.cantidad_actual, 0) AS FLOAT) / ca.capacidad_maxima * 100
                     ELSE NULL END) AS pct_minimo
         FROM vendingm.carriles ca
         INNER JOIN vendingm.maquinas m ON m.id_maquina = ca.id_maquina
         LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
         LEFT JOIN vendingm.productos p ON p.id_producto = COALESCE(ca.id_producto, l.id_producto)
         {$where}
         GROUP BY p.id_producto, p.clave, p.nombre, p.codigo_barras, p.presentacion"
    );
    $stmt->execute($params);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Total dispensado (ventas, tipo 1) por producto, mismo alcance de
    // maquinas que el operador puede ver.
    $paramsVenta = [];
    $condVenta = ['ms.id_tipo_movimiento = 1'];
    $authCondVenta = Auth::machineCondition('ms', $paramsVenta);
    if ($authCondVenta) {
        $condVenta[] = $authCondVenta;
    }
    $stmtVenta = $pdo->prepare(
        'SELECT ms.id_producto, SUM(ms.cantidad) AS dispensado
         FROM vendingm.movimientos_salida ms
         WHERE ' . implode(' AND ', $condVenta) . '
         GROUP BY ms.id_producto'
    );
    $stmtVenta->execute($paramsVenta);
    $dispensadoPorProducto = [];
    foreach ($stmtVenta->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $dispensadoPorProducto[(int) $v['id_producto']] = (int) $v['dispensado'];
    }

    $data = array_map(static function (array $f) use ($dispensadoPorProducto): array {
        $idProducto = (int) $f['id_producto'];
        // Estado por el % MAS BAJO entre las maquinas que manejan este
        // medicamento, no por el agregado total -- una sola maquina critica
        // no debe diluirse entre otras con stock sano. Ver
        // App\Vending\SolicitudPedidoGenerador::colorPorPct().
        $pctMinimo = $f['pct_minimo'] !== null ? (float) $f['pct_minimo'] : null;
        return [
            'id_producto' => $idProducto,
            'clave' => $f['clave'],
            'producto' => $f['producto'],
            'codigo_barras' => $f['codigo_barras'],
            'presentacion' => $f['presentacion'],
            'restante_total' => (int) $f['restante_total'],
            'capacidad_maxima_total' => (int) $f['capacidad_maxima_total'],
            'dispensado_total' => $dispensadoPorProducto[$idProducto] ?? 0,
            'maquinas_con_producto' => (int) $f['maquinas_con_producto'],
            'estado_capacidad' => $pctMinimo !== null ? SolicitudPedidoGenerador::colorPorPct($pctMinimo) : null,
        ];
    }, $filas);

    usort($data, static function (array $a, array $b): int {
        return strcmp((string) $a['producto'], (string) $b['producto']);
    });

    // Maquinas en linea (mismo criterio que dashboard_operacion.php:
    // ultima_conexion dentro de los ultimos ConexionMaquina::MINUTOS_ONLINE)
    // y conteo de maestras, dentro del mismo alcance de maquinas del operador.
    $paramsMaquinas = [];
    $condMaquinas = ['m.id_estatus <> 3'];
    $authCondMaquinas = Auth::machineCondition('m', $paramsMaquinas);
    if ($authCondMaquinas) {
        $condMaquinas[] = $authCondMaquinas;
    }
    $stmtMaquinas = $pdo->prepare(
        'SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN m.ultima_conexion >= DATEADD(minute, -' . \App\Maquinas\ConexionMaquina::MINUTOS_ONLINE . ', SYSDATETIME()) THEN 1 ELSE 0 END) AS en_linea,
            SUM(CASE WHEN m.rol_maquina = 1 THEN 1 ELSE 0 END) AS maestras
         FROM vendingm.maquinas m
         WHERE ' . implode(' AND ', $condMaquinas)
    );
    $stmtMaquinas->execute($paramsMaquinas);
    $maquinasInfo = $stmtMaquinas->fetch(PDO::FETCH_ASSOC) ?: [];

    $resumen = [
        'medicamentos' => count($data),
        'restante_total' => array_sum(array_column($data, 'restante_total')),
        'capacidad_maxima_total' => array_sum(array_column($data, 'capacidad_maxima_total')),
        'dispensado_total' => array_sum(array_column($data, 'dispensado_total')),
        'maquinas_total' => (int) ($maquinasInfo['total'] ?? 0),
        'maquinas_en_linea' => (int) ($maquinasInfo['en_linea'] ?? 0),
        'maquinas_maestras' => (int) ($maquinasInfo['maestras'] ?? 0),
    ];

    echo json_encode([
        'ok' => true,
        'data' => [
            'filas' => $data,
            'resumen' => $resumen,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], JSON_UNESCAPED_UNICODE);
}
