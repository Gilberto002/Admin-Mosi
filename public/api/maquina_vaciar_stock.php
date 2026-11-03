<?php
// Vacía el stock y la planimetría activa de una unidad completa
// (máquina principal + esclavas) sin borrar máquinas, carriles ni capacidad.
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Dispensacion\SalidaRegistrador;
use App\Http\JsonResponse;
use App\Security\Security;
use App\Vending\SolicitudPedidoGenerador;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');
set_time_limit(0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::error('Método no permitido.', 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

$numeroSerie = trim((string) ($body['numero_serie'] ?? ''));
$confirmado = filter_var($body['confirmar'] ?? false, FILTER_VALIDATE_BOOL);

if ($numeroSerie === '') {
    JsonResponse::error('Selecciona la máquina principal.', 422);
}
if (!$confirmado) {
    JsonResponse::error('La operación requiere confirmación explícita.', 422);
}

try {
    $pdo = Connection::get();
    SalidaRegistrador::asegurarTablas($pdo);
    SolicitudPedidoGenerador::asegurarTablas($pdo);

    $stmtMaquina = $pdo->prepare(
        'SELECT id_maquina, numero_serie, rol_maquina
         FROM vendingm.maquinas
         WHERE numero_serie = :serie AND id_estatus <> 3'
    );
    $stmtMaquina->execute(['serie' => $numeroSerie]);
    $principal = $stmtMaquina->fetch(PDO::FETCH_ASSOC);

    if (!$principal) {
        JsonResponse::error('No se encontró la máquina seleccionada.', 404);
    }
    if ((int) $principal['rol_maquina'] !== 1) {
        JsonResponse::error('Para vaciar una unidad selecciona su máquina principal.', 422);
    }

    $idPrincipal = (int) $principal['id_maquina'];
    $stmtUnidades = $pdo->prepare(
        'SELECT id_maquina, numero_serie
         FROM vendingm.maquinas
         WHERE id_maquina = :principal OR id_maquina_padre = :padre
         ORDER BY CASE WHEN id_maquina = :orden THEN 0 ELSE 1 END, id_maquina'
    );
    $stmtUnidades->execute([
        'principal' => $idPrincipal,
        'padre' => $idPrincipal,
        'orden' => $idPrincipal,
    ]);
    $unidades = $stmtUnidades->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_map(static fn (array $u): int => (int) $u['id_maquina'], $unidades);

    if (!$ids) {
        JsonResponse::error('La unidad no tiene máquinas disponibles.', 404);
    }

    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $pdo->beginTransaction();
    try {
        $stmtInconsistencia = $pdo->prepare(
            "SELECT TOP 1 ca.id_carril, ca.numero_carril, ca.cantidad_actual,
                    SUM(ISNULL(l.cantidad_actual, 0)) AS cantidad_lotes
             FROM vendingm.carriles ca WITH (UPDLOCK, ROWLOCK)
             LEFT JOIN vendingm.lotes l WITH (UPDLOCK, ROWLOCK) ON l.id_carril = ca.id_carril
             WHERE ca.id_maquina IN ({$marcadores})
             GROUP BY ca.id_carril, ca.numero_carril, ca.cantidad_actual
             HAVING ISNULL(ca.cantidad_actual, 0) <> SUM(ISNULL(l.cantidad_actual, 0))"
        );
        $stmtInconsistencia->execute($ids);
        $inconsistencia = $stmtInconsistencia->fetch(PDO::FETCH_ASSOC);
        if ($inconsistencia) {
            throw new \RuntimeException(
                "el carril {$inconsistencia['numero_carril']} reporta {$inconsistencia['cantidad_actual']} pieza(s), " .
                "pero sus lotes suman {$inconsistencia['cantidad_lotes']}"
            );
        }

        $stmtCarriles = $pdo->prepare(
            "SELECT id_carril, id_maquina, numero_carril, cantidad_actual
             FROM vendingm.carriles WITH (UPDLOCK, ROWLOCK)
             WHERE id_maquina IN ({$marcadores}) AND cantidad_actual > 0
             ORDER BY id_maquina, numero_carril"
        );
        $stmtCarriles->execute($ids);
        $carrilesConStock = $stmtCarriles->fetchAll(PDO::FETCH_ASSOC);

        $stmtTotalCarriles = $pdo->prepare(
            "SELECT COUNT(*) FROM vendingm.carriles WHERE id_maquina IN ({$marcadores})"
        );
        $stmtTotalCarriles->execute($ids);
        $carrilesLimpiados = (int) $stmtTotalCarriles->fetchColumn();

        $registrador = new SalidaRegistrador($pdo);
        $piezasRetiradas = 0;
        $movimientos = 0;
        $usuario = (string) (Auth::user()['email'] ?? 'admin-web');

        foreach ($carrilesConStock as $carril) {
            $cantidad = (int) $carril['cantidad_actual'];
            $resultado = $registrador->dispensar(
                (int) $carril['id_carril'],
                $cantidad,
                SalidaRegistrador::TIPO_MERMA,
                "Vaciado total previo a nueva planimetría; unidad {$numeroSerie}",
                $usuario
            );
            $piezasRetiradas += $cantidad;
            $movimientos += count($resultado);
        }

        // Se conserva capacidad_maxima y toda la estructura física.
        $stmtLimpiar = $pdo->prepare(
            "UPDATE vendingm.carriles
             SET cantidad_actual = 0,
                 id_lote_actual = NULL,
                 id_producto = NULL,
                 fecha_actualizacion = SYSDATETIME()
             WHERE id_maquina IN ({$marcadores})"
        );
        $stmtLimpiar->execute($ids);

        // Se cancelan solo solicitudes pendientes de la planimetría anterior.
        // Las ya enviadas a CEDIS se conservan para no ocultar pedidos reales.
        $paramsSolicitudes = array_merge([
            SolicitudPedidoGenerador::ESTATUS_CANCELADO,
            Auth::id(),
            SolicitudPedidoGenerador::ESTATUS_PENDIENTE,
        ], $ids);
        $stmtSolicitudes = $pdo->prepare(
            "UPDATE vendingm.solicitudes_pedido
             SET id_estatus = ?, fecha_resuelta = SYSDATETIME(), resuelto_por = ?,
                 notas = CONCAT(COALESCE(notas + CHAR(10), ''), 'Cancelada por vaciado total previo a nueva planimetría.')
             WHERE id_estatus = ? AND id_maquina IN ({$marcadores})"
        );
        $stmtSolicitudes->execute($paramsSolicitudes);
        $solicitudesCanceladas = $stmtSolicitudes->rowCount();

        $pdo->commit();

        JsonResponse::ok([
            'numero_serie' => $numeroSerie,
            'maquinas_afectadas' => count($ids),
            'series_afectadas' => array_values(array_column($unidades, 'numero_serie')),
            'carriles_limpiados' => $carrilesLimpiados,
            'carriles_con_stock' => count($carrilesConStock),
            'piezas_retiradas' => $piezasRetiradas,
            'movimientos_registrados' => $movimientos,
            'solicitudes_canceladas' => $solicitudesCanceladas,
        ]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
} catch (\PDOException $e) {
    Security::logException($e, 'maquina_vaciar_stock');
    JsonResponse::error('No se pudo vaciar el stock de la unidad.', 500);
} catch (\RuntimeException $e) {
    Security::logException($e, 'maquina_vaciar_stock');
    JsonResponse::error(
        'No se vació la unidad. Se detectó una inconsistencia de stock y no se aplicó ningún cambio: ' . $e->getMessage(),
        409
    );
} catch (\Throwable $e) {
    Security::logException($e, 'maquina_vaciar_stock');
    JsonResponse::error('No se pudo vaciar el stock de la unidad.', 500);
}
