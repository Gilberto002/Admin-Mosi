<?php
// public/api/traspaso_guardar.php
// Registra un traspaso real de inventario sucursal->maquina de vending
// (motivo 16/17), sin tocar la API de Intecco -- es el paso "ERP" puro.
// Uso normal: desde public/api/resurtir_carril.php (que orquesta Intecco +
// esto en una sola llamada de red). Este endpoint tambien queda expuesto
// por su cuenta para poder registrar/corregir un traspaso manualmente si
// alguna vez el carril en Intecco ya quedo correcto pero el movimiento del
// ERP no se registro (ver "intecco_ya_actualizado" en resurtir_carril.php).
//
// Portado de system/ML/vendingInventario.php::registrarTraspasoSucursalVending()
// (ver App\Vending\TraspasoService), con la misma logica de negocio pero con
// sentencias preparadas dentro de una transaccion real.
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;
use App\Vending\TraspasoService;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido, use POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

$idUpc = trim((string) ($body['id_upc'] ?? ''));
$cantidad = filter_var($body['cantidad'] ?? null, FILTER_VALIDATE_INT);
$idSucursalOrigen = filter_var($body['id_sucursal_origen'] ?? null, FILTER_VALIDATE_INT);

if ($idUpc === '' || !$cantidad || $cantidad <= 0 || !$idSucursalOrigen) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Faltan datos por ingresar (id_upc, cantidad, id_sucursal_origen)'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Connection::get();

    $stmtProducto = $pdo->prepare('SELECT id_ProductoAlmacen FROM cat_ProductosAlmacenMaster WHERE id_UPC = :upc');
    $stmtProducto->execute(['upc' => $idUpc]);
    $idProductoAlmacen = $stmtProducto->fetchColumn();

    if (!$idProductoAlmacen) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'No se encontró el producto en el catálogo de almacén'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $resultado = TraspasoService::registrarTraspaso($pdo, (int) $idProductoAlmacen, $cantidad, $idSucursalOrigen, (int) Auth::id());
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // registrarTraspaso() ya no lanza por existencia insuficiente -- cierra
    // el folio con nota de "incompleto" y devuelve completo:false, así que
    // aquí se reporta como un aviso, no como un error genérico.
    if (!$resultado['completo']) {
        echo json_encode([
            'ok' => true,
            'aviso' => "Traspaso incompleto: se movieron {$resultado['cantidad_movida']} de {$resultado['cantidad_solicitada']} pieza(s) (sin existencia suficiente en la sucursal).",
            'data' => $resultado,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => true, 'data' => $resultado], JSON_UNESCAPED_UNICODE);
} catch (\InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    JsonResponse::errorFromException($e, 'No se pudo registrar el traspaso.');
}
