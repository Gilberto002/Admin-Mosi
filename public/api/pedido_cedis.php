<?php
// public/api/pedido_cedis.php
// Convierte Solicitudes de pedido (vendingm.solicitudes_pedido) pendientes
// en un pedido real dentro del sistema de CEDIS que ya usa el resto de
// medical-life (tbl_PedidosCedis1 / tbl_PedidosCedis1Detalle -- ver
// system/Cedis/Pedidos/class.Pedidos.php::crearPedidoSemanal(), el
// generador semanal real). No se construye un CEDIS nuevo: se inserta con
// el mismo estatus (9) y las mismas columnas que ya usa el flujo normal,
// para que el personal de almacén lo vea con sus propias herramientas
// (system/Cedis/Pedidos/catPedidos.php).
//
// Cada máquina siempre está anclada a una sucursal real
// (vendingm.maquinas.id_sucursal_clinica) -- decisión de arquitectura del
// 2026-08-24, ver memoria del proyecto. Si una máquina todavía no tiene
// sucursal vinculada, esa línea se rechaza con un mensaje claro en vez de
// inventar una sucursal placeholder.
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;
use App\Security\Security;
use App\Vending\PedidoCedisService;
use App\Vending\SolicitudPedidoGenerador;

// Mismos headers de no-cache que usa pedido.php (el generador real) -- una
// respuesta de "pedido creado" nunca debe servirse desde caché si el
// navegador repite la llamada.
header('Expires: Sat, 01 Jan 2000 00:00:01 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

// Igual que pedido.php: margen amplio por si se envían muchas líneas/
// sucursales de golpe (aunque aquí no se ejecuta ningún EXEC sp_ pesado
// como en el generador real, así que en la práctica debería terminar
// mucho antes de llegar a este límite).
ini_set('max_execution_time', 3800);

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

// ID_OPERADOR_VENDING / ID_STATUS_PEDIDO_NUEVO viven ahora en
// App\Vending\PedidoCedisService (compartida con solicitud_pedido_manual.php).

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido, use POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$lineasEntrada = is_array($body['lineas'] ?? null) ? $body['lineas'] : [];
if (!$lineasEntrada) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'No se recibieron líneas'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Connection::get();

    // ---- 1) Validar y resolver cada línea individualmente ----
    $resultados = [];   // id_solicitud => ['ok' => bool, 'error'|'folio' => ...]
    $validas = [];      // id_solicitud => ['id_sucursal' => int, 'id_producto_almacen' => int, 'cantidad' => int]

    foreach ($lineasEntrada as $linea) {
        $idSolicitud = filter_var($linea['id_solicitud'] ?? null, FILTER_VALIDATE_INT);
        $cantidad = filter_var($linea['cantidad'] ?? null, FILTER_VALIDATE_INT);

        if (!$idSolicitud) {
            continue;
        }
        if (!$cantidad || $cantidad <= 0) {
            $resultados[$idSolicitud] = ['ok' => false, 'error' => 'Cantidad inválida'];
            continue;
        }

        $stmt = $pdo->prepare(
            "SELECT sp.id_solicitud, sp.id_estatus, m.id_sucursal_clinica, p.codigo_barras, p.nombre AS producto_nombre
             FROM vendingm.solicitudes_pedido sp
             INNER JOIN vendingm.maquinas m ON m.id_maquina = sp.id_maquina
             LEFT JOIN vendingm.productos p ON p.id_producto = sp.id_producto
             WHERE sp.id_solicitud = :id"
        );
        $stmt->execute(['id' => $idSolicitud]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fila) {
            $resultados[$idSolicitud] = ['ok' => false, 'error' => 'Solicitud no encontrada'];
            continue;
        }
        if ((int) $fila['id_estatus'] !== SolicitudPedidoGenerador::ESTATUS_PENDIENTE) {
            $resultados[$idSolicitud] = ['ok' => false, 'error' => 'La solicitud ya no está pendiente'];
            continue;
        }
        if (!$fila['id_sucursal_clinica']) {
            $resultados[$idSolicitud] = ['ok' => false, 'error' => 'Esta máquina no tiene sucursal vinculada (asígnala primero en Máquinas)'];
            continue;
        }
        $codigoBarras = trim((string) ($fila['codigo_barras'] ?? ''));
        if ($codigoBarras === '') {
            $resultados[$idSolicitud] = ['ok' => false, 'error' => 'El producto de esta solicitud no tiene código de barras registrado'];
            continue;
        }

        $stmtProd = $pdo->prepare('SELECT id_ProductoAlmacen FROM cat_ProductosAlmacenMaster WHERE id_UPC = :upc');
        $stmtProd->execute(['upc' => $codigoBarras]);
        $idProductoAlmacen = $stmtProd->fetchColumn();

        if (!$idProductoAlmacen) {
            $resultados[$idSolicitud] = ['ok' => false, 'error' => "No se encontró en el catálogo de almacén (código {$codigoBarras})"];
            continue;
        }

        $validas[$idSolicitud] = [
            'id_sucursal' => (int) $fila['id_sucursal_clinica'],
            'id_producto_almacen' => (int) $idProductoAlmacen,
            'cantidad' => $cantidad,
        ];
    }

    // ---- 2) Agrupar las líneas válidas por sucursal y crear un pedido por grupo ----
    $porSucursal = [];
    foreach ($validas as $idSolicitud => $datos) {
        $porSucursal[$datos['id_sucursal']][$idSolicitud] = $datos;
    }

    foreach ($porSucursal as $idSucursal => $lineasSucursal) {
        try {
            // crearPedido() ya actualiza vendingm.solicitudes_pedido a
            // ENVIADO dentro de su propia transacción -- ver PedidoCedisService.
            $resultado = PedidoCedisService::crearPedido($pdo, $idSucursal, $lineasSucursal);
            foreach ($resultado['ids'] as $idSolicitud) {
                $resultados[$idSolicitud] = ['ok' => true, 'folio' => $resultado['folio']];
            }
        } catch (\Throwable $e) {
            foreach (array_keys($lineasSucursal) as $idSolicitud) {
                $resultados[$idSolicitud] = ['ok' => false, 'error' => 'No se pudo crear el pedido: ' . $e->getMessage()];
            }
        }
    }

    // JSON_FORCE_OBJECT: $resultados está indexado por id_solicitud (no
    // secuencial desde 0) casi siempre, pero si por coincidencia los ids
    // seleccionados fueran 0,1,2... PHP lo codificaría como array JSON en
    // vez de objeto, y el front (que hace json.data[id]) espera objeto.
    echo json_encode(['ok' => true, 'data' => $resultados], JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
} catch (\Throwable $e) {
    Security::logException($e, 'pedido_cedis');
    JsonResponse::error('No se pudo procesar el envío a CEDIS.', 500);
}
