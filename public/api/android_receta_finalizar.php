<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Api\AndroidApi;
use App\Database\Connection;
use App\Dispensacion\SalidaRegistrador;

AndroidApi::requirePost();
$body = AndroidApi::body();

// Produccion: cada giro de motor completado descuenta el lote/carril correspondiente.
$omitirStockTemporal = false;

try {
    $pdo = Connection::get();
    $pdo->exec("
        IF COL_LENGTH('vendingm.detalle_receta', 'id_maquina') IS NULL
            ALTER TABLE vendingm.detalle_receta ADD id_maquina INT NULL
    ");
    $maquina = AndroidApi::autenticar($pdo, $body);
    if (!$maquina) {
        AndroidApi::responder(['ok' => false, 'error' => 'Falta id_maquina o numero_serie'], 422);
    }
    $folio = AndroidApi::folio($body);
    $requestId = AndroidApi::requestId($body);
    $items = is_array($body['items'] ?? null) ? $body['items'] : [];

    if (empty($items)) {
        AndroidApi::responder(['ok' => false, 'error' => 'Falta items'], 422);
    }

    AndroidApi::validarRequestIdTipo($pdo, $requestId, 'receta_finalizar');

    // La finalizacion acumula giros de motor completados por item. Algunas versiones de la app
    // reutilizan el mismo request_id en rondas sucesivas; si devolvemos aqui
    // el evento anterior, nunca se registran las siguientes cantidades. El
    // proceso de abajo ya es idempotente porque calcula delta contra lo
    // previamente entregado en BD.

    $pdo->beginTransaction();
    $folio = AndroidApi::resolverFolioReceta($pdo, $folio, true);

    $stmtR = $pdo->prepare(
        "SELECT id_receta, folio, id_maquina, id_estatus, origen, fecha_expiracion
         FROM vendingm.recetas WITH (UPDLOCK, ROWLOCK)
         WHERE codigo_receta = :folio_codigo OR CONVERT(varchar(36), folio) = :folio_uuid"
    );
    $stmtR->execute([
        'folio_codigo' => $folio,
        'folio_uuid' => $folio,
    ]);
    $receta = $stmtR->fetch(PDO::FETCH_ASSOC);

    if (!$receta) {
        $pdo->rollBack();
        AndroidApi::responder(['ok' => false, 'error' => 'Receta no encontrada'], 404);
    }
    if ($maquina) {
        $unidadAuth = ((int) $maquina['rol_maquina'] === 2 && $maquina['id_maquina_padre'] !== null)
            ? (int) $maquina['id_maquina_padre']
            : (int) $maquina['id_maquina'];
        $stmtUnidadReceta = $pdo->prepare(
            "SELECT CASE WHEN rol_maquina = 2 AND id_maquina_padre IS NOT NULL THEN id_maquina_padre ELSE id_maquina END
             FROM vendingm.maquinas
             WHERE id_maquina = :id"
        );
        $stmtUnidadReceta->execute(['id' => $receta['id_maquina']]);
        if ($unidadAuth !== (int) $stmtUnidadReceta->fetchColumn()) {
            $pdo->rollBack();
            AndroidApi::responder(['ok' => false, 'error' => 'La receta no pertenece a esta maquina'], 403);
        }
    }
    // Permitir reintentos despues de entregada: si el payload trae las mismas
    // cantidades acumuladas, los deltas quedan en cero y no descuenta dos veces.
    if (!in_array((int) $receta['id_estatus'], [
        AndroidApi::ESTATUS_EN_PROCESO,
        AndroidApi::ESTATUS_PARCIAL,
        AndroidApi::ESTATUS_ENTREGADA,
    ], true)) {
        $pdo->rollBack();
        AndroidApi::responder(['ok' => false, 'error' => 'La receta debe iniciarse antes de finalizar el surtido'], 409);
    }

    $stmtDetalle = $pdo->prepare(
        "SELECT COALESCE(dr.id_maquina, :id_maquina) AS id_maquina,
                dr.numero_carril, dr.id_producto, dr.cantidad_solicitada, dr.cantidad_entregada
         FROM vendingm.detalle_receta dr
         WHERE dr.id_receta = :id_receta"
    );
    $stmtDetalle->execute(['id_maquina' => $receta['id_maquina'], 'id_receta' => $receta['id_receta']]);
    $detalle = [];
    $detallePorCarril = [];
    foreach ($stmtDetalle->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $idMaquinaDetalle = (int) $fila['id_maquina'];
        $numeroCarrilDetalle = (int) $fila['numero_carril'];
        $detalle[$idMaquinaDetalle . ':' . $numeroCarrilDetalle] = $fila;
        $detallePorCarril[$numeroCarrilDetalle] = ($detallePorCarril[$numeroCarrilDetalle] ?? 0) + 1;
    }

    $stmtCarril = $pdo->prepare(
        "SELECT ca.id_carril,
                COALESCE(ca.id_producto, l.id_producto, :id_producto) AS id_producto,
                COALESCE(ca.id_lote_actual, (
                    SELECT TOP 1 lx.id_lote
                    FROM vendingm.lotes lx
                    WHERE lx.id_carril = ca.id_carril AND lx.id_producto = :id_producto_lote
                    ORDER BY CASE WHEN lx.cantidad_actual > 0 THEN 0 ELSE 1 END, lx.fecha_caducidad ASC, lx.id_lote ASC
                )) AS id_lote
         FROM vendingm.carriles ca WITH (UPDLOCK, ROWLOCK)
         LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
         WHERE ca.id_maquina = :id_maquina AND ca.numero_carril = :numero_carril"
    );
    $updDetalle = $pdo->prepare(
        "UPDATE vendingm.detalle_receta
         SET cantidad_entregada = :cantidad
         WHERE id_receta = :id_receta
           AND COALESCE(id_maquina, :id_maquina_where) = :id_maquina
           AND numero_carril = :numero_carril"
    );

    $registrador = new SalidaRegistrador($pdo);
    SalidaRegistrador::asegurarTablas($pdo);
    $movimientos = [];

    foreach ($items as $item) {
        $numeroCarril = filter_var($item['numero_carril'] ?? null, FILTER_VALIDATE_INT);
        $idMaquinaEnviado = filter_var($item['id_maquina'] ?? null, FILTER_VALIDATE_INT);
        if (!$idMaquinaEnviado && (($detallePorCarril[(int) $numeroCarril] ?? 0) > 1)) {
            throw new \RuntimeException("El carril {$numeroCarril} existe en mas de una maquina de la receta; envia id_maquina");
        }
        $idMaquinaItem = $idMaquinaEnviado ?: (int) $receta['id_maquina'];
        $cantidad = filter_var($item['cantidad_entregada'] ?? $item['cantidad'] ?? null, FILTER_VALIDATE_INT);

        if (!$numeroCarril || $cantidad === false || $cantidad === null || $cantidad < 0) {
            throw new \RuntimeException('Carril o cantidad invalida en items');
        }
        $claveDetalle = $idMaquinaItem . ':' . $numeroCarril;
        if (!isset($detalle[$claveDetalle])) {
            throw new \RuntimeException("El carril {$numeroCarril} no pertenece a la receta");
        }
        $cantidadPrevia = (int) ($detalle[$claveDetalle]['cantidad_entregada'] ?? 0);
        if ($cantidad < $cantidadPrevia) {
            throw new \RuntimeException("Cantidad entregada menor a la ya registrada en carril {$numeroCarril}");
        }
        if ($cantidad > (int) $detalle[$claveDetalle]['cantidad_solicitada']) {
            throw new \RuntimeException("Cantidad entregada mayor a la solicitada en carril {$numeroCarril}");
        }
        $deltaEntregado = $cantidad - $cantidadPrevia;

        $updDetalle->execute([
            'cantidad' => $cantidad,
            'id_receta' => $receta['id_receta'],
            'id_maquina_where' => $receta['id_maquina'],
            'id_maquina' => $idMaquinaItem,
            'numero_carril' => $numeroCarril,
        ]);
        $detalle[$claveDetalle]['cantidad_entregada'] = $cantidad;

        if ($deltaEntregado === 0) {
            continue;
        }

        $stmtCarril->execute([
            'id_producto' => (int) $detalle[$claveDetalle]['id_producto'],
            'id_producto_lote' => (int) $detalle[$claveDetalle]['id_producto'],
            'id_maquina' => $idMaquinaItem,
            'numero_carril' => $numeroCarril,
        ]);
        $carrilMovimiento = $stmtCarril->fetch(PDO::FETCH_ASSOC);
        if (!$carrilMovimiento) {
            throw new \RuntimeException("Carril {$numeroCarril} no existe en la maquina");
        }

        if (!$omitirStockTemporal) {
            $movimientos[$idMaquinaItem . ':' . $numeroCarril] = $registrador->dispensar(
                (int) $carrilMovimiento['id_carril'],
                $deltaEntregado,
                SalidaRegistrador::TIPO_VENTA,
                "Receta {$folio}",
                'android'
            );
        } else {
            if (!$carrilMovimiento['id_lote']) {
                $movimientos[$idMaquinaItem . ':' . $numeroCarril] = [[
                    'id_movimiento' => null,
                    'id_lote' => null,
                    'cantidad' => $deltaEntregado,
                    'temporal_sin_descuento_stock' => true,
                    'sin_movimiento_stock' => true,
                    'aviso' => 'Carril sin lote activo; se registro la entrega de receta sin movimiento de stock',
                ]];
                continue;
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
                'cantidad' => $deltaEntregado,
                'tipo' => SalidaRegistrador::TIPO_VENTA,
                'usuario' => 'android',
                'observaciones' => "Receta {$folio} (prueba sin descuento de stock)",
            ]);
            $movimientos[$idMaquinaItem . ':' . $numeroCarril] = [[
                'id_movimiento' => (int) $insMovimientoPrueba->fetchColumn(),
                'id_lote' => (int) $carrilMovimiento['id_lote'],
                'cantidad' => $deltaEntregado,
                'temporal_sin_descuento_stock' => true,
            ]];
        }
    }

    $stmtTotales = $pdo->prepare(
        "SELECT SUM(ISNULL(cantidad_solicitada, 0)) AS total_solicitado,
                SUM(ISNULL(cantidad_entregada, 0)) AS total_entregado
         FROM vendingm.detalle_receta
         WHERE id_receta = :id_receta"
    );
    $stmtTotales->execute(['id_receta' => $receta['id_receta']]);
    $totales = $stmtTotales->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalSolicitado = (int) ($totales['total_solicitado'] ?? 0);
    $totalEntregado = (int) ($totales['total_entregado'] ?? 0);

    $estatusFinal = $totalEntregado >= $totalSolicitado
        ? AndroidApi::ESTATUS_ENTREGADA
        : AndroidApi::ESTATUS_PARCIAL;

    $updReceta = $pdo->prepare(
        "UPDATE vendingm.recetas
         SET id_estatus = :estatus,
             fecha_entregada = CASE WHEN :estatus2 = :entregada THEN SYSDATETIME() ELSE fecha_entregada END
         WHERE id_receta = :id"
    );
    $updReceta->execute([
        'estatus' => $estatusFinal,
        'estatus2' => $estatusFinal,
        'entregada' => AndroidApi::ESTATUS_ENTREGADA,
        'id' => $receta['id_receta'],
    ]);

    $payload = [
        'ok' => true,
        'data' => [
            'folio' => $folio,
            'id_receta' => (int) $receta['id_receta'],
            'id_estatus' => $estatusFinal,
            'estatus_nombre' => $estatusFinal === AndroidApi::ESTATUS_ENTREGADA ? 'Entregada' : 'Parcial',
            'total_solicitado' => $totalSolicitado,
            'total_entregado' => $totalEntregado,
            'movimientos' => $movimientos,
        ],
        'meta' => [
            'request_id' => $requestId ?: null,
            'duplicado' => false,
            'stock_omitido_temporalmente' => $omitirStockTemporal,
        ],
    ];

    AndroidApi::guardarEventoReemplazar($pdo, $requestId, 'receta_finalizar', $payload);
    $pdo->commit();

    AndroidApi::responder($payload);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    AndroidApi::responder(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], 500);
}
