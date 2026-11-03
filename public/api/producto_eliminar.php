<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Auth\Auth;
use App\Http\JsonResponse;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido, use POST']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

$idProducto = filter_var($body['id_producto'] ?? null, FILTER_VALIDATE_INT);
if (!$idProducto) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta id_producto']);
    exit;
}

try {
    $pdo = Connection::get();

    $upd = $pdo->prepare(
        'UPDATE vendingm.productos SET activo = 0, fecha_actualizacion = SYSDATETIME() WHERE id_producto = :id'
    );
    $upd->execute(['id' => $idProducto]);

    if ($upd->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Producto no encontrado']);
        exit;
    }

    echo json_encode(['ok' => true, 'data' => ['id_producto' => $idProducto]]);
} catch (\Throwable $e) {
    JsonResponse::serverError($e, 'producto_eliminar');
}
