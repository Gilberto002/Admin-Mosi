<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Api\AndroidApi;
use App\Database\Connection;

AndroidApi::requirePost();
$body = AndroidApi::body();

try {
    $pdo = Connection::get();
    $maquina = AndroidApi::autenticar($pdo, $body);
    $folio = AndroidApi::folio($body);
    $requestId = trim((string) ($body['request_id'] ?? ''));

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
    if (($receta['origen'] ?? null) === 'medicalcenter'
        && $receta['fecha_expiracion'] !== null
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
