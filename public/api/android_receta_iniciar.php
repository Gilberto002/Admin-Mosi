<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Api\AndroidApi;
use App\Database\Connection;

AndroidApi::requirePost();
$body = AndroidApi::body();

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

    AndroidApi::validarRequestIdTipo($pdo, $requestId, 'receta_iniciar');
    $previo = AndroidApi::eventoPrevio($pdo, $requestId);
    if ($previo) {
        AndroidApi::responder($previo);
    }

    $pdo->beginTransaction();
    $folio = AndroidApi::resolverFolioReceta($pdo, $folio, true);

    $stmt = $pdo->prepare(
        "SELECT id_receta, folio, id_maquina, id_estatus, origen, fecha_expiracion
         FROM vendingm.recetas WITH (UPDLOCK, ROWLOCK)
         WHERE codigo_receta = :folio_codigo OR CONVERT(varchar(36), folio) = :folio_uuid"
    );
    $stmt->execute([
        'folio_codigo' => $folio,
        'folio_uuid' => $folio,
    ]);
    $receta = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$receta) {
        $pdo->rollBack();
        AndroidApi::responder(['ok' => false, 'error' => 'Receta no encontrada'], 404);
    }
    if ($receta['fecha_expiracion'] !== null
        && strtotime((string) $receta['fecha_expiracion']) <= time()) {
        $pdo->rollBack();
        AndroidApi::responder(['ok' => false, 'error' => 'La receta vending expiro; solicita su reimpresion'], 410);
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
    if ((int) $receta['id_estatus'] === AndroidApi::ESTATUS_ENTREGADA) {
        $pdo->rollBack();
        AndroidApi::responder(['ok' => false, 'error' => 'La receta ya fue entregada'], 409);
    }
    if ((int) $receta['id_estatus'] === AndroidApi::ESTATUS_FALLIDA) {
        $pdo->rollBack();
        AndroidApi::responder(['ok' => false, 'error' => 'La receta esta marcada como fallida'], 409);
    }

    // Se vuelve a validar inmediatamente antes de autorizar motores. La consulta
    // previa no basta porque otra operacion pudo consumir stock entre llamadas.
    $stmtSinStock = $pdo->prepare(
        "SELECT TOP 1 COALESCE(dr.id_maquina, :maquina_default) AS id_maquina,
                dr.numero_carril,
                ISNULL(dr.cantidad_solicitada, 0) - ISNULL(dr.cantidad_entregada, 0) AS cantidad_pendiente,
                ISNULL(ca.cantidad_actual, 0) AS stock_carril,
                ISNULL(ls.stock_lotes, 0) AS stock_lotes
         FROM vendingm.detalle_receta dr
         LEFT JOIN vendingm.carriles ca
           ON ca.id_maquina = COALESCE(dr.id_maquina, :maquina_default2)
          AND ca.numero_carril = dr.numero_carril
         OUTER APPLY (
             SELECT SUM(lx.cantidad_actual) AS stock_lotes
             FROM vendingm.lotes lx
             WHERE lx.id_carril = ca.id_carril
               AND lx.id_producto = dr.id_producto
         ) ls
         WHERE dr.id_receta = :id_receta
           AND (
               ISNULL(ca.cantidad_actual, 0) < ISNULL(dr.cantidad_solicitada, 0) - ISNULL(dr.cantidad_entregada, 0)
               OR ISNULL(ls.stock_lotes, 0) < ISNULL(dr.cantidad_solicitada, 0) - ISNULL(dr.cantidad_entregada, 0)
           )"
    );
    $stmtSinStock->execute([
        'maquina_default' => $receta['id_maquina'],
        'maquina_default2' => $receta['id_maquina'],
        'id_receta' => $receta['id_receta'],
    ]);
    $sinStock = $stmtSinStock->fetch(PDO::FETCH_ASSOC);
    if ($sinStock) {
        $pdo->rollBack();
        AndroidApi::responder([
            'ok' => false,
            'error' => 'Stock fisico insuficiente; no se autoriza iniciar motores',
            'data' => [
                'id_maquina' => (int) $sinStock['id_maquina'],
                'numero_carril' => (int) $sinStock['numero_carril'],
                'cantidad_pendiente' => (int) $sinStock['cantidad_pendiente'],
                'stock' => min((int) $sinStock['stock_carril'], (int) $sinStock['stock_lotes']),
            ],
        ], 409);
    }

    $upd = $pdo->prepare(
        "UPDATE vendingm.recetas
         SET id_estatus = :estatus
         WHERE id_receta = :id AND id_estatus <> :entregada"
    );
    $upd->execute([
        'estatus' => AndroidApi::ESTATUS_EN_PROCESO,
        'id' => $receta['id_receta'],
        'entregada' => AndroidApi::ESTATUS_ENTREGADA,
    ]);

    $payload = [
        'ok' => true,
        'data' => [
            'folio' => $folio,
            'id_receta' => (int) $receta['id_receta'],
            'id_estatus' => AndroidApi::ESTATUS_EN_PROCESO,
            'estatus_nombre' => 'En proceso',
        ],
        'meta' => ['request_id' => $requestId ?: null, 'duplicado' => false],
    ];

    AndroidApi::guardarEvento($pdo, $requestId, 'receta_iniciar', $payload);
    $pdo->commit();

    AndroidApi::responder($payload);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    AndroidApi::responder(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], 500);
}
