<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Auth\Auth;
use App\Maquinas\ActivacionMaquina;
use App\Maquinas\ConexionMaquina;
use App\Maquinas\SegmentoMaquina;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$q        = trim((string) ($_GET['q'] ?? ''));
$rol      = filter_input(INPUT_GET, 'rol', FILTER_VALIDATE_INT);
$padre    = filter_input(INPUT_GET, 'padre', FILTER_VALIDATE_INT);
$estado   = $_GET['estado'] ?? '';
$conexion = $_GET['conexion'] ?? '';
$estadoGeo = trim((string) ($_GET['estado_geo'] ?? ''));
$municipio = trim((string) ($_GET['municipio'] ?? ''));
$segmento = trim((string) ($_GET['segmento_operacion'] ?? $_GET['segmento'] ?? ''));
$agrupado = ($_GET['agrupado'] ?? '') === '1';
$page     = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
$pageSize = min(200, max(1, filter_input(INPUT_GET, 'page_size', FILTER_VALIDATE_INT) ?: 50));
$offset   = ($page - 1) * $pageSize;

try {
    $pdo = Connection::get();
    ActivacionMaquina::asegurarColumnas($pdo);
    ConexionMaquina::asegurarColumnas($pdo);
    SegmentoMaquina::asegurarColumnas($pdo);
    asegurarColumnasUbicacion($pdo);

    if ($agrupado) {
        // ---------------------------------------------------------------
        // Modo agrupado: una fila por UNIDAD (maestra + sus esclavas).
        // La paginación y el conteo total se hacen sobre el número de
        // maestras que cumplen el filtro (la maestra cuenta como grupo).
        // "estado" se evalúa contra el peor de los días-sin-mantenimiento
        // dentro del grupo (nunca cargada = el peor caso posible).
        // ---------------------------------------------------------------
        $condiciones = ['m.rol_maquina = 1'];
        $params = [];

        if ($q !== '') {
            $condiciones[] = "(
                m.numero_serie LIKE :q1 OR m.codigo_interno LIKE :q2 OR m.nombre_ubicacion LIKE :q3 OR m.nombre_maquina LIKE :q7
                OR m.clues LIKE :q9 OR m.estado_geo LIKE :q10 OR m.municipio LIKE :q11 OR m.localidad LIKE :q12
                OR EXISTS (
                    SELECT 1 FROM vendingm.maquinas e
                    WHERE e.id_maquina_padre = m.id_maquina
                      AND (e.numero_serie LIKE :q4 OR e.codigo_interno LIKE :q5 OR e.nombre_ubicacion LIKE :q6 OR e.nombre_maquina LIKE :q8
                           OR e.clues LIKE :q13 OR e.estado_geo LIKE :q14 OR e.municipio LIKE :q15 OR e.localidad LIKE :q16)
                )
            )";
            $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = $params['q5'] = $params['q6'] = $params['q7'] = $params['q8'] =
            $params['q9'] = $params['q10'] = $params['q11'] = $params['q12'] = $params['q13'] = $params['q14'] = $params['q15'] = $params['q16'] = "%{$q}%";
        }
        $authCond = Auth::machineCondition('m', $params);
        if ($authCond) {
            $condiciones[] = $authCond;
        }
        if ($estado === '') {
            $condiciones[] = 'm.id_estatus <> 3';
        } elseif ($estado === 'atencion') {
            $condiciones[] = 'peor.peor_dias > 30';
        } elseif ($estado === 'ok') {
            $condiciones[] = 'peor.peor_dias <= 30';
        } elseif ($estado === 'nunca') {
            // Al menos una unidad del grupo (maestra o alguna esclava) nunca cargó stock.
            $condiciones[] = 'peor.peor_dias >= 100000';
        } elseif (preg_match('/^estatus_(\d+)$/', (string) $estado, $mEstado)) {
            $condiciones[] = 'm.id_estatus = :id_estatus';
            $params['id_estatus'] = (int) $mEstado[1];
        }
        if ($conexion === 'online') {
            $condiciones[] = 'm.ultima_conexion >= DATEADD(minute, -10, SYSDATETIME())';
        } elseif ($conexion === 'offline') {
            $condiciones[] = 'm.ultima_conexion IS NOT NULL AND m.ultima_conexion < DATEADD(minute, -10, SYSDATETIME())';
        } elseif ($conexion === 'nunca') {
            $condiciones[] = 'm.ultima_conexion IS NULL';
        }
        if ($estadoGeo !== '') {
            $condiciones[] = '(m.estado_geo LIKE :estado_geo OR EXISTS (SELECT 1 FROM vendingm.maquinas e WHERE e.id_maquina_padre = m.id_maquina AND e.estado_geo LIKE :estado_geo_e))';
            $params['estado_geo'] = $params['estado_geo_e'] = "%{$estadoGeo}%";
        }
        if ($municipio !== '') {
            $condiciones[] = '(m.municipio LIKE :municipio OR EXISTS (SELECT 1 FROM vendingm.maquinas e WHERE e.id_maquina_padre = m.id_maquina AND e.municipio LIKE :municipio_e))';
            $params['municipio'] = $params['municipio_e'] = "%{$municipio}%";
        }
        if ($segmento !== '') {
            $condiciones[] = 'm.segmento_operacion = :segmento_operacion';
            $params['segmento_operacion'] = SegmentoMaquina::normalizar($segmento);
        }
        $where = 'WHERE ' . implode(' AND ', $condiciones);

        // Sub-select CROSS APPLY: calcula el peor (mayor) días-sin-mantenimiento
        // entre la maestra y todas sus esclavas. 100000 = centinela de "nunca cargada".
        $crossApplyPeor = "
            CROSS APPLY (
                SELECT MAX(dias) AS peor_dias FROM (
                    SELECT CASE WHEN m.fecha_ultimo_mantenimiento IS NULL THEN 100000
                                ELSE DATEDIFF(day, m.fecha_ultimo_mantenimiento, SYSDATETIME()) END AS dias
                    UNION ALL
                    SELECT CASE WHEN e.fecha_ultimo_mantenimiento IS NULL THEN 100000
                                ELSE DATEDIFF(day, e.fecha_ultimo_mantenimiento, SYSDATETIME()) END
                    FROM vendingm.maquinas e WHERE e.id_maquina_padre = m.id_maquina
                ) todos
            ) peor
        ";

        $stmtTotal = $pdo->prepare(
            "SELECT COUNT(*) FROM vendingm.maquinas m {$crossApplyPeor} {$where}"
        );
        $stmtTotal->execute($params);
        $total = (int) $stmtTotal->fetchColumn();

        $sql = "SELECT m.id_maquina, m.numero_serie, m.codigo_interno, m.nombre_maquina, m.nombre_ubicacion, m.id_sucursal_clinica,
                        m.numero_carriles, m.clues, m.estado_geo, m.municipio, m.localidad, m.nivel_atencion, m.institucion_grupo,
                        m.segmento_operacion,
                        m.rol_maquina, m.id_maquina_padre, m.fecha_ultimo_mantenimiento,
                        m.activada, m.fecha_activacion, m.fecha_ultima_activacion, m.permitir_reactivacion,
                        m.id_tecnico_instalador, m.tecnico_instalador_nombre,
                        m.id_estatus, em.nombre AS estatus_nombre,
                        m.ultima_conexion, m.tipo_conexion, m.version_app,
                        CASE
                            WHEN m.ultima_conexion IS NULL THEN 'nunca_conectada'
                            WHEN m.ultima_conexion >= DATEADD(minute, -10, SYSDATETIME()) THEN 'online'
                            ELSE 'offline'
                        END AS estado_conexion,
                        DATEDIFF(second, m.ultima_conexion, SYSDATETIME()) AS segundos_desde_conexion,
                        DATEDIFF(day, m.fecha_ultimo_mantenimiento, SYSDATETIME()) AS dias_sin_mantenimiento
                 FROM vendingm.maquinas m
                 LEFT JOIN vendingm.estatus_maquina em ON em.id_estatus = m.id_estatus
                 {$crossApplyPeor}
                 {$where}
                 ORDER BY m.numero_serie
                 OFFSET :offset ROWS FETCH NEXT :page_size ROWS ONLY";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue('page_size', $pageSize, PDO::PARAM_INT);
        $stmt->execute();
        $maestras = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Traemos las esclavas de todas las maestras de esta página en una sola consulta.
        $idsPagina = array_column($maestras, 'id_maquina');
        $esclavasPorPadre = [];
        if ($idsPagina) {
            $marcadores = implode(',', array_fill(0, count($idsPagina), '?'));
            $stmtE = $pdo->prepare(
                "SELECT e.id_maquina, e.numero_serie, e.codigo_interno, e.nombre_maquina, e.nombre_ubicacion,
                        e.numero_carriles, e.clues, e.estado_geo, e.municipio, e.localidad, e.nivel_atencion, e.institucion_grupo,
                        COALESCE(e.segmento_operacion, p.segmento_operacion, 'cliente') AS segmento_operacion,
                        COALESCE(e.id_sucursal_clinica, p.id_sucursal_clinica) AS id_sucursal_clinica,
                        e.id_maquina_padre,
                        e.activada, e.fecha_activacion, e.fecha_ultima_activacion, e.permitir_reactivacion,
                        e.id_tecnico_instalador, e.tecnico_instalador_nombre,
                        e.fecha_ultimo_mantenimiento, e.id_estatus, em.nombre AS estatus_nombre,
                        e.ultima_conexion, COALESCE(e.tipo_conexion, p.tipo_conexion) AS tipo_conexion,
                        COALESCE(e.version_app, p.version_app) AS version_app,
                        CASE
                            WHEN e.ultima_conexion IS NULL THEN 'nunca_conectada'
                            WHEN e.ultima_conexion >= DATEADD(minute, -10, SYSDATETIME()) THEN 'online'
                            ELSE 'offline'
                        END AS estado_conexion,
                        DATEDIFF(second, e.ultima_conexion, SYSDATETIME()) AS segundos_desde_conexion,
                        DATEDIFF(day, e.fecha_ultimo_mantenimiento, SYSDATETIME()) AS dias_sin_mantenimiento
                 FROM vendingm.maquinas e
                 INNER JOIN vendingm.maquinas p ON p.id_maquina = e.id_maquina_padre
                 LEFT JOIN vendingm.estatus_maquina em ON em.id_estatus = e.id_estatus
                 WHERE e.id_maquina_padre IN ({$marcadores})
                 ORDER BY e.numero_serie"
            );
            $stmtE->execute($idsPagina);
            foreach ($stmtE->fetchAll(PDO::FETCH_ASSOC) as $esc) {
                $esclavasPorPadre[(int) $esc['id_maquina_padre']][] = $esc;
            }
        }

        foreach ($maestras as &$m) {
            $m['esclavas'] = $esclavasPorPadre[(int) $m['id_maquina']] ?? [];
        }
        unset($m);

        echo json_encode([
            'ok' => true,
            'data' => $maestras,
            'meta' => ['total' => $total, 'page' => $page, 'page_size' => $pageSize, 'agrupado' => true],
        ]);
        exit;
    }

    // ---------------------------------------------------------------
    // Modo clásico (sin agrupar): se mantiene igual que antes, fila por
    // fila, para no romper integraciones existentes.
    // ---------------------------------------------------------------
    $condiciones = [];
    $params = [];

        if ($q !== '') {
        $condiciones[] = '(numero_serie LIKE :q1 OR codigo_interno LIKE :q2 OR nombre_ubicacion LIKE :q3 OR nombre_maquina LIKE :q4)';
        $params['q1'] = "%{$q}%";
        $params['q2'] = "%{$q}%";
        $params['q3'] = "%{$q}%";
        $params['q4'] = "%{$q}%";
    }
    $authCond = Auth::machineCondition('x', $params);
    if ($authCond) {
        $condiciones[] = $authCond;
    }
    if ($rol === 1 || $rol === 2) {
        $condiciones[] = 'rol_maquina = :rol';
        $params['rol'] = $rol;
    }
    if ($padre) {
        $condiciones[] = 'id_maquina_padre = :padre';
        $params['padre'] = $padre;
    }
    if ($estado === '') {
        $condiciones[] = 'id_estatus <> 3';
    } elseif ($estado === 'atencion') {
        $condiciones[] = '(dias_sin_mantenimiento IS NULL OR dias_sin_mantenimiento > 30)';
    } elseif ($estado === 'ok') {
        $condiciones[] = 'dias_sin_mantenimiento IS NOT NULL AND dias_sin_mantenimiento <= 30';
    } elseif (preg_match('/^estatus_(\d+)$/', (string) $estado, $mEstado)) {
        $condiciones[] = 'id_estatus = :id_estatus';
        $params['id_estatus'] = (int) $mEstado[1];
    }
    if (in_array($conexion, ['online', 'offline', 'nunca'], true)) {
        $condiciones[] = 'estado_conexion = :estado_conexion';
        $params['estado_conexion'] = $conexion === 'nunca' ? 'nunca_conectada' : $conexion;
    }
    if ($segmento !== '') {
        $condiciones[] = 'segmento_operacion = :segmento_operacion';
        $params['segmento_operacion'] = SegmentoMaquina::normalizar($segmento);
    }

    $where = $condiciones ? ('WHERE ' . implode(' AND ', $condiciones)) : '';

    $base = "SELECT m.id_maquina, m.numero_serie, m.codigo_interno, m.nombre_maquina, m.nombre_ubicacion, m.id_sucursal_clinica, m.rol_maquina, m.id_maquina_padre,
                     m.activada, m.fecha_activacion, m.fecha_ultima_activacion, m.permitir_reactivacion,
                     m.segmento_operacion,
                     m.id_tecnico_instalador, m.tecnico_instalador_nombre,
                     m.id_estatus, em.nombre AS estatus_nombre, m.fecha_ultimo_mantenimiento,
                     m.ultima_conexion, m.tipo_conexion, m.version_app,
                     CASE
                         WHEN m.ultima_conexion IS NULL THEN 'nunca_conectada'
                         WHEN m.ultima_conexion >= DATEADD(minute, -10, SYSDATETIME()) THEN 'online'
                         ELSE 'offline'
                     END AS estado_conexion,
                     DATEDIFF(second, m.ultima_conexion, SYSDATETIME()) AS segundos_desde_conexion,
                     DATEDIFF(day, m.fecha_ultimo_mantenimiento, SYSDATETIME()) AS dias_sin_mantenimiento
              FROM vendingm.maquinas m
              LEFT JOIN vendingm.estatus_maquina em ON em.id_estatus = m.id_estatus";

    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM ({$base}) x {$where}");
    $stmtTotal->execute($params);
    $total = (int) $stmtTotal->fetchColumn();

    $sql = "SELECT * FROM ({$base}) x {$where}
            ORDER BY numero_serie
            OFFSET :offset ROWS FETCH NEXT :page_size ROWS ONLY";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue('page_size', $pageSize, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode([
        'ok' => true,
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'meta' => ['total' => $total, 'page' => $page, 'page_size' => $pageSize],
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')]);
}

function asegurarColumnasUbicacion(PDO $pdo): void
{
    $pdo->exec("
        IF COL_LENGTH('vendingm.maquinas', 'clues') IS NULL
            ALTER TABLE vendingm.maquinas ADD clues NVARCHAR(30) NULL;
        IF COL_LENGTH('vendingm.maquinas', 'estado_geo') IS NULL
            ALTER TABLE vendingm.maquinas ADD estado_geo NVARCHAR(100) NULL;
        IF COL_LENGTH('vendingm.maquinas', 'municipio') IS NULL
            ALTER TABLE vendingm.maquinas ADD municipio NVARCHAR(120) NULL;
        IF COL_LENGTH('vendingm.maquinas', 'localidad') IS NULL
            ALTER TABLE vendingm.maquinas ADD localidad NVARCHAR(120) NULL;
        IF COL_LENGTH('vendingm.maquinas', 'nivel_atencion') IS NULL
            ALTER TABLE vendingm.maquinas ADD nivel_atencion NVARCHAR(80) NULL;
        IF COL_LENGTH('vendingm.maquinas', 'institucion_grupo') IS NULL
            ALTER TABLE vendingm.maquinas ADD institucion_grupo NVARCHAR(120) NULL;
    ");
}
