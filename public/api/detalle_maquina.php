<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Auth\Auth;
use App\Catalogo\ProductoCatalogo;
use App\Vending\SolicitudPedidoGenerador;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$idMaquina = filter_input(INPUT_GET, 'id_maquina', FILTER_VALIDATE_INT);
if (!$idMaquina) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta id_maquina']);
    exit;
}

try {
    $pdo = Connection::get();
    ProductoCatalogo::asegurarColumnas($pdo);
    SolicitudPedidoGenerador::asegurarTablas($pdo);
    if (!Auth::canAccessMachine($pdo, $idMaquina)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No tienes acceso a esta maquina']);
        exit;
    }
    $stmt = $pdo->prepare(
        "SELECT ca.numero_carril, ca.cantidad_actual, p.id_producto, p.nombre, p.codigo_barras,
                p.presentacion, p.descripcion, p.precio, p.imagen_path,
                l.numero_lote, l.fecha_caducidad,
                sol.id_solicitud, sol.fecha_creacion AS solicitud_fecha
         FROM vendingm.carriles ca
         LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
         LEFT JOIN vendingm.productos p ON p.id_producto = COALESCE(ca.id_producto, l.id_producto)
         OUTER APPLY (
             -- Solicitud ya enviada a CEDIS (con pedido real ligado) que
             -- sigue pendiente de recepcion para este carril -- se ofrece
             -- ligarla al confirmar la carga fisica abajo en
             -- guardarCarrilVendingm(). TOP 1 por si llegara a haber mas de
             -- una (no deberia, pero no se asume).
             SELECT TOP 1 sp.id_solicitud, sp.fecha_creacion
             FROM vendingm.solicitudes_pedido sp
             WHERE sp.id_carril = ca.id_carril
               AND sp.id_estatus = :estatus_enviado
               AND sp.id_pedido_cedis1 IS NOT NULL
             ORDER BY sp.fecha_creacion DESC
         ) sol
         WHERE ca.id_maquina = :id AND ca.numero_carril % 10 <> 0
         ORDER BY ca.numero_carril"
    );
    $stmt->execute(['id' => $idMaquina, 'estatus_enviado' => SolicitudPedidoGenerador::ESTATUS_ENVIADO]);
    $data = array_map(static function (array $fila): array {
        $fila['numero_carril'] = (int) $fila['numero_carril'];
        $fila['cantidad_actual'] = (int) $fila['cantidad_actual'];
        $fila['id_producto'] = $fila['id_producto'] !== null ? (int) $fila['id_producto'] : null;
        $fila['precio'] = $fila['precio'] !== null ? (float) $fila['precio'] : null;
        $fila['imagen_url'] = ProductoCatalogo::urlPublica($fila['imagen_path'] ?? null);
        $fila['id_solicitud'] = $fila['id_solicitud'] !== null ? (int) $fila['id_solicitud'] : null;
        unset($fila['imagen_path']);
        return $fila;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')]);
}
