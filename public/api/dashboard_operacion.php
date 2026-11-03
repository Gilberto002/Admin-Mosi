<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Maquinas\ConexionMaquina;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Connection::get();
    ConexionMaquina::asegurarColumnas($pdo);

    $paramsMaquinas = [];
    $scopeMaquinas = Auth::machineScope('m', $paramsMaquinas);
    $stmtMaquinas = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN m.ultima_conexion >= DATEADD(minute, -10, SYSDATETIME()) THEN 1 ELSE 0 END) AS online,
            SUM(CASE WHEN m.ultima_conexion IS NOT NULL AND m.ultima_conexion < DATEADD(minute, -10, SYSDATETIME()) THEN 1 ELSE 0 END) AS offline,
            SUM(CASE WHEN m.ultima_conexion IS NULL THEN 1 ELSE 0 END) AS nunca_conectadas
         FROM vendingm.maquinas m
         WHERE m.id_estatus <> 3 {$scopeMaquinas}"
    );
    $stmtMaquinas->execute($paramsMaquinas);
    $maquinas = $stmtMaquinas->fetch(PDO::FETCH_ASSOC) ?: [];

    $paramsDesconectadas = [];
    $scopeDesconectadas = Auth::machineScope('m', $paramsDesconectadas);
    $stmtDesconectadas = $pdo->prepare(
        "SELECT TOP 6 m.id_maquina, m.numero_serie, m.nombre_maquina, m.nombre_ubicacion,
                m.ultima_conexion, m.tipo_conexion, m.version_app,
                CASE WHEN m.ultima_conexion IS NULL THEN 'nunca_conectada' ELSE 'offline' END AS estado_conexion,
                DATEDIFF(minute, m.ultima_conexion, SYSDATETIME()) AS minutos_desde_conexion
         FROM vendingm.maquinas m
         WHERE m.id_estatus <> 3
           AND (m.ultima_conexion IS NULL OR m.ultima_conexion < DATEADD(minute, -10, SYSDATETIME()))
           {$scopeDesconectadas}
         ORDER BY CASE WHEN m.ultima_conexion IS NULL THEN 0 ELSE 1 END, m.ultima_conexion ASC"
    );
    $stmtDesconectadas->execute($paramsDesconectadas);

    $paramsRecetas = [];
    $scopeRecetas = Auth::machineScope('m', $paramsRecetas);
    $stmtRecetas = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN r.fecha_creacion >= CAST(SYSDATETIME() AS date) THEN 1 ELSE 0 END) AS creadas_hoy,
            SUM(CASE WHEN r.id_estatus = 1 THEN 1 ELSE 0 END) AS pendientes,
            SUM(CASE WHEN r.id_estatus = 3 THEN 1 ELSE 0 END) AS en_proceso,
            SUM(CASE WHEN r.id_estatus = 2 AND r.fecha_entregada >= CAST(SYSDATETIME() AS date) THEN 1 ELSE 0 END) AS entregadas_hoy,
            SUM(CASE WHEN r.id_estatus IN (4, 5) AND r.fecha_creacion >= CAST(SYSDATETIME() AS date) THEN 1 ELSE 0 END) AS incidencias_hoy
         FROM vendingm.recetas r
         INNER JOIN vendingm.maquinas m ON m.id_maquina = r.id_maquina
         WHERE 1 = 1 {$scopeRecetas}"
    );
    $stmtRecetas->execute($paramsRecetas);
    $recetas = $stmtRecetas->fetch(PDO::FETCH_ASSOC) ?: [];

    $paramsSalidas = [];
    $scopeSalidas = Auth::machineScope('m', $paramsSalidas);
    $stmtSalidas = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN ms.id_tipo_movimiento = 1 AND ms.fecha_movimiento >= CAST(SYSDATETIME() AS date) THEN ms.cantidad ELSE 0 END) AS piezas_hoy,
            SUM(CASE WHEN ms.id_tipo_movimiento = 1 AND ms.fecha_movimiento >= DATEADD(day, -7, SYSDATETIME()) THEN ms.cantidad ELSE 0 END) AS piezas_7d,
            SUM(CASE WHEN ms.id_tipo_movimiento IN (2, 3, 5) AND ms.fecha_movimiento >= CAST(SYSDATETIME() AS date) THEN ms.cantidad ELSE 0 END) AS mermas_hoy
         FROM vendingm.movimientos_salida ms
         INNER JOIN vendingm.maquinas m ON m.id_maquina = ms.id_maquina
         WHERE 1 = 1 {$scopeSalidas}"
    );
    $stmtSalidas->execute($paramsSalidas);
    $salidas = $stmtSalidas->fetch(PDO::FETCH_ASSOC) ?: [];

    $distribucion = [
        'activos' => 0,
        'en_ruta' => 0,
        'vencidos' => 0,
        'proximas_24h' => 0,
        'km_pendientes' => 0.0,
        'viajes' => [],
    ];
    $existeDistribucion = (bool) $pdo->query("SELECT CASE WHEN OBJECT_ID('vendingm.distribucion_viajes', 'U') IS NULL THEN 0 ELSE 1 END")->fetchColumn();
    if ($existeDistribucion) {
        $paramsDistribucion = [];
        $scopeDistribucion = Auth::machineScope('m', $paramsDistribucion);
        $stmtDistribucion = $pdo->prepare(
            "SELECT
                COUNT(*) AS activos,
                SUM(CASE WHEN dv.estatus = 'en_ruta' THEN 1 ELSE 0 END) AS en_ruta,
                SUM(CASE WHEN dv.fecha_limite < SYSDATETIME() THEN 1 ELSE 0 END) AS vencidos,
                SUM(CASE WHEN dv.fecha_limite >= SYSDATETIME() AND dv.fecha_limite <= DATEADD(hour, 24, SYSDATETIME()) THEN 1 ELSE 0 END) AS proximas_24h,
                SUM(ISNULL(dv.distancia_km, 0)) AS km_pendientes
             FROM vendingm.distribucion_viajes dv
             INNER JOIN vendingm.maquinas m ON m.id_maquina = dv.id_maquina
             WHERE dv.estatus IN ('programado', 'en_ruta') {$scopeDistribucion}"
        );
        $stmtDistribucion->execute($paramsDistribucion);
        $resumenDistribucion = $stmtDistribucion->fetch(PDO::FETCH_ASSOC) ?: [];
        $distribucion = array_merge($distribucion, $resumenDistribucion);

        $paramsViajes = [];
        $scopeViajes = Auth::machineScope('m', $paramsViajes);
        $stmtViajes = $pdo->prepare(
            "SELECT TOP 5 dv.id_viaje, dv.id_maquina, m.numero_serie, dv.destino_nombre,
                    dv.estatus, dv.fecha_limite, dv.distancia_km,
                    DATEDIFF(minute, SYSDATETIME(), dv.fecha_limite) AS minutos_restantes
             FROM vendingm.distribucion_viajes dv
             INNER JOIN vendingm.maquinas m ON m.id_maquina = dv.id_maquina
             WHERE dv.estatus IN ('programado', 'en_ruta') {$scopeViajes}
             ORDER BY CASE WHEN dv.fecha_limite < SYSDATETIME() THEN 0 ELSE 1 END, dv.fecha_limite ASC"
        );
        $stmtViajes->execute($paramsViajes);
        $distribucion['viajes'] = $stmtViajes->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'maquinas' => normalizarEnteros($maquinas),
            'maquinas_desconectadas' => $stmtDesconectadas->fetchAll(PDO::FETCH_ASSOC),
            'recetas' => normalizarEnteros($recetas),
            'salidas' => normalizarNumeros($salidas),
            'distribucion' => normalizarNumeros($distribucion),
            'generado_en' => date(DATE_ATOM),
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], JSON_UNESCAPED_UNICODE);
}

function normalizarEnteros(array $datos): array
{
    foreach ($datos as $clave => $valor) {
        $datos[$clave] = (int) ($valor ?? 0);
    }
    return $datos;
}

function normalizarNumeros(array $datos): array
{
    foreach ($datos as $clave => $valor) {
        if ($clave === 'viajes' || is_array($valor)) {
            continue;
        }
        $datos[$clave] = (float) ($valor ?? 0);
    }
    return $datos;
}
