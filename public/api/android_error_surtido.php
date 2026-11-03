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

    $folio = AndroidApi::folio($body, false);
    $requestId = trim((string) ($body['request_id'] ?? ''));
    $codigoError = trim((string) ($body['codigo_error'] ?? ''));
    $mensaje = trim((string) ($body['mensaje'] ?? '')) ?: null;
    $numeroCarril = filter_var($body['numero_carril'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $codigoBarras = trim((string) ($body['codigo_barras'] ?? '')) ?: null;
    $marcarFallida = (bool) ($body['marcar_receta_fallida'] ?? false);

    if ($codigoError === '') {
        AndroidApi::responder(['ok' => false, 'error' => 'Falta codigo_error'], 422);
    }

    $previo = AndroidApi::eventoPrevio($pdo, $requestId);
    if ($previo) {
        AndroidApi::responder($previo);
    }

    $pdo->beginTransaction();
    if ($folio) {
        $folio = AndroidApi::resolverFolioReceta($pdo, $folio, true);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO vendingm.api_errores_surtido
         (id_maquina, folio, numero_carril, codigo_barras, codigo_error, mensaje, request_id, payload_json)
         OUTPUT INSERTED.id_error
         VALUES (:id_maquina, :folio, :numero_carril, :codigo_barras, :codigo_error, :mensaje, :request_id, :payload_json)"
    );
    $stmt->execute([
        'id_maquina' => $maquina['id_maquina'] ?? null,
        'folio' => $folio,
        'numero_carril' => $numeroCarril,
        'codigo_barras' => $codigoBarras,
        'codigo_error' => $codigoError,
        'mensaje' => $mensaje,
        'request_id' => $requestId ?: null,
        'payload_json' => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $idError = (int) $stmt->fetchColumn();

    if ($folio && $marcarFallida) {
        $upd = $pdo->prepare(
            "UPDATE vendingm.recetas
             SET id_estatus = :fallida
             WHERE (codigo_receta = :folio_codigo OR CONVERT(varchar(36), folio) = :folio_uuid)
               AND id_estatus <> :entregada"
        );
        $upd->execute([
            'fallida' => AndroidApi::ESTATUS_FALLIDA,
            'folio_codigo' => $folio,
            'folio_uuid' => $folio,
            'entregada' => AndroidApi::ESTATUS_ENTREGADA,
        ]);
    }

    $payload = [
        'ok' => true,
        'data' => [
            'id_error' => $idError,
            'folio' => $folio,
            'codigo_error' => $codigoError,
        ],
        'meta' => ['request_id' => $requestId ?: null, 'duplicado' => false],
    ];

    AndroidApi::guardarEvento($pdo, $requestId, 'error_surtido', $payload);
    $pdo->commit();

    AndroidApi::responder($payload);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    AndroidApi::responder(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], 500);
}
