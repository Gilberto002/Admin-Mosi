<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Auth\Auth;
use App\Maquinas\ConexionMaquina;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$q        = trim((string) ($_GET['q'] ?? ''));
$estado   = $_GET['estado'] ?? '';
$page     = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
$pageSize = min(100, max(1, filter_input(INPUT_GET, 'page_size', FILTER_VALIDATE_INT) ?: 25));
$offset   = ($page - 1) * $pageSize;

try {
    $pdo = Connection::get();
    ConexionMaquina::asegurarColumnas($pdo);
    $kpiParams = [];
    $kpiWhere = 'WHERE 1 = 1' . Auth::machineScope('vw', $kpiParams);

    // ------------------------------------------------------------
    // KPIs globales de TODA la red en una sola consulta agregada.
    // No dependen de la búsqueda/paginación de la tabla de abajo:
    // siempre reflejan el estado real de la red completa, aunque
    // haya miles de máquinas.
    // ------------------------------------------------------------
    $stmtKpi = $pdo->prepare(
        "SELECT
            COUNT(*)                                              AS total_maquinas,
            SUM(CASE WHEN lotes_ya_caducados > 0 THEN 1 ELSE 0 END) AS maquinas_con_caducados,
            SUM(CASE WHEN carriles_vacios > 0 THEN 1 ELSE 0 END)    AS maquinas_con_vacios,
            SUM(lotes_ya_caducados)                                AS lotes_caducados_total,
            SUM(lotes_por_caducar_30d)                              AS lotes_por_caducar_30d_total,
            SUM(carriles_vacios)                                    AS carriles_vacios_total,
            SUM(total_carriles)                                     AS carriles_totales
         FROM vendingm.vw_resumen_maquina vw
         {$kpiWhere}"
    );
    $stmtKpi->execute($kpiParams);
    $kpis = $stmtKpi->fetch(PDO::FETCH_ASSOC);

    // ------------------------------------------------------------
    // Tabla de máquinas: paginada y filtrable, ordenada por severidad
    // (primero las que tienen caducados, luego carriles vacíos).
    // ------------------------------------------------------------
    $condiciones = [];
    $params = [];

    if ($q !== '') {
        $condiciones[] = 'vw.numero_serie LIKE :q';
        $params['q'] = "%{$q}%";
    }
    if ($estado === 'atencion') {
        $condiciones[] = '(vw.lotes_ya_caducados > 0 OR vw.carriles_vacios > 0)';
    } elseif ($estado === 'ok') {
        $condiciones[] = '(vw.lotes_ya_caducados = 0 AND vw.carriles_vacios = 0)';
    }
    $authCond = Auth::machineCondition('vw', $params);
    if ($authCond) {
        $condiciones[] = $authCond;
    }
    $where = $condiciones ? ('WHERE ' . implode(' AND ', $condiciones)) : '';

    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM vendingm.vw_resumen_maquina vw {$where}");
    $stmtTotal->execute($params);
    $total = (int) $stmtTotal->fetchColumn();

    $sql = "SELECT vw.id_maquina, vw.numero_serie, vw.rol_maquina, vw.total_carriles, vw.carriles_vacios,
                    vw.lotes_ya_caducados, vw.lotes_por_caducar_30d, vw.productos_control_especial_distintos,
                    m.ultima_conexion, m.tipo_conexion, m.version_app,
                    CASE
                        WHEN m.ultima_conexion IS NULL THEN 'nunca_conectada'
                        WHEN m.ultima_conexion >= DATEADD(minute, -10, SYSDATETIME()) THEN 'online'
                        ELSE 'offline'
                    END AS estado_conexion
             FROM vendingm.vw_resumen_maquina vw
             INNER JOIN vendingm.maquinas m ON m.id_maquina = vw.id_maquina
             {$where}
             ORDER BY vw.lotes_ya_caducados DESC, vw.carriles_vacios DESC, vw.numero_serie ASC
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
        'kpis' => $kpis,
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'meta' => ['total' => $total, 'page' => $page, 'page_size' => $pageSize],
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo cargar el resumen de máquinas.'], JSON_UNESCAPED_UNICODE);
}
