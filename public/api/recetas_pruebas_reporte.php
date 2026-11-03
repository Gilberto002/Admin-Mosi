<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Dispensacion\SalidaRegistrador;
use App\Security\Security;

Auth::requireAdmin();

$accion = (string) ($_GET['accion'] ?? 'resumen');

function fechaValida(?string $fecha): bool
{
    return $fecha !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1;
}

function construirFiltros(array &$params): string
{
    $desde = (string) ($_GET['desde'] ?? date('Y-m-d'));
    $hasta = (string) ($_GET['hasta'] ?? $desde);
    $idMaquina = $_GET['id_maquina'] ?? null;
    $q = trim((string) ($_GET['q'] ?? ''));
    $modo = (string) ($_GET['modo'] ?? 'pruebas');

    if (!fechaValida($desde) || !fechaValida($hasta)) {
        throw new InvalidArgumentException('Formato de fecha invalido. Usa YYYY-MM-DD.');
    }

    $where = "WHERE ms.id_tipo_movimiento = 1
        AND ms.observaciones LIKE 'Receta %'
        AND ms.fecha_movimiento >= :desde
        AND ms.fecha_movimiento < DATEADD(day, 1, :hasta)";
    $params['desde'] = $desde;
    $params['hasta'] = $hasta;

    if ($modo === 'pruebas') {
        $where .= " AND (ms.observaciones LIKE '%prueba sin descuento de stock%' OR r.nombre_paciente LIKE 'Paciente Prueba %')";
    }

    if (is_numeric($idMaquina)) {
        $where .= ' AND ms.id_maquina = :id_maquina';
        $params['id_maquina'] = (int) $idMaquina;
    }

    if ($q !== '') {
        $where .= ' AND (p.nombre LIKE :q1 OR p.codigo_barras LIKE :q2 OR m.numero_serie LIKE :q3 OR m.nombre_ubicacion LIKE :q4 OR ms.observaciones LIKE :q5)';
        $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = $params['q5'] = "%{$q}%";
    }

    $where .= Auth::machineScope('m', $params);
    return $where;
}

function construirFiltrosGenerados(array &$params): string
{
    $desde = (string) ($_GET['desde'] ?? date('Y-m-d'));
    $hasta = (string) ($_GET['hasta'] ?? $desde);
    $idMaquina = $_GET['id_maquina'] ?? null;
    $q = trim((string) ($_GET['q'] ?? ''));
    $modo = (string) ($_GET['modo'] ?? 'pruebas');

    if (!fechaValida($desde) || !fechaValida($hasta)) {
        throw new InvalidArgumentException('Formato de fecha invalido. Usa YYYY-MM-DD.');
    }

    $where = "WHERE r.fecha_creacion >= :g_desde
        AND r.fecha_creacion < DATEADD(day, 1, :g_hasta)";
    $params['g_desde'] = $desde;
    $params['g_hasta'] = $hasta;

    if ($modo === 'pruebas') {
        $where .= " AND r.nombre_paciente LIKE 'Paciente Prueba %'";
    }

    if (is_numeric($idMaquina)) {
        $where .= ' AND m.id_maquina = :g_id_maquina';
        $params['g_id_maquina'] = (int) $idMaquina;
    }

    if ($q !== '') {
        $where .= ' AND (p.nombre LIKE :g_q1 OR p.codigo_barras LIKE :g_q2 OR m.numero_serie LIKE :g_q3 OR m.nombre_ubicacion LIKE :g_q4 OR CONVERT(varchar(36), r.folio) LIKE :g_q5)';
        $params['g_q1'] = $params['g_q2'] = $params['g_q3'] = $params['g_q4'] = $params['g_q5'] = "%{$q}%";
    }

    $where .= Auth::machineScope('m', $params);
    return $where;
}

function bindParams(PDOStatement $stmt, array $params): void
{
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}

