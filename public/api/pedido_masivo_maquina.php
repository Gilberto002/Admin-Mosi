<?php
// public/api/pedido_masivo_maquina.php
// "Pedido de todos los faltantes" de una sola vending, en un solo pedido
// real a CEDIS -- parte del flujo por región de Resurtir (2026-09-04).
// Reutiliza exactamente los mismos criterios de "carril en alerta" que
// disponibilidad_cercana.php (accion=bajo_stock) y vendings_region.php, y el
// mismo motor de creación de pedido que ya usan pedido_cedis.php /
// solicitud_pedido_manual.php (App\Vending\PedidoCedisService).
//
// A diferencia de pedido_cedis.php (que solo actúa sobre solicitudes que YA
// existen en vendingm.solicitudes_pedido), este endpoint primero se asegura
// de que exista una solicitud PENDIENTE por cada carril en alerta -- un
// carril puede estar bajo de stock sin que nunca se haya cruzado el umbral
// "en vivo" (ej. una carga inicial ya vino baja), así que no basta con
// esperar a que SolicitudPedidoGenerador::generarSiAplica() la cree sola.
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;
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
if (!$idMaquina) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Falta id_maquina'], JSON_UNESCAPED_UNICODE);
    exit;
}
// Mismos defaults que disponibilidad_cercana.php/vendings_region.php -- el
// pedido masivo tiene que considerar EXACTAMENTE el mismo conjunto de
// carriles que el listado que el admin está viendo en pantalla.
$capacidadCarril = max(1, filter_var($body['capacidad_carril'] ?? null, FILTER_VALIDATE_INT) ?: 10);
$umbralPorcentaje = max(1, min(100, filter_var($body['umbral_porcentaje'] ?? null, FILTER_VALIDATE_INT) ?: 50));

try {
    $pdo = Connection::get();

    if (!Auth::canAccessMachine($pdo, $idMaquina)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No tienes acceso a esta máquina'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmtMaquina = $pdo->prepare('SELECT id_sucursal_clinica FROM vendingm.maquinas WHERE id_maquina = :id');
    $stmtMaquina->execute(['id' => $idMaquina]);
    $idSucursal = $stmtMaquina->fetchColumn();
    if (!$idSucursal) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Esta máquina no tiene sucursal de farmacia vinculada (asígnala primero en Máquinas)'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $idSucursal = (int) $idSucursal;

    SolicitudPedidoGenerador::asegurarTablas($pdo);

    // Mismo criterio de "carril en alerta" que disponibilidad_cercana.php.
    $stmtCarriles = $pdo->prepare(
        "SELECT ca.id_carril, ca.numero_carril, (:capacidad - ca.cantidad_actual) AS cantidad_faltante,
                p.id_producto, p.nombre, p.codigo_barras
         FROM vendingm.carriles ca
         INNER JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
         INNER JOIN vendingm.productos p ON p.id_producto = l.id_producto
         WHERE ca.id_maquina = :id_maquina
           AND ca.numero_carril % 10 <> 0
           AND ca.cantidad_actual > 0
           AND (ca.cantidad_actual * 100.0 / :capacidad2) <= :umbral"
    );
    $stmtCarriles->execute([
        'capacidad' => $capacidadCarril,
        'capacidad2' => $capacidadCarril,
        'id_maquina' => $idMaquina,
        'umbral' => $umbralPorcentaje,
    ]);
    $carriles = $stmtCarriles->fetchAll(PDO::FETCH_ASSOC);

    if (!$carriles) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Esta máquina no tiene carriles en alerta ahora mismo.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmtSolPendiente = $pdo->prepare(
        'SELECT TOP 1 id_solicitud FROM vendingm.solicitudes_pedido
         WHERE id_carril = :carril AND id_estatus = :pendiente
         ORDER BY fecha_creacion DESC'
    );
    $stmtInsertSol = $pdo->prepare(
        'INSERT INTO vendingm.solicitudes_pedido (id_maquina, id_carril, id_producto, cantidad_detectada, id_estatus)
         OUTPUT INSERTED.id_solicitud
         VALUES (:id_maquina, :id_carril, :id_producto, :cantidad, :pendiente)'
    );
    $stmtProd = $pdo->prepare('SELECT id_ProductoAlmacen FROM cat_ProductosAlmacenMaster WHERE id_UPC = :upc');

    $lineas = [];       // id_solicitud => ['id_producto_almacen'=>int, 'cantidad'=>int]
    $omitidas = [];     // motivo por producto que no se pudo incluir

    foreach ($carriles as $c) {
        $cantidadFaltante = max(1, (int) $c['cantidad_faltante']);
        $codigoBarras = trim((string) ($c['codigo_barras'] ?? ''));
        $nombre = (string) ($c['nombre'] ?? "Carril {$c['numero_carril']}");

        if ($codigoBarras === '') {
            $omitidas[] = "{$nombre} (carril {$c['numero_carril']}): sin código de barras registrado";
            continue;
        }
        $stmtProd->execute(['upc' => $codigoBarras]);
        $idProductoAlmacen = $stmtProd->fetchColumn();
        if (!$idProductoAlmacen) {
            $omitidas[] = "{$nombre} (carril {$c['numero_carril']}): no está en el catálogo de almacén (código {$codigoBarras})";
            continue;
        }

        $stmtSolPendiente->execute(['carril' => $c['id_carril'], 'pendiente' => SolicitudPedidoGenerador::ESTATUS_PENDIENTE]);
        $idSolicitud = $stmtSolPendiente->fetchColumn();
        if (!$idSolicitud) {
            $stmtInsertSol->execute([
                'id_maquina' => $idMaquina,
                'id_carril' => $c['id_carril'],
                'id_producto' => $c['id_producto'],
                'cantidad' => $cantidadFaltante,
                'pendiente' => SolicitudPedidoGenerador::ESTATUS_PENDIENTE,
            ]);
            $idSolicitud = (int) $stmtInsertSol->fetchColumn();
        }

        $lineas[(int) $idSolicitud] = [
            'id_producto_almacen' => (int) $idProductoAlmacen,
            'cantidad' => $cantidadFaltante,
        ];
    }

    if (!$lineas) {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'error' => 'Ningún producto faltante se pudo mandar a CEDIS.',
            'meta' => ['omitidas' => $omitidas],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $resultado = PedidoCedisService::crearPedido($pdo, $idSucursal, $lineas);

    echo json_encode([
        'ok' => true,
        'data' => [
            'folio' => $resultado['folio'],
            'id_pedido_cedis1' => $resultado['id_pedido_cedis'],
            'total_lineas' => count($resultado['ids']),
        ],
        'meta' => ['omitidas' => $omitidas],
    ], JSON_UNESCAPED_UNICODE);
} catch (\RuntimeException|\InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    Security::logException($e, 'pedido_masivo_maquina');
    JsonResponse::error('No se pudo generar el pedido masivo.', 500);
}
