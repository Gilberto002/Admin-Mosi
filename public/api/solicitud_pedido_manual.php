<?php
// public/api/solicitud_pedido_manual.php
// Crea/actualiza a mano una vendingm.solicitudes_pedido y la manda de una
// vez a CEDIS (App\Vending\PedidoCedisService, mismo motor que usa
// pedido_cedis.php) -- sin esperar a que SalidaRegistrador::dispensar() la
// dispare automáticamente al cruzar el umbral de stock bajo (ver
// App\Vending\SolicitudPedidoGenerador::generarSiAplica()), y sin necesitar
// el paso aparte de "Enviar a CEDIS" desde pedidos.php. Botón "Pedido" en
// Disponibilidad cercana (Resurtir): el admin ya sabe que el carril está
// bajo de stock (por eso apareció en el listado) y decide pedirlo a CEDIS
// en vez de traspasarlo desde una sucursal cercana.
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Security\Security;
use App\Vending\PedidoCedisService;
use App\Vending\SolicitudPedidoGenerador;

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

$idMaquina = filter_var($body['id_maquina'] ?? null, FILTER_VALIDATE_INT);
$numeroCarril = filter_var($body['numero_carril'] ?? null, FILTER_VALIDATE_INT);
$cantidad = filter_var($body['cantidad'] ?? null, FILTER_VALIDATE_INT);

