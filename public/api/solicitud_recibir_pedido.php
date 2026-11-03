<?php
// public/api/solicitud_recibir_pedido.php
// Botón "Recibir pedido CEDIS" del dashboard de Pedidos (público/pedidos.php)
// -- vía de respaldo para forzar la recepción real de un pedido desde el
// panel de escritorio, sin depender de que el colaborador use la pantalla
// de Resurtir actualizada (que hace lo mismo, pero ligado a la carga física
// de un carril -- ver public/api/resurtir_vendingm.php).
//
// A diferencia de resurtir_vendingm.php, este endpoint NO toca stock de
// ningún carril: solo confirma la recepción real en CEDIS
// (App\Vending\RecepcionPedidoService) y cierra la solicitud como
// ENTREGADA. La carga física del medicamento en la máquina se sigue
// registrando aparte, en Resurtir, cuando corresponda.
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;
use App\Security\Security;
use App\Vending\PedidoCedisService;
use App\Vending\RecepcionPedidoService;
use App\Vending\SolicitudPedidoGenerador;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido, use POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$idSolicitud = filter_var($body['id_solicitud'] ?? null, FILTER_VALIDATE_INT);
if (!$idSolicitud) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Falta id_solicitud'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Connection::get();
    SolicitudPedidoGenerador::asegurarTablas($pdo);

    $stmtSolicitud = $pdo->prepare(
        'SELECT id_pedido_cedis1, id_estatus FROM vendingm.solicitudes_pedido WHERE id_solicitud = :id'
    );
    $stmtSolicitud->execute(['id' => $idSolicitud]);
    $solicitud = $stmtSolicitud->fetch(PDO::FETCH_ASSOC);

    if (!$solicitud) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Solicitud no encontrada'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ((int) $solicitud['id_estatus'] !== SolicitudPedidoGenerador::ESTATUS_ENVIADO) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Esta solicitud no está en estatus Enviado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $idPedidoCedis1 = $solicitud['id_pedido_cedis1'] !== null ? (int) $solicitud['id_pedido_cedis1'] : null;
    if (!$idPedidoCedis1) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Esta solicitud no tiene un pedido de CEDIS asociado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $resultado = RecepcionPedidoService::recibirPedido($pdo, $idPedidoCedis1, PedidoCedisService::ID_OPERADOR_VENDING);

        $pdo->prepare(
            'UPDATE vendingm.solicitudes_pedido
             SET id_estatus = :entregado, fecha_resuelta = SYSDATETIME(), resuelto_por = :usuario
             WHERE id_solicitud = :id'
        )->execute([
            'entregado' => SolicitudPedidoGenerador::ESTATUS_ENTREGADO,
            'usuario' => Auth::id(),
            'id' => $idSolicitud,
        ]);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    echo json_encode(['ok' => true, 'data' => [
        'id_solicitud' => $idSolicitud,
        'id_pedido_cedis1' => $idPedidoCedis1,
        'pedido_cerrado' => $resultado['pedido_cerrado'],
    ]], JSON_UNESCAPED_UNICODE);
} catch (\RuntimeException $e) {
    // Validación de negocio (ej. el pedido todavía no llegó a "en tránsito"
    // en CEDIS) -- mensaje claro para el admin, no un 500 genérico.
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    Security::logException($e, 'solicitud_recibir_pedido');
    JsonResponse::error('No se pudo confirmar la recepción del pedido.', 500);
}