try {
    $pdo = Connection::get();
    SalidaRegistrador::asegurarTablas($pdo);

    if ($accion === 'maquinas') {
        $params = [];
        $scope = Auth::machineScope('m', $params);
        $stmt = $pdo->prepare(
            "SELECT m.id_maquina, m.numero_serie, m.nombre_ubicacion
             FROM vendingm.maquinas m
             WHERE m.id_estatus <> 3 {$scope}
             ORDER BY m.numero_serie"
        );
        $stmt->execute($params);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $params = [];
    $where = construirFiltros($params);
    $paramsGenerados = [];
    $whereGenerados = construirFiltrosGenerados($paramsGenerados);
    $folioExpr = "COALESCE(r.folio, NULLIF(LTRIM(RTRIM(REPLACE(SUBSTRING(ms.observaciones, 8, 120), ' (prueba sin descuento de stock)', ''))), ''))";
    $from = "
        FROM vendingm.movimientos_salida ms
        INNER JOIN vendingm.maquinas m ON m.id_maquina = ms.id_maquina
        INNER JOIN vendingm.carriles ca ON ca.id_carril = ms.id_carril
        LEFT JOIN vendingm.productos p ON p.id_producto = ms.id_producto
        LEFT JOIN vendingm.recetas r ON ms.observaciones LIKE CONCAT('Receta ', r.folio, '%')
        {$where}";
    $fromGenerados = "
        FROM vendingm.detalle_receta dr
        INNER JOIN vendingm.recetas r ON r.id_receta = dr.id_receta
        INNER JOIN vendingm.maquinas m ON m.id_maquina = COALESCE(dr.id_maquina, r.id_maquina)
        INNER JOIN vendingm.productos p ON p.id_producto = dr.id_producto
        {$whereGenerados}";

    if ($accion === 'exportar') {
        $stmt = $pdo->prepare(
            "SELECT
                ms.fecha_movimiento,
                {$folioExpr} AS folio_receta,
                CASE WHEN ms.observaciones LIKE '%prueba sin descuento de stock%' THEN 'Prueba sin descuento' ELSE 'Receta normal' END AS modo,
                m.numero_serie,
                m.nombre_ubicacion,
                ca.numero_carril,
                p.codigo_barras,
                p.nombre AS producto,
                ms.cantidad,
                ms.usuario,
                ms.observaciones
             {$from}
             ORDER BY ms.fecha_movimiento DESC"
        );
        bindParams($stmt, $params);
        $stmt->execute();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="recetas-pruebas-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['fecha', 'folio_receta', 'modo', 'maquina', 'ubicacion', 'carril', 'codigo_barras', 'producto', 'cantidad', 'usuario', 'observaciones']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            fputcsv($out, $fila);
        }
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');

    $stmtTotales = $pdo->prepare(
        "SELECT
            COUNT(*) AS movimientos,
            SUM(ms.cantidad) AS piezas,
            COUNT(DISTINCT {$folioExpr}) AS recetas,
            COUNT(DISTINCT ms.id_maquina) AS maquinas,
            COUNT(DISTINCT ms.id_producto) AS productos
         {$from}"
    );
    bindParams($stmtTotales, $params);
    $stmtTotales->execute();
    $totales = $stmtTotales->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmtProductos = $pdo->prepare(
        "SELECT TOP 12
            p.nombre AS producto,
            p.codigo_barras,
            SUM(ms.cantidad) AS piezas,
            COUNT(DISTINCT {$folioExpr}) AS recetas,
            COUNT(DISTINCT ms.id_maquina) AS maquinas
         {$from}
         GROUP BY p.nombre, p.codigo_barras
         ORDER BY piezas DESC, recetas DESC"
    );
    bindParams($stmtProductos, $params);
    $stmtProductos->execute();
    $productosSurtidos = $stmtProductos->fetchAll(PDO::FETCH_ASSOC);

    $stmtProductosGenerados = $pdo->prepare(
        "SELECT TOP 50
            p.nombre AS producto,
            p.codigo_barras,
            SUM(ISNULL(dr.cantidad_solicitada, 0)) AS generadas,
            SUM(ISNULL(dr.cantidad_entregada, 0)) AS entregadas_registradas,
            COUNT(DISTINCT r.id_receta) AS recetas
         {$fromGenerados}
         GROUP BY p.nombre, p.codigo_barras
         ORDER BY generadas DESC, recetas DESC"
    );
    bindParams($stmtProductosGenerados, $paramsGenerados);
    $stmtProductosGenerados->execute();
    $productosGenerados = $stmtProductosGenerados->fetchAll(PDO::FETCH_ASSOC);

    $comparativo = [];
    foreach ($productosGenerados as $fila) {
        $clave = ((string) ($fila['codigo_barras'] ?? '')) !== ''
            ? 'c:' . (string) $fila['codigo_barras']
            : 'n:' . strtolower((string) ($fila['producto'] ?? ''));
        $comparativo[$clave] = [
            'producto' => $fila['producto'],
            'codigo_barras' => $fila['codigo_barras'],
            'generadas' => (float) ($fila['generadas'] ?? 0),
            'entregadas_receta' => (float) ($fila['entregadas_registradas'] ?? 0),
            'surtidas' => 0,
            'recetas' => (int) ($fila['recetas'] ?? 0),
        ];
    }
    foreach ($productosSurtidos as $fila) {
        $clave = ((string) ($fila['codigo_barras'] ?? '')) !== ''
            ? 'c:' . (string) $fila['codigo_barras']
            : 'n:' . strtolower((string) ($fila['producto'] ?? ''));
        if (!isset($comparativo[$clave])) {
            $comparativo[$clave] = [
                'producto' => $fila['producto'],
                'codigo_barras' => $fila['codigo_barras'],
                'generadas' => 0,
                'entregadas_receta' => 0,
                'surtidas' => 0,
                'recetas' => 0,
            ];
        }
        $comparativo[$clave]['surtidas'] += (float) ($fila['piezas'] ?? 0);
    }
    $comparativo = array_values($comparativo);
    usort($comparativo, static fn(array $a, array $b): int => ($b['generadas'] <=> $a['generadas']) ?: ($b['surtidas'] <=> $a['surtidas']));

    $stmtMaquinas = $pdo->prepare(
        "SELECT TOP 12
            m.numero_serie,
            m.nombre_ubicacion,
            SUM(ms.cantidad) AS piezas,
            COUNT(DISTINCT {$folioExpr}) AS recetas,
            COUNT(DISTINCT ms.id_producto) AS productos
         {$from}
         GROUP BY m.numero_serie, m.nombre_ubicacion
         ORDER BY piezas DESC, recetas DESC"
    );
    bindParams($stmtMaquinas, $params);
    $stmtMaquinas->execute();

    $stmtHoras = $pdo->prepare(
        "SELECT
            DATEPART(hour, ms.fecha_movimiento) AS hora,
            SUM(ms.cantidad) AS piezas,
            COUNT(DISTINCT {$folioExpr}) AS recetas
         {$from}
         GROUP BY DATEPART(hour, ms.fecha_movimiento)
         ORDER BY hora ASC"
    );
    bindParams($stmtHoras, $params);
    $stmtHoras->execute();

    $stmtCarriles = $pdo->prepare(
        "SELECT TOP 20
            m.numero_serie,
            ca.numero_carril,
            p.nombre AS producto,
            SUM(ms.cantidad) AS piezas
         {$from}
         GROUP BY m.numero_serie, ca.numero_carril, p.nombre
         ORDER BY piezas DESC"
    );
    bindParams($stmtCarriles, $params);
    $stmtCarriles->execute();

    $stmtDetalle = $pdo->prepare(
        "SELECT TOP 250
            ms.fecha_movimiento,
            {$folioExpr} AS folio_receta,
            m.numero_serie,
            m.nombre_ubicacion,
            ca.numero_carril,
            p.codigo_barras,
            p.nombre AS producto,
            ms.cantidad,
            ms.usuario
         {$from}
         ORDER BY ms.fecha_movimiento DESC"
    );
    bindParams($stmtDetalle, $params);
    $stmtDetalle->execute();

    echo json_encode([
        'ok' => true,
        'data' => [
            'totales' => [
                'movimientos' => (int) ($totales['movimientos'] ?? 0),
                'piezas' => (float) ($totales['piezas'] ?? 0),
                'recetas' => (int) ($totales['recetas'] ?? 0),
                'maquinas' => (int) ($totales['maquinas'] ?? 0),
                'productos' => (int) ($totales['productos'] ?? 0),
                'piezas_generadas' => (float) array_sum(array_map(static fn(array $x): float => (float) ($x['generadas'] ?? 0), $productosGenerados)),
            ],
            'productos' => $productosSurtidos,
            'productos_generados' => $productosGenerados,
            'comparativo_productos' => $comparativo,
            'maquinas' => $stmtMaquinas->fetchAll(PDO::FETCH_ASSOC),
            'horas' => $stmtHoras->fetchAll(PDO::FETCH_ASSOC),
            'carriles' => $stmtCarriles->fetchAll(PDO::FETCH_ASSOC),
            'detalle' => $stmtDetalle->fetchAll(PDO::FETCH_ASSOC),
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    Security::logException($e, 'recetas_pruebas_reporte');
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'No se pudo recuperar el reporte de recetas.'], JSON_UNESCAPED_UNICODE);
}
