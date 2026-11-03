<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Auth\Auth;
use App\Dispensacion\SalidaRegistrador;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

// TEMPORAL PRUEBAS: permite entregar recetas sin validar ni descontar stock.
// Cambiar a false para reactivar el descuento real por lotes/carriles.
$omitirStockTemporal = false;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

try {
    $pdo = Connection::get();
    $pdo->exec("
        IF COL_LENGTH('vendingm.detalle_receta', 'id_maquina') IS NULL
            ALTER TABLE vendingm.detalle_receta ADD id_maquina INT NULL
    ");

    $body  = json_decode(file_get_contents('php://input'), true);
    $folio = trim((string) ($body['folio'] ?? ''));
    $items = is_array($body['items'] ?? null) ? $body['items'] : [];
    // items: [{ id_maquina, numero_carril, cantidad_entregada }]

    if ($folio === '' || empty($items)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Falta folio o items']);
        exit;
    }

    $stmtR = $pdo->prepare(
        "SELECT id_receta, id_maquina, id_estatus FROM vendingm.recetas WHERE folio = :folio"
    );
    $stmtR->execute(['folio' => $folio]);
    $receta = $stmtR->fetch(PDO::FETCH_ASSOC);

    if (!$receta) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Receta no encontrada']);
        exit;
    }
    if (!Auth::canAccessMachine($pdo, (int) $receta['id_maquina'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No tienes acceso a esta maquina']);
        exit;
    }
    if ((int) $receta['id_estatus'] === 2) { // 2 = ya entregada, ajusta al id real de tu tabla estatus_receta
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Esta receta ya fue marcada como entregada']);
        exit;
    }

    $pdo->beginTransaction();

    $updDetalle = $pdo->prepare(
        "UPDATE vendingm.detalle_receta
         SET cantidad_entregada = :cant
         WHERE id_receta = :id_receta
           AND COALESCE(id_maquina, :id_maquina_receta) = :id_maquina
           AND numero_carril = :carril"
    );
    $stmtCarril = $pdo->prepare(
        "SELECT ca.id_carril,
                COALESCE(ca.id_producto, l.id_producto, dr.id_producto) AS id_producto,
                COALESCE(ca.id_lote_actual, (
                    SELECT TOP 1 lx.id_lote
                    FROM vendingm.lotes lx
                    WHERE lx.id_carril = ca.id_carril AND lx.id_producto = dr.id_producto
                    ORDER BY CASE WHEN lx.cantidad_actual > 0 THEN 0 ELSE 1 END, lx.fecha_caducidad ASC, lx.id_lote ASC
                )) AS id_lote
         FROM vendingm.detalle_receta dr
         INNER JOIN vendingm.carriles ca
            ON ca.id_maquina = COALESCE(dr.id_maquina, :id_maquina_receta)
           AND ca.numero_carril = dr.numero_carril
         LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
         WHERE dr.id_receta = :id_receta
           AND COALESCE(dr.id_maquina, :id_maquina_receta2) = :id_maquina
           AND dr.numero_carril = :carril"
    );
    $registrador = new SalidaRegistrador($pdo);
    SalidaRegistrador::asegurarTablas($pdo);
    $usuario = Auth::user()['email'] ?? 'web';
    $movimientos = [];

    foreach ($items as $it) {
        $carril = filter_var($it['numero_carril'] ?? null, FILTER_VALIDATE_INT);
        $idMaquinaItem = filter_var($it['id_maquina'] ?? null, FILTER_VALIDATE_INT) ?: (int) $receta['id_maquina'];
        $cant   = filter_var($it['cantidad_entregada'] ?? null, FILTER_VALIDATE_INT);
        if (!$carril || $cant === false || $cant === null || $cant < 0) {
            continue;
        }
        if ($cant === 0) {
            $updDetalle->execute([
                'cant' => 0,
                'id_receta' => $receta['id_receta'],
                'id_maquina_receta' => $receta['id_maquina'],
                'id_maquina' => $idMaquinaItem,
                'carril' => $carril,
            ]);
            continue;
        }

        $updDetalle->execute([
            'cant' => $cant,
            'id_receta' => $receta['id_receta'],
            'id_maquina_receta' => $receta['id_maquina'],
            'id_maquina' => $idMaquinaItem,
            'carril' => $carril,
        ]);
        if (!$omitirStockTemporal) {
            $stmtCarril->execute([
                'id_maquina_receta' => $receta['id_maquina'],
                'id_receta' => $receta['id_receta'],
                'id_maquina_receta2' => $receta['id_maquina'],
                'id_maquina' => $idMaquinaItem,
                'carril' => $carril,
            ]);
            $carrilMovimiento = $stmtCarril->fetch(PDO::FETCH_ASSOC);
            if (!$carrilMovimiento) {
                throw new \RuntimeException("Carril {$carril} no existe en la maquina {$idMaquinaItem}");
            }

            $registrador->dispensar(
                (int) $carrilMovimiento['id_carril'],
                $cant,
                SalidaRegistrador::TIPO_VENTA,
                "Receta {$folio}",
                (string) $usuario
            );
        } else {
            $stmtCarril->execute([
                'id_maquina_receta' => $receta['id_maquina'],
                'id_receta' => $receta['id_receta'],
                'id_maquina_receta2' => $receta['id_maquina'],
                'id_maquina' => $idMaquinaItem,
                'carril' => $carril,
            ]);
            $carrilMovimiento = $stmtCarril->fetch(PDO::FETCH_ASSOC);
            if (!$carrilMovimiento) {
                throw new \RuntimeException("Carril {$carril} no existe en la maquina {$idMaquinaItem}");
            }
            if (!$carrilMovimiento['id_lote']) {
                throw new \RuntimeException("Carril {$carril} no tiene lote para registrar movimiento de prueba");
            }
            $insMovimientoPrueba = $pdo->prepare(
                "INSERT INTO vendingm.movimientos_salida
                 (id_maquina, id_carril, id_producto, id_lote, cantidad, id_tipo_movimiento, usuario, observaciones)
                 OUTPUT INSERTED.id_movimiento
                 VALUES (:maquina, :carril, :producto, :lote, :cantidad, :tipo, :usuario, :observaciones)"
            );
            $insMovimientoPrueba->execute([
                'maquina' => $idMaquinaItem,
                'carril' => (int) $carrilMovimiento['id_carril'],
                'producto' => (int) $carrilMovimiento['id_producto'],
                'lote' => (int) $carrilMovimiento['id_lote'],
                'cantidad' => $cant,
                'tipo' => SalidaRegistrador::TIPO_VENTA,
                'usuario' => (string) $usuario,
                'observaciones' => "Receta {$folio} (prueba sin descuento de stock)",
            ]);
            $movimientos[] = [
                'id_movimiento' => (int) $insMovimientoPrueba->fetchColumn(),
                'id_lote' => (int) $carrilMovimiento['id_lote'],
                'cantidad' => $cant,
                'temporal_sin_descuento_stock' => true,
            ];
        }
    }

    $updReceta = $pdo->prepare(
        "UPDATE vendingm.recetas SET id_estatus = 2, fecha_entregada = SYSDATETIME() WHERE id_receta = :id"
    );
    $updReceta->execute(['id' => $receta['id_receta']]);

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'data' => ['folio' => $folio, 'id_receta' => $receta['id_receta'], 'movimientos' => $movimientos],
        'meta' => ['stock_omitido_temporalmente' => $omitirStockTemporal],
    ]);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')]);
}
