<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Actualizaciones\AppActualizaciones;
use App\Api\AndroidApi;
use App\Database\Connection;
use App\Maquinas\ConexionMaquina;

AndroidApi::requirePost();
$body = AndroidApi::body();

try {
    $pdo = Connection::get();
    ConexionMaquina::asegurarColumnas($pdo);
    $maquina = AndroidApi::autenticar($pdo, $body);
    if (!$maquina) {
        AndroidApi::responder(['ok' => false, 'error' => 'Falta id_maquina o numero_serie'], 422);
    }

    $tipo = strtolower(trim((string) ($body['tipo_evento'] ?? $body['evento'] ?? '')));
    if (!in_array($tipo, [AppActualizaciones::EVENTO_DESCARGA, AppActualizaciones::EVENTO_INSTALACION, AppActualizaciones::EVENTO_ERROR], true)) {
        AndroidApi::responder(['ok' => false, 'error' => 'tipo_evento debe ser descarga, instalacion o error'], 422);
    }

    $idActualizacion = filter_var($body['id_actualizacion'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $versionCodeActual = filter_var($body['version_code_actual'] ?? $body['version_code'] ?? null, FILTER_VALIDATE_INT);
    $versionCodeActual = $versionCodeActual !== false ? (int) $versionCodeActual : null;
    $versionNameActual = trim((string) ($body['version_name_actual'] ?? $body['version_name'] ?? '')) ?: null;
    $versionCodeObjetivo = filter_var($body['version_code_objetivo'] ?? null, FILTER_VALIDATE_INT);
    $versionCodeObjetivo = $versionCodeObjetivo !== false ? (int) $versionCodeObjetivo : null;
    $codigoError = trim((string) ($body['codigo_error'] ?? '')) ?: null;
    $mensaje = trim((string) ($body['mensaje'] ?? '')) ?: null;
    $requestId = trim((string) ($body['request_id'] ?? ''));
    $canalApp = AppActualizaciones::CANAL_CLIENTES;

    $pdo->beginTransaction();
    $idEvento = AppActualizaciones::registrarEvento($pdo, [
        'id_actualizacion' => $idActualizacion,
        'canal_app' => $canalApp,
        'id_maquina' => (int) $maquina['id_maquina'],
        'numero_serie' => (string) $maquina['numero_serie'],
        'tipo_evento' => $tipo,
        'version_code_actual' => $versionCodeActual,
        'version_name_actual' => $versionNameActual,
        'version_code_objetivo' => $versionCodeObjetivo,
        'codigo_error' => $codigoError,
        'mensaje' => $mensaje,
        'request_id' => $requestId ?: null,
        'payload_json' => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);

    if ($tipo === AppActualizaciones::EVENTO_INSTALACION && $versionNameActual !== null) {
        $stmt = $pdo->prepare(
            "UPDATE vendingm.maquinas
             SET version_app = :version_app,
                 fecha_actualizacion = SYSDATETIME()
             WHERE id_maquina = :id_maquina"
        );
        $stmt->execute([
            'version_app' => $versionNameActual,
            'id_maquina' => (int) $maquina['id_maquina'],
        ]);
    }

    $pdo->commit();

    AndroidApi::responder([
        'ok' => true,
        'data' => [
            'id_evento' => $idEvento,
            'tipo_evento' => $tipo,
            'server_time' => date(DATE_ATOM),
        ],
    ]);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    AndroidApi::responder(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], 500);
}
