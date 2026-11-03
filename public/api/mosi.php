<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Security\Security;
use App\Telemedicina\Schema;
use App\Telemedicina\MosiApi;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Connection::get();
    MosiApi::asegurarTablas($pdo);

    $idMosi = filter_input(INPUT_GET, 'id_mosi', FILTER_VALIDATE_INT) ?: null;
    $q = trim((string) ($_GET['q'] ?? ''));
    $conexion = trim((string) ($_GET['conexion'] ?? ''));
    $idGrupo = filter_input(INPUT_GET, 'id_grupo', FILTER_VALIDATE_INT) ?: null;
    $page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
    $pageSize = min(100, max(1, filter_input(INPUT_GET, 'page_size', FILTER_VALIDATE_INT) ?: 24));
    $offset = ($page - 1) * $pageSize;

    $params = [];
    $condiciones = ["t.estatus <> 'baja'"];
    if ($idMosi) {
        $condiciones[] = 't.id_mosi = :id_mosi';
        $params['id_mosi'] = $idMosi;
    }
    if ($q !== '') {
        $condiciones[] = '(t.numero_serie LIKE :q1 OR t.nombre LIKE :q2 OR t.ubicacion LIKE :q3 OR g.nombre LIKE :q4 OR EXISTS (
            SELECT 1 FROM vendingm.telemedicina_mosi_maquinas bx
            INNER JOIN vendingm.maquinas mx ON mx.id_maquina = bx.id_maquina
            WHERE bx.id_mosi = t.id_mosi AND (mx.numero_serie LIKE :q5 OR mx.nombre_maquina LIKE :q6)
        ))';
        for ($i = 1; $i <= 6; $i++) {
            $params['q' . $i] = "%{$q}%";
        }
    }
    if ($idGrupo) {
        $condiciones[] = 't.id_grupo = :id_grupo';
        $params['id_grupo'] = $idGrupo;
    }
    if ($conexion === 'online') {
        $condiciones[] = 't.ultima_conexion >= DATEADD(minute, -' . Schema::MINUTOS_ONLINE . ', SYSDATETIME())';
    } elseif ($conexion === 'offline') {
        $condiciones[] = 't.ultima_conexion IS NOT NULL AND t.ultima_conexion < DATEADD(minute, -' . Schema::MINUTOS_ONLINE . ', SYSDATETIME())';
    } elseif ($conexion === 'nunca') {
        $condiciones[] = 't.ultima_conexion IS NULL';
    }

    $scopeParams = [];
    $machineCondition = Auth::machineCondition('ma', $scopeParams);
    if ($machineCondition) {
        $condiciones[] = "EXISTS (
            SELECT 1 FROM vendingm.telemedicina_mosi_maquinas am
            INNER JOIN vendingm.maquinas ma ON ma.id_maquina = am.id_maquina
            WHERE am.id_mosi = t.id_mosi AND {$machineCondition}
        )";
        $params += $scopeParams;
    }
    $where = 'WHERE ' . implode(' AND ', $condiciones);

    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM vendingm.telemedicina_mosi t LEFT JOIN vendingm.telemedicina_grupos g ON g.id_grupo = t.id_grupo {$where}");
    $stmtTotal->execute($params);
    $total = (int) $stmtTotal->fetchColumn();

    $sql = "SELECT t.id_mosi, t.id_grupo, t.numero_serie, t.nombre, t.modelo, t.fabricante,
                   t.ubicacion, t.consultorio, t.ip_local, t.mac_address, t.version_software,
                   t.tipo_conexion, t.ultima_conexion, t.estatus, t.notas,
                   t.identificador_dispositivo, t.internet_disponible, t.wifi_conectado,
                   t.ethernet_conectado, t.nombre_red, t.sensores_json,
                   t.pendientes_sincronizacion, t.errores_recientes, t.ultima_medicion,
                   t.actualizacion_disponible, t.version_disponible,
                   t.version_code, t.ultima_sincronizacion, t.device_owner,
                   t.id_cliente_externo, t.cliente_nombre, t.id_sucursal_externa, t.sucursal_nombre,
                   t.id_sucursal_clinica, t.videoloop_host, t.videoloop_port, t.videoloop_email,
                   CASE WHEN t.videoloop_clave_sha256 IS NULL THEN 0 ELSE 1 END AS videoloop_clave_configurada,
                   t.impresora_ip, t.impresora_puerto, t.instalada_por, t.fecha_instalacion,
                   (SELECT TOP 1 LTRIM(RTRIM(CONCAT(u.nombre, ' ', u.apellido)))
                    FROM vendingm.usuarios u WHERE u.id_usuario=t.instalada_por) AS instalador_nombre,
                   (SELECT COUNT(*) FROM vendingm.telemedicina_mosi_api_tokens tk WHERE tk.id_mosi=t.id_mosi AND tk.revocado=0) AS tokens_activos,
                   (SELECT MAX(tk.ultimo_uso) FROM vendingm.telemedicina_mosi_api_tokens tk WHERE tk.id_mosi=t.id_mosi AND tk.revocado=0) AS ultimo_uso_token,
                   g.nombre AS grupo_nombre, g.clave AS grupo_clave,
                   CASE WHEN t.ultima_conexion IS NULL THEN 'nunca_conectada'
                        WHEN t.ultima_conexion >= DATEADD(minute, -" . Schema::MINUTOS_ONLINE . ", SYSDATETIME()) THEN 'online'
                        ELSE 'offline' END AS estado_conexion,
                   DATEDIFF(second, t.ultima_conexion, SYSDATETIME()) AS segundos_desde_conexion
            FROM vendingm.telemedicina_mosi t
            LEFT JOIN vendingm.telemedicina_grupos g ON g.id_grupo = t.id_grupo
            {$where}
            ORDER BY t.nombre, t.numero_serie
            OFFSET :offset ROWS FETCH NEXT :page_size ROWS ONLY";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
    $stmt->bindValue('page_size', $pageSize, \PDO::PARAM_INT);
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $porMosi = [];
    $ids = array_map('intval', array_column($data, 'id_mosi'));
    if ($ids) {
        $marks = implode(',', $ids);
        $linkScopeParams = [];
        $linkMachineCondition = Auth::machineCondition('m', $linkScopeParams);
        $linkScope = $linkMachineCondition ? " AND {$linkMachineCondition}" : '';
        $stmtLinks = $pdo->prepare(
            "SELECT mm.id_mosi, m.id_maquina, m.numero_serie, m.nombre_maquina, m.nombre_ubicacion,
                    m.rol_maquina
             FROM vendingm.telemedicina_mosi_maquinas mm
             INNER JOIN vendingm.maquinas m ON m.id_maquina = mm.id_maquina
             WHERE mm.id_mosi IN ({$marks}) {$linkScope}
             ORDER BY m.numero_serie"
        );
        $stmtLinks->execute($linkScopeParams);
        foreach ($stmtLinks->fetchAll(\PDO::FETCH_ASSOC) as $link) {
            $porMosi[(int) $link['id_mosi']][] = $link;
        }
    }
    foreach ($data as &$item) {
        $item['maquinas'] = $porMosi[(int) $item['id_mosi']] ?? [];
        $sensores = json_decode((string) ($item['sensores_json'] ?? ''), true);
        $item['sensores'] = is_array($sensores) ? $sensores : [];
        unset($item['sensores_json']);
        $item['eventos'] = [];
    }
    unset($item);

    if ($idMosi && $data) {
        $eventos = $pdo->prepare(
            'SELECT TOP 20 id_evento, nivel, codigo, mensaje, fecha_evento
             FROM vendingm.telemedicina_mosi_eventos
             WHERE id_mosi = :id ORDER BY fecha_evento DESC, id_evento DESC'
        );
        $eventos->execute(['id' => $idMosi]);
        $data[0]['eventos'] = $eventos->fetchAll(PDO::FETCH_ASSOC);
        $historial = $pdo->prepare(
            'SELECT TOP 30 id_telemetria, request_id, version_code, version_name, tipo_conexion,
                    internet_disponible, pendientes_sincronizacion, errores_recientes,
                    ultima_sincronizacion, fecha_recepcion
             FROM vendingm.telemedicina_mosi_telemetria
             WHERE id_mosi=:id ORDER BY fecha_recepcion DESC, id_telemetria DESC'
        );
        $historial->execute(['id' => $idMosi]);
        $data[0]['historial_telemetria'] = $historial->fetchAll(PDO::FETCH_ASSOC);
    }

    $grupos = $pdo->query("SELECT id_grupo, clave, nombre, descripcion, responsable FROM vendingm.telemedicina_grupos WHERE activo = 1 ORDER BY nombre")
        ->fetchAll(PDO::FETCH_ASSOC);

    $maquinas = [];
    if (Auth::isAdmin() && !Auth::isUsuarioMaquinasDashboard()) {
        $maquinas = $pdo->query(
            "SELECT id_maquina, numero_serie, nombre_maquina, nombre_ubicacion, rol_maquina
             FROM vendingm.maquinas WHERE id_estatus <> 3 ORDER BY numero_serie"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    $statsWhere = $machineCondition
        ? "WHERE EXISTS (SELECT 1 FROM vendingm.telemedicina_mosi_maquinas am INNER JOIN vendingm.maquinas ma ON ma.id_maquina = am.id_maquina WHERE am.id_mosi = t.id_mosi AND {$machineCondition})"
        : '';
    $stmtStats = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN t.ultima_conexion >= DATEADD(minute, -" . Schema::MINUTOS_ONLINE . ", SYSDATETIME()) THEN 1 ELSE 0 END) AS online,
                SUM(CASE WHEN t.ultima_conexion IS NOT NULL AND t.ultima_conexion < DATEADD(minute, -" . Schema::MINUTOS_ONLINE . ", SYSDATETIME()) THEN 1 ELSE 0 END) AS offline,
                SUM(CASE WHEN t.ultima_conexion IS NULL THEN 1 ELSE 0 END) AS nunca,
                SUM(CASE WHEN t.estatus = 'mantenimiento' THEN 1 ELSE 0 END) AS mantenimiento,
                SUM(CASE WHEN t.pendientes_sincronizacion > 0 THEN t.pendientes_sincronizacion ELSE 0 END) AS pendientes_sync,
                SUM(CASE WHEN t.errores_recientes > 0 THEN t.errores_recientes ELSE 0 END) AS errores,
                SUM(CASE WHEN t.actualizacion_disponible = 1 THEN 1 ELSE 0 END) AS actualizaciones
         FROM vendingm.telemedicina_mosi t {$statsWhere} " . ($statsWhere ? "AND t.estatus <> 'baja'" : "WHERE t.estatus <> 'baja'")
    );
    $stmtStats->execute($scopeParams);
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC) ?: [];

    if ($idMosi && !$data) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'MOSI no encontrada'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'data' => $idMosi ? $data[0] : $data,
        'meta' => ['total' => $total, 'page' => $page, 'page_size' => $pageSize],
        'catalogos' => ['grupos' => $grupos, 'maquinas' => $maquinas],
        'stats' => $stats,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => Security::publicError($e, 'mosi_listado')], JSON_UNESCAPED_UNICODE);
}
