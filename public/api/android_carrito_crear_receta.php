<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Api\AndroidApi;
use App\Catalogo\ProductoCatalogo;
use App\Database\Connection;
use App\Vending\RecetaFolio;

AndroidApi::requirePost();
$body = AndroidApi::body();

try {
    $pdo = Connection::get();
    ProductoCatalogo::asegurarColumnas($pdo);
    $pdo->exec("
        IF COL_LENGTH('vendingm.detalle_receta', 'id_maquina') IS NULL
            ALTER TABLE vendingm.detalle_receta ADD id_maquina INT NULL
    ");
    $authBody = $body;
    if (!empty($body['id_maquina_auth']) || !empty($body['numero_serie_auth'])) {
        $authBody['id_maquina'] = $body['id_maquina_auth'] ?? null;
        $authBody['numero_serie'] = $body['numero_serie_auth'] ?? '';
    }
    $maquina = AndroidApi::autenticar($pdo, $authBody);

    $requestId = trim((string) ($body['request_id'] ?? ''));
    if ($requestId !== '') {
        $previo = AndroidApi::eventoPrevio($pdo, $requestId);
        if ($previo) {
            AndroidApi::responder($previo);
        }
    }

    $idMaquina = filter_var($body['id_maquina'] ?? null, FILTER_VALIDATE_INT) ?: (int) ($maquina['id_maquina'] ?? 0);
    $numeroSerie = trim((string) ($body['numero_serie'] ?? ''));
    $items = is_array($body['items'] ?? null) ? $body['items'] : [];
    $nombrePaciente = trim((string) ($body['nombre_paciente'] ?? '')) ?: null;
    $medicoSolicita = trim((string) ($body['medico_solicita'] ?? '')) ?: null;

    if (!$idMaquina || !$items) {
        AndroidApi::responder(['ok' => false, 'error' => 'Falta id_maquina o items'], 422);
    }
    $stmtTarget = $pdo->prepare(
        "SELECT id_maquina, numero_serie, rol_maquina, id_maquina_padre
         FROM vendingm.maquinas
         WHERE id_maquina = :id"
    );
    $stmtTarget->execute(['id' => $idMaquina]);
    $maquinaTarget = $stmtTarget->fetch(PDO::FETCH_ASSOC);
    if (!$maquinaTarget) {
        AndroidApi::responder(['ok' => false, 'error' => 'Maquina no encontrada'], 404);
    }

    $unidadTarget = ((int) $maquinaTarget['rol_maquina'] === 2 && $maquinaTarget['id_maquina_padre'] !== null)
        ? (int) $maquinaTarget['id_maquina_padre']
        : (int) $maquinaTarget['id_maquina'];
    if ($maquina) {
        $unidadAuth = ((int) $maquina['rol_maquina'] === 2 && $maquina['id_maquina_padre'] !== null)
            ? (int) $maquina['id_maquina_padre']
            : (int) $maquina['id_maquina'];
        if ($unidadAuth !== $unidadTarget) {
            AndroidApi::responder(['ok' => false, 'error' => 'El token no pertenece a esta unidad'], 403);
        }
    }
    if ($numeroSerie !== '' && strcasecmp((string) $maquinaTarget['numero_serie'], $numeroSerie) !== 0) {
        AndroidApi::responder(['ok' => false, 'error' => 'numero_serie no coincide con la maquina destino'], 403);
    }

    $itemsValidados = [];
    foreach ($items as $item) {
        $idProducto = filter_var($item['id_producto'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $idMaquinaItem = filter_var($item['id_maquina'] ?? null, FILTER_VALIDATE_INT) ?: $idMaquina;
        $numeroCarril = filter_var($item['numero_carril'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $cantidad = filter_var($item['cantidad'] ?? null, FILTER_VALIDATE_INT);

        if (!$idProducto || !$idMaquinaItem || !$numeroCarril || $cantidad === false || $cantidad <= 0) {
            AndroidApi::responder(['ok' => false, 'error' => 'Item invalido'], 422);
        }

        $stmtCarril = $pdo->prepare(
            "SELECT ca.cantidad_actual, l.id_producto, p.nombre
             FROM vendingm.carriles ca
             INNER JOIN vendingm.maquinas m ON m.id_maquina = ca.id_maquina
             INNER JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
             INNER JOIN vendingm.productos p ON p.id_producto = l.id_producto
             WHERE ca.id_maquina = :id_maquina
               AND (m.id_maquina = :id_unidad OR m.id_maquina_padre = :id_unidad_padre)
               AND ca.numero_carril = :numero_carril
               AND l.id_producto = :id_producto
               AND ISNULL(p.activo, 1) = 1"
        );
        $stmtCarril->execute([
            'id_maquina' => $idMaquinaItem,
            'id_unidad' => $unidadTarget,
            'id_unidad_padre' => $unidadTarget,
            'numero_carril' => $numeroCarril,
            'id_producto' => $idProducto,
        ]);
        $fila = $stmtCarril->fetch(PDO::FETCH_ASSOC);
        if (!$fila) {
            AndroidApi::responder(['ok' => false, 'error' => "Producto no disponible en carril {$numeroCarril}"], 422);
        }
        if ((int) $fila['cantidad_actual'] < (int) $cantidad) {
            AndroidApi::responder(['ok' => false, 'error' => "Stock insuficiente en carril {$numeroCarril}"], 422);
        }

        $itemsValidados[] = [
            'id_maquina' => (int) $idMaquinaItem,
            'numero_carril' => (int) $numeroCarril,
            'id_producto' => (int) $idProducto,
            'cantidad' => (int) $cantidad,
        ];
    }

    $pdo->beginTransaction();
    $folioNuevo = RecetaFolio::siguiente($pdo);
    $ins = $pdo->prepare(
        "INSERT INTO vendingm.recetas (folio, codigo_receta, tipo_receta, id_maquina, nombre_paciente, medico_solicita, id_estatus, fecha_creacion)
         OUTPUT INSERTED.id_receta, INSERTED.codigo_receta AS folio
         VALUES (NEWID(), :codigo_receta, 'cliente_carrito_android', :id_maquina, :paciente, :medico, 1, SYSDATETIME())"
    );
    $ins->execute([
        'codigo_receta' => $folioNuevo,
        'id_maquina' => $unidadTarget,
        'paciente' => $nombrePaciente,
        'medico' => $medicoSolicita,
    ]);
    $receta = $ins->fetch(PDO::FETCH_ASSOC);

    $insDetalle = $pdo->prepare(
        "INSERT INTO vendingm.detalle_receta (id_receta, id_maquina, numero_carril, id_producto, cantidad_solicitada)
         VALUES (:id_receta, :id_maquina, :numero_carril, :id_producto, :cantidad)"
    );
    foreach ($itemsValidados as $item) {
        $insDetalle->execute([
            'id_receta' => (int) $receta['id_receta'],
            'id_maquina' => $item['id_maquina'],
            'numero_carril' => $item['numero_carril'],
            'id_producto' => $item['id_producto'],
            'cantidad' => $item['cantidad'],
        ]);
    }

    $payload = [
        'ok' => true,
        'data' => [
            'id_receta' => (int) $receta['id_receta'],
            'folio' => (string) $receta['folio'],
            'id_maquina' => $unidadTarget,
            'items' => $itemsValidados,
        ],
        'meta' => [
            'request_id' => $requestId ?: null,
            'duplicado' => false,
            'flujo' => 'receta',
        ],
    ];

    if ($requestId !== '') {
        AndroidApi::guardarEvento($pdo, $requestId, 'carrito_crear_receta', $payload);
    }

    $pdo->commit();
    AndroidApi::responder($payload);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    AndroidApi::responder(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], 500);
}
