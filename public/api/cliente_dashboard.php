<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Api\AndroidApi;
use App\Auth\Auth;
use App\Database\Connection;
use App\Dispensacion\SalidaRegistrador;
use App\Maquinas\ConexionMaquina;
use App\Security\Security;

Auth::requireCliente();
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Connection::get();
    ConexionMaquina::asegurarColumnas($pdo);
    AndroidApi::asegurarTablas($pdo);
    SalidaRegistrador::asegurarTablas($pdo);

    $vista = (string) ($_GET['vista'] ?? 'resumen');
    if ($vista === 'detalle') {
        responderDetalle($pdo);
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'maquinas' => consultarMaquinas($pdo),
            'medicamentos' => consultarMedicamentos($pdo),
            'recetas' => consultarRecetas($pdo),
            'rangos_ambientales' => [
                'temperatura_min' => 15,
                'temperatura_max' => 20,
                'humedad_min' => 0,
                'humedad_max' => 60,
                'etiqueta' => 'Rango operativo configurado',
            ],
            'generado_en' => date(DATE_ATOM),
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    Security::logException($e, 'cliente_dashboard');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo cargar el centro de monitoreo.'], JSON_UNESCAPED_UNICODE);
}

function consultarMaquinas(PDO $pdo): array
{
    $params = [];
    $scope = Auth::machineScope('m', $params);
    $stmt = $pdo->prepare(
        "SELECT m.id_maquina, m.numero_serie, m.codigo_interno, m.nombre_maquina,
                m.nombre_ubicacion, m.direccion, m.latitud, m.longitud, m.clues,
                m.estado_geo, m.municipio, m.localidad, m.nivel_atencion,
                m.institucion_grupo, m.numero_carriles, m.ultima_conexion,
                m.tipo_conexion, m.version_app, m.id_estatus,
                CASE WHEN m.ultima_conexion >= DATEADD(minute, -10, SYSDATETIME()) THEN 'online'
                     WHEN m.ultima_conexion IS NULL THEN 'sin_conexion' ELSE 'offline' END AS estado_conexion
         FROM vendingm.maquinas m
         WHERE m.rol_maquina = 1 AND m.id_estatus <> 3 {$scope}
         ORDER BY m.estado_geo, m.municipio, m.numero_serie"
    );
    $stmt->execute($params);
    $maquinas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$maquinas) {
        return ['resumen' => resumenMaquinas([]), 'items' => []];
    }

    $ids = array_map('intval', array_column($maquinas, 'id_maquina'));
    $marcadores = implode(',', array_fill(0, count($ids), '?'));

    $stmtE = $pdo->prepare(
        "SELECT e.id_maquina, e.id_maquina_padre, e.numero_serie, e.codigo_interno,
                e.nombre_maquina, e.nombre_ubicacion, e.numero_carriles,
                e.ultima_conexion, e.tipo_conexion, e.version_app, e.id_estatus,
                CASE WHEN e.ultima_conexion >= DATEADD(minute, -10, SYSDATETIME()) THEN 'online'
                     WHEN e.ultima_conexion IS NULL THEN 'sin_conexion' ELSE 'offline' END AS estado_conexion
         FROM vendingm.maquinas e
         WHERE e.id_maquina_padre IN ({$marcadores}) AND e.id_estatus <> 3
         ORDER BY e.numero_serie"
    );
    $stmtE->execute($ids);
    $esclavas = [];
    foreach ($stmtE->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $esclavas[(int) $fila['id_maquina_padre']][] = normalizarMaquina($fila);
    }

    $stmtStock = $pdo->prepare(
        "SELECT m.id_maquina AS id_unidad,
                COUNT(CASE WHEN ca.numero_carril % 10 <> 0 THEN 1 END) AS carriles,
                SUM(CASE WHEN ca.numero_carril % 10 <> 0 THEN ISNULL(ca.cantidad_actual, 0) ELSE 0 END) AS piezas,
                SUM(CASE WHEN ca.numero_carril % 10 <> 0 AND ISNULL(ca.cantidad_actual, 0) = 0 THEN 1 ELSE 0 END) AS vacios,
                SUM(CASE WHEN ca.numero_carril % 10 <> 0 AND ca.cantidad_actual BETWEEN 1 AND 4 THEN 1 ELSE 0 END) AS bajos
         FROM vendingm.maquinas m
         LEFT JOIN vendingm.carriles ca ON ca.id_maquina = m.id_maquina
         WHERE m.id_maquina IN ({$marcadores})
         GROUP BY m.id_maquina"
    );
    $stmtStock->execute($ids);
    $stock = [];
    foreach ($stmtStock->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $stock[(int) $fila['id_unidad']] = [
            'carriles' => (int) $fila['carriles'],
            'piezas' => (int) $fila['piezas'],
            'vacios' => (int) $fila['vacios'],
            'bajos' => (int) $fila['bajos'],
        ];
    }

    $stmtT = $pdo->prepare(
        "SELECT id_unidad, id_maquina, payload_json, battery_level, network_type,
                signal_strength, fecha_creacion
         FROM (
            SELECT mt.id_maquina AS id_unidad,
                   t.id_maquina, t.payload_json, t.battery_level, t.network_type,
                   t.signal_strength, t.fecha_creacion,
                   ROW_NUMBER() OVER (
                        PARTITION BY mt.id_maquina
                       ORDER BY t.id_telemetria DESC
                   ) AS fila
            FROM vendingm.api_telemetria_android t
            INNER JOIN vendingm.maquinas mt ON mt.id_maquina = t.id_maquina
             WHERE mt.id_maquina IN ({$marcadores})
         ) ult
         WHERE fila = 1"
    );
    $stmtT->execute($ids);
    $telemetria = [];
    foreach ($stmtT->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $telemetria[(int) $fila['id_unidad']] = telemetriaNormalizada($fila);
    }

    $items = [];
    foreach ($maquinas as $fila) {
        $id = (int) $fila['id_maquina'];
        $item = normalizarMaquina($fila);
        $item['esclavas'] = $esclavas[$id] ?? [];
        $item['stock'] = $stock[$id] ?? ['carriles' => 0, 'piezas' => 0, 'vacios' => 0, 'bajos' => 0];
        $item['telemetria'] = $telemetria[$id] ?? telemetriaAmbientalDemoActual($id);
        $items[] = $item;
    }

    return ['resumen' => resumenMaquinas($items), 'items' => $items];
}

