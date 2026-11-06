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
    if (!$maquina) {
        AndroidApi::responder(['ok' => false, 'error' => 'Falta id_maquina o numero_serie'], 422);
    }

    $folio = AndroidApi::folio($body, false);
    $requestId = AndroidApi::requestId($body);
    $codigoError = trim((string) ($body['codigo_error'] ?? ''));
    $mensaje = trim((string) ($body['mensaje'] ?? '')) ?: null;
    $numeroCarril = filter_var($body['numero_carril'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $codigoBarras = trim((string) ($body['codigo_barras'] ?? '')) ?: null;
    $marcarFallida = (bool) ($body['marcar_receta_fallida'] ?? false);

    if ($codigoError === '') {
        AndroidApi::responder(['ok' => false, 'error' => 'Falta codigo_error'], 422);
    }

    AndroidApi::validarRequestIdTipo($pdo, $requestId, 'error_surtido');
    $previo = AndroidApi::eventoPrevio($pdo, $requestId);
    if ($previo) {
        AndroidApi::responder($previo);
    }

    $pdo->beginTransaction();
    if ($folio) {
        $folio = AndroidApi::resolverFolioReceta($pdo, $folio, true);
    }

    $recetaError = null;
    $totalEntregado = 0;
    if ($folio) {
        $stmtReceta = $pdo->prepare(
            "SELECT r.id_receta, r.id_maquina, r.id_estatus,
                    ISNULL((SELECT SUM(ISNULL(dr.cantidad_entregada, 0))
                            FROM vendingm.detalle_receta dr
                            WHERE dr.id_receta = r.id_receta), 0) AS total_entregado
             FROM vendingm.recetas r WITH (UPDLOCK, ROWLOCK)
             WHERE r.codigo_receta = :folio_codigo OR CONVERT(varchar(36), r.folio) = :folio_uuid"
        );
        $stmtReceta->execute(['folio_codigo' => $folio, 'folio_uuid' => $folio]);
        $recetaError = $stmtReceta->fetch(PDO::FETCH_ASSOC);
        if (!$recetaError) {
            $pdo->rollBack();
            AndroidApi::responder(['ok' => false, 'error' => 'Receta no encontrada'], 404);
        }

        if ($maquina) {
            $unidadAuth = ((int) $maquina['rol_maquina'] === 2 && $maquina['id_maquina_padre'] !== null)
                ? (int) $maquina['id_maquina_padre']
                : (int) $maquina['id_maquina'];
            $stmtUnidad = $pdo->prepare(
                "SELECT CASE WHEN rol_maquina = 2 AND id_maquina_padre IS NOT NULL THEN id_maquina_padre ELSE id_maquina END
                 FROM vendingm.maquinas WHERE id_maquina = :id"
            );
            $stmtUnidad->execute(['id' => $recetaError['id_maquina']]);
            if ($unidadAuth !== (int) $stmtUnidad->fetchColumn()) {
                $pdo->rollBack();
                AndroidApi::responder(['ok' => false, 'error' => 'La receta no pertenece a esta maquina'], 403);
            }
        }
        $totalEntregado = (int) $recetaError['total_entregado'];
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

    $recetaMarcadaFallida = false;
    if ($recetaError && $marcarFallida && $totalEntregado === 0) {
        $upd = $pdo->prepare(
            "UPDATE vendingm.recetas
             SET id_estatus = :fallida
             WHERE id_receta = :id_receta AND id_estatus <> :entregada"
        );
        $upd->execute([
            'fallida' => AndroidApi::ESTATUS_FALLIDA,
            'id_receta' => $recetaError['id_receta'],
            'entregada' => AndroidApi::ESTATUS_ENTREGADA,
        ]);
        $recetaMarcadaFallida = $upd->rowCount() > 0;
    }

    $payload = [
        'ok' => true,
        'data' => [
            'id_error' => $idError,
            'folio' => $folio,
            'codigo_error' => $codigoError,
            'total_entregado' => $totalEntregado,
            'receta_marcada_fallida' => $recetaMarcadaFallida,
            'accion_requerida' => $marcarFallida && $totalEntregado > 0
                ? 'Enviar android_receta_finalizar.php con la cantidad acumulada de giros completados'
                : null,
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
