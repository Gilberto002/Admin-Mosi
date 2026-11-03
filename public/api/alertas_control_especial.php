<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Auth\Auth;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$dias     = filter_input(INPUT_GET, 'dias', FILTER_VALIDATE_INT);
$q        = trim((string) ($_GET['q'] ?? ''));
$page     = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
$pageSize = min(100, max(1, filter_input(INPUT_GET, 'page_size', FILTER_VALIDATE_INT) ?: 25));
$offset   = ($page - 1) * $pageSize;

$condiciones = ['l.cantidad_actual > 0', 'p.control_especial = 1'];
$params = [];

if ($dias !== null && $dias !== false) {
    $condiciones[] = 'DATEDIFF(day, CAST(SYSDATETIME() AS date), l.fecha_caducidad) <= :dias';
    $params['dias'] = $dias;
}
if ($q !== '') {
    $condiciones[] = '(p.nombre LIKE :q1 OR p.codigo_barras LIKE :q2 OR m.numero_serie LIKE :q3)';
    $params['q1'] = $params['q2'] = $params['q3'] = "%{$q}%";
}
$authCond = Auth::machineCondition('m', $params);
if ($authCond) {
    $condiciones[] = $authCond;
}
$where = 'WHERE ' . implode(' AND ', $condiciones);

$from = "FROM vendingm.lotes l
         INNER JOIN vendingm.carriles ca ON ca.id_carril = l.id_carril
         INNER JOIN vendingm.maquinas m ON m.id_maquina = ca.id_maquina
         INNER JOIN vendingm.productos p ON p.id_producto = l.id_producto
         LEFT JOIN vendingm.categorias c ON c.id_categoria = p.id_categoria";

try {
    $pdo = Connection::get();

    $stmtTotal = $pdo->prepare("SELECT COUNT(*) {$from} {$where}");
    $stmtTotal->execute($params);
    $total = (int) $stmtTotal->fetchColumn();

    $sql = "SELECT p.nombre, p.codigo_barras, c.nombre_categoria AS categoria,
                   m.id_maquina, m.numero_serie, ca.numero_carril, ca.cantidad_actual AS stock_carril,
                   l.fecha_caducidad AS proxima_caducidad,
                   DATEDIFF(day, CAST(SYSDATETIME() AS date), l.fecha_caducidad) AS dias_para_caducar
            {$from}
            {$where}
            ORDER BY l.fecha_caducidad ASC
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