function consultarMedicamentos(PDO $pdo): array
{
    $params = [];
    $scope = Auth::machineScope('m', $params);
    $stmt = $pdo->prepare(
        "SELECT COUNT(CASE WHEN ca.numero_carril % 10 <> 0 THEN 1 END) AS carriles,
                SUM(CASE WHEN ca.numero_carril % 10 <> 0 THEN ISNULL(ca.cantidad_actual, 0) ELSE 0 END) AS piezas,
                SUM(CASE WHEN ca.numero_carril % 10 <> 0 AND ISNULL(ca.cantidad_actual, 0) = 0 THEN 1 ELSE 0 END) AS vacios,
                SUM(CASE WHEN ca.numero_carril % 10 <> 0 AND ca.cantidad_actual BETWEEN 1 AND 4 THEN 1 ELSE 0 END) AS bajos,
                COUNT(DISTINCT CASE WHEN ca.numero_carril % 10 <> 0 THEN COALESCE(ca.id_producto, lot.id_producto) END) AS productos
         FROM vendingm.carriles ca
         INNER JOIN vendingm.maquinas m ON m.id_maquina = ca.id_maquina
         LEFT JOIN vendingm.lotes lot ON lot.id_lote = ca.id_lote_actual
         WHERE 1 = 1 {$scope}"
    );
    $stmt->execute($params);
    $resumen = array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC) ?: []);

    $paramsTop = [];
    $scopeTop = Auth::machineScope('m', $paramsTop);
    $stmtTop = $pdo->prepare(
        "SELECT TOP 10 p.nombre, p.codigo_barras,
                SUM(CASE WHEN ms.fecha_movimiento >= DATEADD(day, -30, SYSDATETIME()) THEN ms.cantidad ELSE 0 END) AS unidades_30d,
                SUM(ms.cantidad) AS unidades_total,
                COUNT(DISTINCT ms.id_maquina) AS maquinas
         FROM vendingm.movimientos_salida ms
         INNER JOIN vendingm.maquinas m ON m.id_maquina = ms.id_maquina
         INNER JOIN vendingm.productos p ON p.id_producto = ms.id_producto
         WHERE ms.id_tipo_movimiento = 1 {$scopeTop}
         GROUP BY p.nombre, p.codigo_barras
         ORDER BY unidades_30d DESC, unidades_total DESC"
    );
    $stmtTop->execute($paramsTop);

    $paramsStock = [];
    $scopeStock = Auth::machineScope('m', $paramsStock);
    $stmtStock = $pdo->prepare(
        "SELECT TOP 80 m.id_maquina, m.numero_serie, m.nombre_ubicacion,
                ca.numero_carril, ca.cantidad_actual, p.nombre AS producto,
                p.codigo_barras, l.numero_lote, l.fecha_caducidad
         FROM vendingm.carriles ca
         INNER JOIN vendingm.maquinas m ON m.id_maquina = ca.id_maquina
         LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
         LEFT JOIN vendingm.productos p ON p.id_producto = COALESCE(ca.id_producto, l.id_producto)
         WHERE ca.numero_carril % 10 <> 0 {$scopeStock}
         ORDER BY ca.cantidad_actual ASC, m.numero_serie, ca.numero_carril"
    );
    $stmtStock->execute($paramsStock);

    $paramsMaquina = [];
    $scopeMaquina = Auth::machineScope('m', $paramsMaquina);
    $stmtMaquina = $pdo->prepare(
        "SELECT m.id_maquina, m.numero_serie, m.rol_maquina,
                COUNT(CASE WHEN ca.numero_carril % 10 <> 0 THEN 1 END) AS carriles,
                SUM(CASE WHEN ca.numero_carril % 10 <> 0 THEN ISNULL(ca.cantidad_actual, 0) ELSE 0 END) AS piezas,
                SUM(CASE WHEN ca.numero_carril % 10 <> 0 AND ISNULL(ca.cantidad_actual, 0) = 0 THEN 1 ELSE 0 END) AS vacios,
                SUM(CASE WHEN ca.numero_carril % 10 <> 0 AND ca.cantidad_actual BETWEEN 1 AND 4 THEN 1 ELSE 0 END) AS bajos
         FROM vendingm.maquinas m
         LEFT JOIN vendingm.carriles ca ON ca.id_maquina = m.id_maquina
         WHERE m.id_estatus <> 3 {$scopeMaquina}
         GROUP BY m.id_maquina, m.numero_serie, m.rol_maquina
         ORDER BY piezas DESC, m.numero_serie"
    );
    $stmtMaquina->execute($paramsMaquina);

    return [
        'resumen' => $resumen,
        'rotacion' => $stmtTop->fetchAll(PDO::FETCH_ASSOC),
        'carriles' => $stmtStock->fetchAll(PDO::FETCH_ASSOC),
        'por_maquina' => $stmtMaquina->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function consultarRecetas(PDO $pdo): array
{
    $params = [];
    $scope = Auth::machineScope('m', $params);
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS total_30d,
                SUM(CASE WHEN r.id_estatus = 1 THEN 1 ELSE 0 END) AS pendientes,
                SUM(CASE WHEN r.id_estatus = 2 THEN 1 ELSE 0 END) AS entregadas,
                SUM(CASE WHEN r.id_estatus = 3 THEN 1 ELSE 0 END) AS en_proceso,
                SUM(CASE WHEN r.id_estatus IN (4,5) THEN 1 ELSE 0 END) AS incidencias
         FROM vendingm.recetas r
         INNER JOIN vendingm.maquinas m ON m.id_maquina = r.id_maquina
         WHERE r.fecha_creacion >= DATEADD(day, -30, SYSDATETIME()) {$scope}"
    );
    $stmt->execute($params);
    $resumen = array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC) ?: []);

    $paramsM = [];
    $scopeM = Auth::machineScope('m', $paramsM);
    $stmtM = $pdo->prepare(
        "SELECT TOP 10 m.id_maquina, m.numero_serie, m.nombre_ubicacion,
                COUNT(DISTINCT r.id_receta) AS recetas,
                COUNT(DISTINCT CASE WHEN r.id_estatus = 2 THEN r.id_receta END) AS entregadas,
                COUNT(DISTINCT CASE WHEN r.id_estatus IN (4,5) THEN r.id_receta END) AS incidencias,
                ISNULL(SUM(dr.cantidad_solicitada), 0) AS piezas
         FROM vendingm.recetas r
         LEFT JOIN vendingm.detalle_receta dr ON dr.id_receta = r.id_receta
         INNER JOIN vendingm.maquinas m ON m.id_maquina = COALESCE(dr.id_maquina, r.id_maquina)
         WHERE r.fecha_creacion >= DATEADD(day, -30, SYSDATETIME()) {$scopeM}
         GROUP BY m.id_maquina, m.numero_serie, m.nombre_ubicacion
         ORDER BY recetas DESC, piezas DESC"
    );
    $stmtM->execute($paramsM);

    $paramsD = [];
    $scopeD = Auth::machineScope('m', $paramsD);
    $stmtD = $pdo->prepare(
        "SELECT CONVERT(VARCHAR(10), r.fecha_creacion, 23) AS dia, COUNT(*) AS recetas,
                SUM(CASE WHEN r.id_estatus = 2 THEN 1 ELSE 0 END) AS entregadas
         FROM vendingm.recetas r
         INNER JOIN vendingm.maquinas m ON m.id_maquina = r.id_maquina
         WHERE r.fecha_creacion >= DATEADD(day, -14, CAST(SYSDATETIME() AS date)) {$scopeD}
         GROUP BY CONVERT(VARCHAR(10), r.fecha_creacion, 23)
         ORDER BY dia"
    );
    $stmtD->execute($paramsD);

    $paramsR = [];
    $scopeR = Auth::machineScope('m', $paramsR);
    $stmtR = $pdo->prepare(
        "SELECT TOP 10 r.id_receta, CONVERT(VARCHAR(36), r.folio) AS folio,
                r.nombre_paciente, r.medico_solicita, r.fecha_creacion, r.fecha_entregada,
                m.numero_serie, m.nombre_ubicacion,
                COUNT(dr.id_detalle_receta) AS medicamentos,
                ISNULL(SUM(ISNULL(dr.cantidad_entregada, 0)), 0) AS piezas
         FROM vendingm.recetas r
         INNER JOIN vendingm.maquinas m ON m.id_maquina = r.id_maquina
         LEFT JOIN vendingm.detalle_receta dr ON dr.id_receta = r.id_receta
         WHERE r.id_estatus = 2 {$scopeR}
         GROUP BY r.id_receta, r.folio, r.nombre_paciente, r.medico_solicita,
                  r.fecha_creacion, r.fecha_entregada, m.numero_serie, m.nombre_ubicacion
         ORDER BY COALESCE(r.fecha_entregada, r.fecha_creacion) DESC"
    );
    $stmtR->execute($paramsR);
    $recientes = $stmtR->fetchAll(PDO::FETCH_ASSOC);

    if ($recientes) {
        $ids = array_map('intval', array_column($recientes, 'id_receta'));
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $stmtItems = $pdo->prepare(
            "SELECT dr.id_receta, p.nombre, p.codigo_barras, dr.numero_carril,
                    ISNULL(dr.cantidad_solicitada, 0) AS cantidad_solicitada,
                    ISNULL(dr.cantidad_entregada, 0) AS cantidad_entregada,
                    COALESCE(md.numero_serie, mr.numero_serie) AS numero_serie
             FROM vendingm.detalle_receta dr
             INNER JOIN vendingm.recetas r ON r.id_receta = dr.id_receta
             INNER JOIN vendingm.maquinas mr ON mr.id_maquina = r.id_maquina
             LEFT JOIN vendingm.maquinas md ON md.id_maquina = dr.id_maquina
             LEFT JOIN vendingm.productos p ON p.id_producto = dr.id_producto
             WHERE dr.id_receta IN ({$marcadores})
             ORDER BY dr.id_receta DESC, p.nombre, dr.numero_carril"
        );
        $stmtItems->execute($ids);
        $itemsPorReceta = [];
        foreach ($stmtItems->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $item['cantidad_solicitada'] = (int) $item['cantidad_solicitada'];
            $item['cantidad_entregada'] = (int) $item['cantidad_entregada'];
            $itemsPorReceta[(int) $item['id_receta']][] = $item;
        }
        foreach ($recientes as &$receta) {
            $receta['id_receta'] = (int) $receta['id_receta'];
            $receta['medicamentos'] = (int) $receta['medicamentos'];
            $receta['piezas'] = (int) $receta['piezas'];
            $receta['items'] = $itemsPorReceta[$receta['id_receta']] ?? [];
        }
        unset($receta);
    }

    $paramsTop = [];
    $scopeTop = Auth::machineScope('m', $paramsTop);
    $stmtTop = $pdo->prepare(
        "SELECT TOP 10 p.nombre, p.codigo_barras,
                SUM(ISNULL(dr.cantidad_entregada, 0)) AS unidades,
                COUNT(DISTINCT r.id_receta) AS recetas
         FROM vendingm.detalle_receta dr
         INNER JOIN vendingm.recetas r ON r.id_receta = dr.id_receta
         INNER JOIN vendingm.maquinas m ON m.id_maquina = r.id_maquina
         INNER JOIN vendingm.productos p ON p.id_producto = dr.id_producto
         WHERE r.id_estatus = 2
           AND COALESCE(r.fecha_entregada, r.fecha_creacion) >= DATEADD(day, -30, SYSDATETIME())
           AND ISNULL(dr.cantidad_entregada, 0) > 0 {$scopeTop}
         GROUP BY p.nombre, p.codigo_barras
         ORDER BY unidades DESC, recetas DESC, p.nombre"
    );
    $stmtTop->execute($paramsTop);

    return [
        'resumen' => $resumen,
        'maquinas' => $stmtM->fetchAll(PDO::FETCH_ASSOC),
        'tendencia' => $stmtD->fetchAll(PDO::FETCH_ASSOC),
        'recientes' => $recientes,
        'medicamentos_top' => $stmtTop->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function responderDetalle(PDO $pdo): never
{
    $id = filter_input(INPUT_GET, 'id_maquina', FILTER_VALIDATE_INT);
    if (!$id || !Auth::canAccessMachine($pdo, $id)) {
        http_response_code($id ? 403 : 422);
        echo json_encode(['ok' => false, 'error' => $id ? 'No tienes acceso a esta maquina.' : 'Falta id_maquina.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT TOP 96 id_maquina, payload_json, battery_level, network_type, signal_strength, fecha_creacion
         FROM vendingm.api_telemetria_android
         WHERE id_maquina = :id_maquina
         ORDER BY fecha_creacion DESC"
    );
    $stmt->execute(['id_maquina' => $id]);
    $historial = [];
    foreach (array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC)) as $fila) {
        $telemetria = telemetriaNormalizada($fila);
        if ($telemetria['temperatura'] !== null || $telemetria['humedad'] !== null) {
            $historial[] = $telemetria;
        }
    }
    $ambientalDemo = !$historial;
    if ($ambientalDemo) {
        $historial = generarHistorialAmbientalDemo($id);
    }

    $stmtStock = $pdo->prepare(
        "SELECT m.id_maquina, m.numero_serie, m.rol_maquina, ca.numero_carril,
                ISNULL(ca.cantidad_actual, 0) AS cantidad_actual,
                p.nombre AS producto, p.codigo_barras, l.numero_lote, l.fecha_caducidad
         FROM vendingm.maquinas m
         INNER JOIN vendingm.carriles ca ON ca.id_maquina = m.id_maquina
         LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
         LEFT JOIN vendingm.productos p ON p.id_producto = COALESCE(ca.id_producto, l.id_producto)
         WHERE m.id_maquina = :id_maquina
           AND ca.numero_carril % 10 <> 0
         ORDER BY m.rol_maquina, m.numero_serie, ca.numero_carril"
    );
    $stmtStock->execute(['id_maquina' => $id]);
    $stock = $stmtStock->fetchAll(PDO::FETCH_ASSOC);

    $stmtTop = $pdo->prepare(
        "SELECT TOP 10 p.nombre, p.codigo_barras, SUM(ms.cantidad) AS unidades,
                COUNT(*) AS movimientos
         FROM vendingm.movimientos_salida ms
         INNER JOIN vendingm.maquinas m ON m.id_maquina = ms.id_maquina
         INNER JOIN vendingm.productos p ON p.id_producto = ms.id_producto
         WHERE m.id_maquina = :id_maquina
           AND ms.id_tipo_movimiento = 1
           AND ms.fecha_movimiento >= DATEADD(day, -30, SYSDATETIME())
         GROUP BY p.nombre, p.codigo_barras
         ORDER BY unidades DESC, movimientos DESC"
    );
    $stmtTop->execute(['id_maquina' => $id]);

    $stmtLatido = $pdo->prepare(
        "SELECT MAX(ultima_conexion)
         FROM vendingm.maquinas
         WHERE id_maquina = :id_maquina"
    );
    $stmtLatido->execute(['id_maquina' => $id]);

    echo json_encode(['ok' => true, 'data' => [
        'historial_ambiental' => $historial,
        'stock' => $stock,
        'medicamentos_salida' => $stmtTop->fetchAll(PDO::FETCH_ASSOC),
        'ultimo_latido' => $stmtLatido->fetchColumn() ?: null,
        'ambiental_demo' => $ambientalDemo,
    ]], JSON_UNESCAPED_UNICODE);
    exit;
}

function generarHistorialAmbientalDemo(int $idMaquina): array
{
    $historial = [];
    $baseTemperatura = 17.1 + (($idMaquina % 7) * 0.15);
    $baseHumedad = 46 + (($idMaquina % 9) * 0.7);
    for ($i = 23; $i >= 0; $i--) {
        $fase = ($idMaquina % 13) + (23 - $i);
        $historial[] = [
            'temperatura' => round($baseTemperatura + sin($fase / 2.8) * 1.25, 1),
            'humedad' => round($baseHumedad + cos($fase / 3.1) * 4.2, 1),
            'bateria' => null,
            'red' => null,
            'senal' => null,
            'fecha' => date('Y-m-d H:i:s', strtotime("-{$i} hour")),
        ];
    }
    return $historial;
}

function telemetriaAmbientalDemoActual(int $idMaquina): array
{
    $fase = ($idMaquina % 13) + 23;
    return [
        'temperatura' => round(17.1 + (($idMaquina % 7) * 0.15) + sin($fase / 2.8) * 1.25, 1),
        'humedad' => round(46 + (($idMaquina % 9) * 0.7) + cos($fase / 3.1) * 4.2, 1),
        'bateria' => null,
        'red' => null,
        'senal' => null,
        'fecha' => date('Y-m-d H:i:s'),
        'demo' => true,
    ];
}

function normalizarMaquina(array $fila): array
{
    foreach (['id_maquina', 'id_maquina_padre', 'numero_carriles', 'id_estatus'] as $campo) {
        if (array_key_exists($campo, $fila)) {
            $fila[$campo] = $fila[$campo] !== null ? (int) $fila[$campo] : null;
        }
    }
    foreach (['latitud', 'longitud'] as $campo) {
        if (array_key_exists($campo, $fila)) {
            $fila[$campo] = is_numeric($fila[$campo]) ? (float) $fila[$campo] : null;
        }
    }
    return $fila;
}

function resumenMaquinas(array $items): array
{
    $online = count(array_filter($items, static fn(array $m): bool => $m['estado_conexion'] === 'online'));
    $esclavas = array_sum(array_map(static fn(array $m): int => count($m['esclavas'] ?? []), $items));
    $conAlertas = count(array_filter($items, static fn(array $m): bool => (($m['stock']['vacios'] ?? 0) + ($m['stock']['bajos'] ?? 0)) > 0));
    return ['unidades' => count($items), 'online' => $online, 'offline' => count($items) - $online, 'esclavas' => $esclavas, 'con_alertas' => $conAlertas];
}

function telemetriaNormalizada(array $fila): array
{
    $payload = json_decode((string) ($fila['payload_json'] ?? ''), true);
    $telemetria = is_array($payload['telemetria'] ?? null) ? $payload['telemetria'] : (is_array($payload) ? $payload : []);
    $temperatura = primerNumero($telemetria, ['temperatura', 'temperature', 'temperature_c', 'temp_c']);
    $humedad = primerNumero($telemetria, ['humedad', 'humidity', 'relative_humidity', 'humidity_percent']);
    return [
        'temperatura' => $temperatura,
        'humedad' => $humedad,
        'bateria' => isset($fila['battery_level']) ? (float) $fila['battery_level'] : null,
        'red' => $fila['network_type'] ?? null,
        'senal' => isset($fila['signal_strength']) ? (int) $fila['signal_strength'] : null,
        'fecha' => $fila['fecha_creacion'] ?? null,
    ];
}

function telemetriaVacia(): array
{
    return ['temperatura' => null, 'humedad' => null, 'bateria' => null, 'red' => null, 'senal' => null, 'fecha' => null];
}

function primerNumero(array $datos, array $claves): ?float
{
    foreach ($claves as $clave) {
        if (isset($datos[$clave]) && is_numeric($datos[$clave])) {
            return (float) $datos[$clave];
        }
    }
    foreach ($datos as $valor) {
        if (is_array($valor)) {
            $encontrado = primerNumero($valor, $claves);
            if ($encontrado !== null) {
                return $encontrado;
            }
        }
    }
    return null;
}
