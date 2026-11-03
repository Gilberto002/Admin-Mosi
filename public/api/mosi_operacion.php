<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Security\Security;
use App\Telemedicina\LegacyConnection;
use App\Telemedicina\Schema;
Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

/** @return string|null */
function tablaLegacy(PDO $pdo, string $nombre): ?string
{
    $stmt = $pdo->prepare(<<<'SQL'
SELECT TOP 1 QUOTENAME(SCHEMA_NAME(schema_id)) + '.' + QUOTENAME(name)
FROM sys.tables
WHERE name = :nombre
ORDER BY CASE SCHEMA_NAME(schema_id) WHEN 'fernandoruiz' THEN 0 WHEN 'dbo' THEN 1 ELSE 2 END
SQL);
    $stmt->execute(['nombre' => $nombre]);
    $tabla = $stmt->fetchColumn();
    return is_string($tabla) && $tabla !== '' ? $tabla : null;
}

/** @return array{0:string,1:array<string,mixed>} */
function alcanceLegacy(array $medicos, array $dispositivos, ?string $sucursal, string $alias = 'n'): array
{
    $params = [];
    $medicoMarks = [];
    foreach ($medicos as $i => $id) {
        $key = 'medico_' . $i;
        $medicoMarks[] = ':' . $key;
        $params[$key] = (int) $id;
    }
    $partes = [$alias . '.id_medico IN (' . implode(',', $medicoMarks) . ')'];

    $origenes = [];
    foreach ($dispositivos as $i => $valor) {
        $key = 'dispositivo_' . $i;
        $origenes[] = ':' . $key;
        $params[$key] = $valor;
    }
    $filtroOrigen = [];
    if ($origenes) {
        $filtroOrigen[] = 'LTRIM(RTRIM(CONVERT(NVARCHAR(100),' . $alias . '.st_dispositivo))) IN (' . implode(',', $origenes) . ')';
    }
    if ($sucursal !== null && $sucursal !== '') {
        $filtroOrigen[] = 'LTRIM(RTRIM(CONVERT(NVARCHAR(100),' . $alias . '.id_sucursal))) = :sucursal_mosi';
        $params['sucursal_mosi'] = $sucursal;
    }
    if ($filtroOrigen) {
        $partes[] = '(' . implode(' OR ', $filtroOrigen) . ')';
    }

    return [implode(' AND ', $partes), $params];
}

