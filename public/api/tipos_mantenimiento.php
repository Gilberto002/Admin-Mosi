<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Auth\Auth;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Connection::get();
    $stmt = $pdo->query(
        "SELECT id_tipo_mantenimiento, nombre, descripcion
         FROM vendingm.tipos_mantenimiento
         ORDER BY id_tipo_mantenimiento"
    );
    echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')]);
}
