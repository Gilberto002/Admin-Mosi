<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Vending\SolicitudPedidoGenerador;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$q = trim((string) ($_GET['q'] ?? ''));
$idMaquina = filter_input(INPUT_GET, 'id_maquina', FILTER_VALIDATE_INT) ?: null;
$soloBajo = ($_GET['solo_bajo'] ?? '') === '1';
$umbral = max(1, min(99, filter_input(INPUT_GET, 'umbral', FILTER_VALIDATE_INT) ?: 5));
$clave = trim((string) ($_GET['clave'] ?? ''));

try {
    $pdo = Connection::get();

    $params = [];
    $condiciones = [];

    $authCond = Auth::machineCondition('m', $params);
    if ($authCond) {
        $condiciones[] = $authCond;
    }
    if ($idMaquina) {
        $condiciones[] = 'm.id_maquina = :id_maquina';
        $params['id_maquina'] = $idMaquina;
    }
    if ($q !== '') {
        $condiciones[] = "(
            m.numero_serie LIKE :q1
            OR m.codigo_interno LIKE :q2
            OR m.nombre_ubicacion LIKE :q3
            OR p.nombre LIKE :q4
            OR p.codigo_barras LIKE :q5
            OR l.numero_lote LIKE :q6
        )";
        $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = $params['q5'] = $params['q6'] = "%{$q}%";
    }
    if ($soloBajo) {
        $condiciones[] = 'ca.id_carril IS NOT NULL AND ISNULL(ca.cantidad_actual, 0) <= :umbral';
        $params['umbral'] = $umbral;
    }
    if ($clave !== '') {
        $condiciones[] = 'p.clave = :clave';
        $params['clave'] = $clave;
    }

    $where = $condiciones ? ('WHERE ' . implode(' AND ', $condiciones)) : '';
    $paramsMaquinas = [];
    $scopeMaquinas = Auth::machineScope('m', $paramsMaquinas);

    $stmtMaquinas = $pdo->prepare(
        "SELECT m.id_maquina, m.numero_serie, m.codigo_interno, m.nombre_ubicacion,
                m.rol_maquina, m.id_maquina_padre
         FROM vendingm.maquinas m
         WHERE 1 = 1{$scopeMaquinas}
         ORDER BY m.numero_serie"
    );
    $stmtMaquinas->execute($paramsMaquinas);
    $maquinas = array_map(static function (array $m): array {
        return [
            'id_maquina' => (int) $m['id_maquina'],
            'numero_serie' => $m['numero_serie'],
            'codigo_interno' => $m['codigo_interno'],
            'nombre_ubicacion' => $m['nombre_ubicacion'],
            'rol_maquina' => (int) $m['rol_maquina'],
            'id_maquina_padre' => $m['id_maquina_padre'] !== null ? (int) $m['id_maquina_padre'] : null,
        ];
    }, $stmtMaquinas->fetchAll(PDO::FETCH_ASSOC));

    $sql = "SELECT
                m.id_maquina,
                m.numero_serie,
                m.codigo_interno,
                m.nombre_ubicacion,
                m.rol_maquina,
                ca.numero_carril,
                ISNULL(ca.cantidad_actual, 0) AS cantidad_actual,
                ISNULL(ca.capacidad_maxima, 0) AS capacidad_maxima,
                ca.fecha_actualizacion,
                p.id_producto,
                p.nombre AS producto,
                p.codigo_barras,
                p.clave,
                p.presentacion,
                l.numero_lote,
                l.fecha_caducidad
            FROM vendingm.maquinas m
            LEFT JOIN vendingm.carriles ca ON ca.id_maquina = m.id_maquina AND ca.numero_carril % 10 <> 0
            LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
            LEFT JOIN vendingm.productos p ON p.id_producto = COALESCE(ca.id_producto, l.id_producto)
            {$where}
            ORDER BY m.numero_serie, ca.numero_carril";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $filas = array_map(static function (array $f) use ($umbral): array {
        $cantidad = (int) $f['cantidad_actual'];
        $capacidadMaxima = (int) $f['capacidad_maxima'];
        return [
            'id_maquina' => (int) $f['id_maquina'],
            'numero_serie' => $f['numero_serie'],
            'codigo_interno' => $f['codigo_interno'],
            'nombre_ubicacion' => $f['nombre_ubicacion'],
            'rol_maquina' => (int) $f['rol_maquina'],
            'numero_carril' => $f['numero_carril'] !== null ? (int) $f['numero_carril'] : null,
            'cantidad_actual' => $cantidad,
            'stock_bajo' => $cantidad <= $umbral,
            'fecha_actualizacion' => $f['fecha_actualizacion'],
            'capacidad_maxima' => $capacidadMaxima,
            // amarillo (50-60%) / rojo (<=49%) segun capacidad_maxima -- null si
            // no hay capacidad configurada o el carril esta por arriba del 60%.
            // Al <=30% ademas se auto-crea el pedido real a CEDIS (ver
            // App\Vending\SolicitudPedidoGenerador::autopedirSiTocaUmbral(),
            // disparado desde SalidaRegistrador::dispensar()) -- este color es
            // solo el indicador visual, no dispara nada por si mismo.
            'estado_capacidad' => SolicitudPedidoGenerador::colorPorPorcentaje($cantidad, $capacidadMaxima),
            'id_producto' => $f['id_producto'] !== null ? (int) $f['id_producto'] : null,
            'producto' => $f['producto'],
            'codigo_barras' => $f['codigo_barras'],
            'clave' => $f['clave'],
            'presentacion' => $f['presentacion'],
            'numero_lote' => $f['numero_lote'],
            'fecha_caducidad' => $f['fecha_caducidad'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    $resumen = [
        'maquinas' => count(array_unique(array_column($filas, 'id_maquina'))),
        'carriles' => 0,
        'unidades' => 0,
        'stock_bajo' => 0,
    ];

    foreach ($filas as $fila) {
        if ($fila['numero_carril'] === null) {
            continue;
        }
        $resumen['carriles']++;
        $resumen['unidades'] += $fila['cantidad_actual'];
        if ($fila['stock_bajo']) {
            $resumen['stock_bajo']++;
        }
    }

    // Resumen por producto (totales de la maquina, no por carril): agrupa las
    // mismas filas ya filtradas por id_maquina+id_producto para sumar cuanto
    // queda y cual es la capacidad fisica total entre todos sus carriles, y
    // se completa con lo realmente vendido (vendingm.movimientos_salida,
    // tipo 1 = venta) para ese mismo par maquina+producto.
    $porProducto = [];
    foreach ($filas as $fila) {
        if ($fila['id_producto'] === null) {
            continue;
        }
        $llave = $fila['id_maquina'] . ':' . $fila['id_producto'];
        if (!isset($porProducto[$llave])) {
            $porProducto[$llave] = [
                'id_maquina' => $fila['id_maquina'],
                'numero_serie' => $fila['numero_serie'],
                'nombre_ubicacion' => $fila['nombre_ubicacion'],
                'id_producto' => $fila['id_producto'],
                'producto' => $fila['producto'],
                'clave' => $fila['clave'],
                'restante_total' => 0,
                'capacidad_maxima_total' => 0,
                'surtido_total' => 0,
            ];
        }
        $porProducto[$llave]['restante_total'] += $fila['cantidad_actual'];
        $porProducto[$llave]['capacidad_maxima_total'] += $fila['capacidad_maxima'];
    }

    if ($porProducto) {
        $condVenta = [];
        $paramsVenta = [];
        $i = 0;
        foreach ($porProducto as $llave => $grupo) {
            $condVenta[] = "(ms.id_maquina = :vm{$i} AND ms.id_producto = :vp{$i})";
            $paramsVenta["vm{$i}"] = $grupo['id_maquina'];
            $paramsVenta["vp{$i}"] = $grupo['id_producto'];
            $i++;
        }
        $stmtVenta = $pdo->prepare(
            "SELECT ms.id_maquina, ms.id_producto, SUM(ms.cantidad) AS surtido
             FROM vendingm.movimientos_salida ms
             WHERE ms.id_tipo_movimiento = 1 AND (" . implode(' OR ', $condVenta) . ')
             GROUP BY ms.id_maquina, ms.id_producto'
        );
        $stmtVenta->execute($paramsVenta);
        foreach ($stmtVenta->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $llave = $v['id_maquina'] . ':' . $v['id_producto'];
            if (isset($porProducto[$llave])) {
                $porProducto[$llave]['surtido_total'] = (int) $v['surtido'];
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'maquinas' => $maquinas,
            'filas' => $filas,
            'resumen' => $resumen,
            'por_producto' => array_values($porProducto),
            'umbral' => $umbral,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], JSON_UNESCAPED_UNICODE);
}