try {
    $pdo = Connection::get();
    Schema::ensure($pdo);

    $hoy = new DateTimeImmutable('today');
    $desdeTexto = trim((string) ($_GET['desde'] ?? $hoy->modify('-29 days')->format('Y-m-d')));
    $hastaTexto = trim((string) ($_GET['hasta'] ?? $hoy->format('Y-m-d')));
    $desde = DateTimeImmutable::createFromFormat('!Y-m-d', $desdeTexto);
    $hasta = DateTimeImmutable::createFromFormat('!Y-m-d', $hastaTexto);
    if (!$desde || !$hasta || $desde->format('Y-m-d') !== $desdeTexto || $hasta->format('Y-m-d') !== $hastaTexto) {
        throw new InvalidArgumentException('El rango de fechas no es válido.');
    }
    $dias = (int) $desde->diff($hasta)->format('%r%a');
    if ($dias < 0 || $dias > 365) {
        throw new InvalidArgumentException('Selecciona un rango entre 1 y 366 días.');
    }

    $idMosi = filter_input(INPUT_GET, 'id_mosi', FILTER_VALIDATE_INT) ?: null;
    $paramsAcceso = [];
    $condicionesAcceso = ["t.estatus <> 'baja'"];
    if (Auth::isClinico()) {
        $condicionesAcceso[] = 'EXISTS (
            SELECT 1 FROM vendingm.telemedicina_personal p
            INNER JOIN vendingm.telemedicina_personal_mosi pm ON pm.id_personal = p.id_personal
            WHERE pm.id_mosi = t.id_mosi AND p.id_usuario = :usuario_clinico AND p.activo = 1
        )';
        $paramsAcceso['usuario_clinico'] = Auth::id() ?? 0;
    } else {
        $scopeParams = [];
        $machineCondition = Auth::machineCondition('ma', $scopeParams);
        if ($machineCondition) {
            $condicionesAcceso[] = "EXISTS (
                SELECT 1 FROM vendingm.telemedicina_mosi_maquinas mm
                INNER JOIN vendingm.maquinas ma ON ma.id_maquina = mm.id_maquina
                WHERE mm.id_mosi = t.id_mosi AND {$machineCondition}
            )";
            $paramsAcceso += $scopeParams;
        }
    }
    $whereAcceso = implode(' AND ', $condicionesAcceso);
    $stmtMosis = $pdo->prepare("SELECT t.id_mosi,t.nombre,t.numero_serie,t.ubicacion,t.consultorio,
        t.id_sucursal_externa,t.sucursal_nombre,t.ultima_conexion,t.estatus,
        CASE WHEN t.ultima_conexion IS NULL THEN 'nunca_conectada'
             WHEN t.ultima_conexion >= DATEADD(minute,-10,SYSDATETIME()) THEN 'online' ELSE 'offline' END estado_conexion,
        g.nombre grupo_nombre,
        (SELECT COUNT(*) FROM vendingm.telemedicina_personal_mosi pm WHERE pm.id_mosi=t.id_mosi) total_personal
        FROM vendingm.telemedicina_mosi t
        LEFT JOIN vendingm.telemedicina_grupos g ON g.id_grupo=t.id_grupo
        WHERE {$whereAcceso}
        ORDER BY t.nombre,t.numero_serie");
    $stmtMosis->execute($paramsAcceso);
    $mosis = $stmtMosis->fetchAll(PDO::FETCH_ASSOC);
    if (!$mosis) {
        echo json_encode(['ok' => true, 'data' => ['mosis' => [], 'seleccion' => null, 'medicos' => [], 'llamadas' => [], 'tendencia' => [], 'stats' => []], 'legacy' => ['available' => false, 'message' => 'No hay módulos MOSI disponibles para este usuario.']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($idMosi === null) {
        $idMosi = (int) $mosis[0]['id_mosi'];
    }
    $seleccion = null;
    foreach ($mosis as $item) {
        if ((int) $item['id_mosi'] === $idMosi) {
            $seleccion = $item;
            break;
        }
    }
    if ($seleccion === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'MOSI no encontrada o sin acceso.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmtPersonal = $pdo->prepare("SELECT p.id_personal,p.id_medico_legacy,p.nombre,p.apellido,p.tipo_profesional,
        p.especialidad,p.estado_operativo,p.sesion_activa,p.hora_inicio,p.hora_fin,p.sucursal_legacy
        FROM vendingm.telemedicina_personal_mosi pm
        INNER JOIN vendingm.telemedicina_personal p ON p.id_personal=pm.id_personal
        WHERE pm.id_mosi=:id AND p.activo=1
        ORDER BY p.tipo_profesional,p.nombre,p.apellido");
    $stmtPersonal->execute(['id' => $idMosi]);
    $personal = $stmtPersonal->fetchAll(PDO::FETCH_ASSOC);

    $stmtDispositivos = $pdo->prepare("SELECT DISTINCT token FROM (
        SELECT LTRIM(RTRIM(CONVERT(NVARCHAR(100),m.numero_serie))) token
        FROM vendingm.telemedicina_mosi_maquinas mm INNER JOIN vendingm.maquinas m ON m.id_maquina=mm.id_maquina WHERE mm.id_mosi=:id1
        UNION ALL SELECT LTRIM(RTRIM(CONVERT(NVARCHAR(100),m.codigo_interno)))
        FROM vendingm.telemedicina_mosi_maquinas mm INNER JOIN vendingm.maquinas m ON m.id_maquina=mm.id_maquina WHERE mm.id_mosi=:id2
        UNION ALL SELECT LTRIM(RTRIM(CONVERT(NVARCHAR(100),m.id_maquina)))
        FROM vendingm.telemedicina_mosi_maquinas mm INNER JOIN vendingm.maquinas m ON m.id_maquina=mm.id_maquina WHERE mm.id_mosi=:id3
        UNION ALL SELECT LTRIM(RTRIM(CONVERT(NVARCHAR(100),t.identificador_dispositivo))) FROM vendingm.telemedicina_mosi t WHERE t.id_mosi=:id4
        UNION ALL SELECT LTRIM(RTRIM(CONVERT(NVARCHAR(100),t.numero_serie))) FROM vendingm.telemedicina_mosi t WHERE t.id_mosi=:id5
    ) x WHERE token IS NOT NULL AND token<>''");
    $stmtDispositivos->execute(['id1' => $idMosi, 'id2' => $idMosi, 'id3' => $idMosi, 'id4' => $idMosi, 'id5' => $idMosi]);
    $dispositivos = array_values(array_unique(array_map('strval', $stmtDispositivos->fetchAll(PDO::FETCH_COLUMN))));
    $idsMedicos = array_values(array_unique(array_filter(array_map(static fn(array $p): int => (int) ($p['id_medico_legacy'] ?? 0), $personal))));

    $respuesta = [
        'mosis' => $mosis,
        'seleccion' => $seleccion,
        'rango' => ['desde' => $desdeTexto, 'hasta' => $hastaTexto],
        'stats' => ['total' => 0, 'atendidas' => 0, 'perdidas' => 0, 'reasignadas' => 0, 'tasa_atencion' => 0, 'espera_promedio_segundos' => null, 'duracion_promedio_segundos' => null],
        'medicos' => array_map(static fn(array $p): array => $p + ['total' => 0, 'atendidas' => 0, 'perdidas' => 0, 'reasignadas' => 0, 'tasa_atencion' => 0, 'espera_promedio_segundos' => null, 'duracion_promedio_segundos' => null, 'ultima_llamada' => null], $personal),
        'tendencia' => [],
        'llamadas' => [],
    ];

    if (!$idsMedicos) {
        echo json_encode(['ok' => true, 'data' => $respuesta, 'legacy' => ['available' => true, 'precision' => 'sin_personal', 'message' => 'Asigna personal sincronizado a esta MOSI para consultar sus llamadas.']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $legacy = LegacyConnection::get();
        $tabla = tablaLegacy($legacy, 'tbl_notificaciones');
        if ($tabla === null) {
            throw new RuntimeException('No se encontró el historial de llamadas de Mosy.SYBI.');
        }
        [$alcance, $paramsLegacy] = alcanceLegacy($idsMedicos, $dispositivos, (string) ($seleccion['id_sucursal_externa'] ?? ''));
        $paramsLegacy['desde'] = $desdeTexto;
        $paramsLegacy['hasta'] = $hastaTexto;
        $base = "n.i_Activo=1 AND n.id_usuarioWeb IS NOT NULL AND n.id_usuarioWeb<>0
            AND n.dt_fecha_registro>=CONVERT(date,:desde,23) AND n.dt_fecha_registro<DATEADD(day,1,CONVERT(date,:hasta,23)) AND {$alcance}";
        $final = 'COALESCE(n.dt_fechaFinalizacion_Med,n.dt_fechaFinalizacion_User)';

        $sqlMedicos = "SELECT n.id_medico,COUNT(*) total,
            SUM(CASE WHEN {$final} IS NOT NULL THEN 1 ELSE 0 END) atendidas,
            SUM(CASE WHEN {$final} IS NULL THEN 1 ELSE 0 END) perdidas,
            SUM(CASE WHEN n.i_reasignacion IN(1,2) THEN 1 ELSE 0 END) reasignadas,
            AVG(CASE WHEN n.dt_conexion_Med IS NOT NULL THEN CONVERT(FLOAT,ABS(DATEDIFF(second,n.dt_fecha_registro,n.dt_conexion_Med))) END) espera_promedio_segundos,
            AVG(CASE WHEN n.dt_conexion_Med IS NOT NULL AND {$final} IS NOT NULL THEN CONVERT(FLOAT,ABS(DATEDIFF(second,n.dt_conexion_Med,{$final}))) END) duracion_promedio_segundos,
            MAX(n.dt_fecha_registro) ultima_llamada
            FROM {$tabla} n WHERE {$base} GROUP BY n.id_medico";
        $stmt = $legacy->prepare($sqlMedicos);
        $stmt->execute($paramsLegacy);
        $porMedico = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $porMedico[(int) $row['id_medico']] = $row;
        }
        foreach ($respuesta['medicos'] as &$persona) {
            $m = $porMedico[(int) ($persona['id_medico_legacy'] ?? 0)] ?? null;
            if (!$m) continue;
            foreach (['total', 'atendidas', 'perdidas', 'reasignadas'] as $key) $persona[$key] = (int) $m[$key];
            $persona['tasa_atencion'] = $persona['total'] ? round($persona['atendidas'] * 100 / $persona['total'], 1) : 0;
            $persona['espera_promedio_segundos'] = $m['espera_promedio_segundos'] !== null ? (int) round((float) $m['espera_promedio_segundos']) : null;
            $persona['duracion_promedio_segundos'] = $m['duracion_promedio_segundos'] !== null ? (int) round((float) $m['duracion_promedio_segundos']) : null;
            $persona['ultima_llamada'] = $m['ultima_llamada'];
        }
        unset($persona);

        foreach ($respuesta['medicos'] as $persona) {
            foreach (['total', 'atendidas', 'perdidas', 'reasignadas'] as $key) $respuesta['stats'][$key] += (int) $persona[$key];
        }
        $respuesta['stats']['tasa_atencion'] = $respuesta['stats']['total'] ? round($respuesta['stats']['atendidas'] * 100 / $respuesta['stats']['total'], 1) : 0;

        $sqlPromedios = "SELECT
            AVG(CASE WHEN n.dt_conexion_Med IS NOT NULL THEN CONVERT(FLOAT,ABS(DATEDIFF(second,n.dt_fecha_registro,n.dt_conexion_Med))) END) espera,
            AVG(CASE WHEN n.dt_conexion_Med IS NOT NULL AND {$final} IS NOT NULL THEN CONVERT(FLOAT,ABS(DATEDIFF(second,n.dt_conexion_Med,{$final}))) END) duracion
            FROM {$tabla} n WHERE {$base}";
        $stmt = $legacy->prepare($sqlPromedios);
        $stmt->execute($paramsLegacy);
        $promedios = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $respuesta['stats']['espera_promedio_segundos'] = isset($promedios['espera']) ? (int) round((float) $promedios['espera']) : null;
        $respuesta['stats']['duracion_promedio_segundos'] = isset($promedios['duracion']) ? (int) round((float) $promedios['duracion']) : null;

        $sqlTendencia = "SELECT CONVERT(date,n.dt_fecha_registro) fecha,COUNT(*) total,
            SUM(CASE WHEN {$final} IS NOT NULL THEN 1 ELSE 0 END) atendidas,
            SUM(CASE WHEN {$final} IS NULL THEN 1 ELSE 0 END) perdidas
            FROM {$tabla} n WHERE {$base} GROUP BY CONVERT(date,n.dt_fecha_registro) ORDER BY fecha";
        $stmt = $legacy->prepare($sqlTendencia);
        $stmt->execute($paramsLegacy);
        $respuesta['tendencia'] = array_map(static function (array $r): array {
            return ['fecha' => $r['fecha'], 'total' => (int) $r['total'], 'atendidas' => (int) $r['atendidas'], 'perdidas' => (int) $r['perdidas']];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        $sqlLlamadas = "SELECT TOP 80 n.id_medico,n.dt_fecha_registro,n.st_dispositivo,n.i_reasignacion,
            CASE WHEN {$final} IS NOT NULL THEN 'atendida' ELSE 'perdida' END estado,
            CASE WHEN n.dt_conexion_Med IS NOT NULL THEN ABS(DATEDIFF(second,n.dt_fecha_registro,n.dt_conexion_Med)) END espera_segundos,
            CASE WHEN n.dt_conexion_Med IS NOT NULL AND {$final} IS NOT NULL THEN ABS(DATEDIFF(second,n.dt_conexion_Med,{$final})) END duracion_segundos
            FROM {$tabla} n WHERE {$base} ORDER BY n.dt_fecha_registro DESC";
        $stmt = $legacy->prepare($sqlLlamadas);
        $stmt->execute($paramsLegacy);
        $nombres = [];
        foreach ($personal as $p) $nombres[(int) ($p['id_medico_legacy'] ?? 0)] = trim($p['nombre'] . ' ' . $p['apellido']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $respuesta['llamadas'][] = [
                'medico' => $nombres[(int) $row['id_medico']] ?? 'Profesional sin sincronizar',
                'fecha' => $row['dt_fecha_registro'],
                'origen' => (string) ($row['st_dispositivo'] ?? 'Sin identificar'),
                'estado' => $row['estado'],
                'reasignada' => in_array((int) $row['i_reasignacion'], [1, 2], true),
                'espera_segundos' => $row['espera_segundos'] !== null ? (int) $row['espera_segundos'] : null,
                'duracion_segundos' => $row['duracion_segundos'] !== null ? (int) $row['duracion_segundos'] : null,
            ];
        }

        echo json_encode(['ok' => true, 'data' => $respuesta, 'legacy' => [
            'available' => true,
            'precision' => ($dispositivos || !empty($seleccion['id_sucursal_externa'])) ? 'mosi' : 'personal',
            'message' => ($dispositivos || !empty($seleccion['id_sucursal_externa']))
                ? 'Las llamadas se filtraron por el personal y los identificadores vinculados a esta MOSI.'
                : 'No hay identificadores de origen vinculados; las métricas incluyen la actividad del personal asignado en el periodo.',
        ]], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $legacyError) {
        echo json_encode(['ok' => true, 'data' => $respuesta, 'legacy' => [
            'available' => false,
            'precision' => 'unavailable',
            'message' => 'El inventario está disponible, pero no fue posible consultar Mosy.SYBI. Revisa TELEMEDICINA_DB_* y las tablas heredadas.',
        ]], JSON_UNESCAPED_UNICODE);
    }
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => Security::publicError($e, 'mosi_operacion')], JSON_UNESCAPED_UNICODE);
}