if (!$idMaquina || !$numeroCarril || $cantidad === false || $cantidad === null || $cantidad < 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Faltan datos por ingresar o son inválidos'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Connection::get();

    if (!Auth::canAccessMachine($pdo, $idMaquina)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No tienes acceso a esta máquina'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    SolicitudPedidoGenerador::asegurarTablas($pdo);

    $stmtCarril = $pdo->prepare(
        'SELECT ca.id_carril, ca.id_producto AS id_producto_carril, l.id_producto AS id_producto_lote,
                m.id_sucursal_clinica
         FROM vendingm.carriles ca
         INNER JOIN vendingm.maquinas m ON m.id_maquina = ca.id_maquina
         LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
         WHERE ca.id_maquina = :m AND ca.numero_carril = :c'
    );
    $stmtCarril->execute(['m' => $idMaquina, 'c' => $numeroCarril]);
    $carril = $stmtCarril->fetch(PDO::FETCH_ASSOC);
    if (!$carril) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Carril no encontrado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $idProducto = $carril['id_producto_lote'] ?? $carril['id_producto_carril'] ?? null;
    $idProducto = $idProducto !== null ? (int) $idProducto : null;

    // Mismo carril que ya usa generarSiAplica() para no duplicar: en vez de
    // rechazar cuando ya hay una solicitud pendiente para este carril
    // (chocaba seguido contra la que se crea sola al dispensar), se
    // actualiza la cantidad de esa solicitud existente en vez de abrir una
    // segunda. Decisión del usuario 2026-09-02.
    $stmtExistente = $pdo->prepare(
        'SELECT id_solicitud FROM vendingm.solicitudes_pedido
         WHERE id_carril = :carril AND id_estatus = :pendiente'
    );
    $stmtExistente->execute([
        'carril' => (int) $carril['id_carril'],
        'pendiente' => SolicitudPedidoGenerador::ESTATUS_PENDIENTE,
    ]);
    $idSolicitudExistente = $stmtExistente->fetchColumn();

    if ($idSolicitudExistente !== false) {
        $idSolicitud = (int) $idSolicitudExistente;
        $stmtUpdate = $pdo->prepare(
            'UPDATE vendingm.solicitudes_pedido
             SET cantidad_detectada = :cantidad, id_producto = :id_producto
             WHERE id_solicitud = :id'
        );
        $stmtUpdate->execute([
            'cantidad' => $cantidad,
            'id_producto' => $idProducto,
            'id' => $idSolicitud,
        ]);
        $actualizada = true;
    } else {
        $stmtInsert = $pdo->prepare(
            'INSERT INTO vendingm.solicitudes_pedido
                (id_maquina, id_carril, id_producto, cantidad_detectada, id_estatus)
             OUTPUT INSERTED.id_solicitud
             VALUES (:id_maquina, :id_carril, :id_producto, :cantidad, :estatus)'
        );
        $stmtInsert->execute([
            'id_maquina' => $idMaquina,
            'id_carril' => (int) $carril['id_carril'],
            'id_producto' => $idProducto,
            'cantidad' => $cantidad,
            'estatus' => SolicitudPedidoGenerador::ESTATUS_PENDIENTE,
        ]);
        $idSolicitud = (int) $stmtInsert->fetchColumn();
        $actualizada = false;
    }

    // ---- Intentar mandarla a CEDIS de una vez (mismo motor que
    // pedido_cedis.php) -- si algo de esto falla, la solicitud ya quedó
    // creada/actualizada (PENDIENTE) y se puede reintentar después desde
    // Pedidos, "Enviar a CEDIS"; no se pierde lo que ya se hizo.
    $idSucursalClinica = $carril['id_sucursal_clinica'] !== null ? (int) $carril['id_sucursal_clinica'] : null;
    if ($idSucursalClinica === null) {
        echo json_encode([
            'ok' => true,
            'data' => ['id_solicitud' => $idSolicitud, 'actualizada' => $actualizada, 'enviada_cedis' => false],
            'meta' => ['aviso' => 'Esta máquina no tiene sucursal vinculada -- la solicitud quedó pendiente, asígnala en Máquinas y envíala desde Pedidos.'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $codigoBarras = '';
    if ($idProducto !== null) {
        $stmtCB = $pdo->prepare('SELECT codigo_barras FROM vendingm.productos WHERE id_producto = :id');
        $stmtCB->execute(['id' => $idProducto]);
        $codigoBarras = trim((string) $stmtCB->fetchColumn());
    }
    if ($codigoBarras === '') {
        echo json_encode([
            'ok' => true,
            'data' => ['id_solicitud' => $idSolicitud, 'actualizada' => $actualizada, 'enviada_cedis' => false],
            'meta' => ['aviso' => 'Este producto no tiene código de barras registrado -- la solicitud quedó pendiente, envíala desde Pedidos una vez corregido.'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmtProd = $pdo->prepare('SELECT id_ProductoAlmacen FROM cat_ProductosAlmacenMaster WHERE id_UPC = :upc');
    $stmtProd->execute(['upc' => $codigoBarras]);
    $idProductoAlmacen = $stmtProd->fetchColumn();
    if (!$idProductoAlmacen) {
        echo json_encode([
            'ok' => true,
            'data' => ['id_solicitud' => $idSolicitud, 'actualizada' => $actualizada, 'enviada_cedis' => false],
            'meta' => ['aviso' => "No se encontró en el catálogo de almacén (código {$codigoBarras}) -- la solicitud quedó pendiente, envíala desde Pedidos una vez corregido."],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $resultado = PedidoCedisService::crearPedido($pdo, $idSucursalClinica, [
            $idSolicitud => ['id_producto_almacen' => (int) $idProductoAlmacen, 'cantidad' => $cantidad],
        ]);
        echo json_encode([
            'ok' => true,
            'data' => [
                'id_solicitud' => $idSolicitud,
                'actualizada' => $actualizada,
                'enviada_cedis' => true,
                'folio' => $resultado['folio'],
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        Security::logException($e, 'solicitud_pedido_manual');
        echo json_encode([
            'ok' => true,
            'data' => ['id_solicitud' => $idSolicitud, 'actualizada' => $actualizada, 'enviada_cedis' => false],
            'meta' => ['aviso' => 'La solicitud quedó pendiente, pero no se pudo crear el pedido en CEDIS -- envíala desde Pedidos.'],
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (\Throwable $e) {
    Security::logException($e, 'solicitud_pedido_manual');
    echo json_encode(['ok' => false, 'error' => 'No se pudo crear la solicitud de pedido.'], JSON_UNESCAPED_UNICODE);
}
