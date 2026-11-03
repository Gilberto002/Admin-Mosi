<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Auth\Auth;
use App\Dispensacion\SalidaRegistrador;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$orden = $_GET['orden'] ?? '30d';
$limit = min(100, max(1, filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 20));

$columnaOrden = match ($orden) {
    '7d'    => 'unidades_7d',
    'total' => 'unidades_total',
    default => 'unidades_30d',
};

try {
    $pdo = Connection::get();
    SalidaRegistrador::asegurarTablas($pdo);
    $params = [];
    $where = 'WHERE 1 = 1' . Auth::machineScope('mv', $params);
    $sql = "SELECT TOP (:limit)
                p.nombre, p.codigo_barras, c.nombre_categoria AS categoria,
                SUM(CASE WHEN mv.fecha_movimiento >= DATEADD(day, -7, SYSDATETIME()) THEN mv.cantidad ELSE 0 END) AS unidades_7d,
                SUM(CASE WHEN mv.fecha_movimiento >= DATEADD(day, -30, SYSDATETIME()) THEN mv.cantidad ELSE 0 END) AS unidades_30d,
                SUM(mv.cantidad) AS unidades_total,
                COUNT(DISTINCT mv.id_maquina) AS maquinas_distintas
            FROM vendingm.movimientos_salida mv
            INNER JOIN vendingm.productos p ON p.id_producto = mv.id_producto
            LEFT JOIN vendingm.categorias c ON c.id_categoria = p.id_categoria
            {$where}
            GROUP BY p.nombre, p.codigo_barras, c.nombre_categoria
            ORDER BY {$columnaOrden} DESC";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')]);
}
