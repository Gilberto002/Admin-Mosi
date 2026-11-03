<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

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

    $requestId = trim((string) ($body['request_id'] ?? ''));
    $previo = AndroidApi::eventoPrevio($pdo, $requestId);
    if ($previo) {
        AndroidApi::responder($previo);
    }

    // Se acepta el contrato v3 anidado y el formato plano anterior.
    $telemetria = isset($body['telemetria']) && is_array($body['telemetria'])
        ? $body['telemetria']
        : $body;
    $tipo = strtolower(trim((string) ($telemetria['tipo'] ?? 'telemetria')));
    $networkType = textoTelemetria($telemetria['network_type'] ?? null, 50);
    $appVersion = textoTelemetria($telemetria['app_version'] ?? null, 50);
    $batteryLevel = is_numeric($telemetria['battery_level'] ?? null)
        ? (float) $telemetria['battery_level']
        : null;
    $signalStrength = filter_var($telemetria['signal_strength'] ?? null, FILTER_VALIDATE_INT);
    $signalStrength = $signalStrength !== false ? (int) $signalStrength : null;

    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "INSERT INTO vendingm.api_telemetria_android
         (id_maquina, numero_serie, app_version, battery_level, network_type, signal_strength, payload_json)
         OUTPUT INSERTED.id_telemetria
         VALUES (:id_maquina, :numero_serie, :app_version, :battery_level, :network_type, :signal_strength, :payload_json)"
    );
    $stmt->execute([
        'id_maquina' => (int) $maquina['id_maquina'],
        'numero_serie' => (string) $maquina['numero_serie'],
        'app_version' => $appVersion,
        'battery_level' => $batteryLevel,
        'network_type' => $networkType,
        'signal_strength' => $signalStrength,
        'payload_json' => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $idTelemetria = (int) $stmt->fetchColumn();
    $ultimaConexion = ConexionMaquina::registrarLatido(
        $pdo,
        (int) $maquina['id_maquina'],
        $networkType,
        $appVersion
    );
    $pdo->commit();

    $payload = [
        'ok' => true,
        'data' => [
            'id_telemetria' => $idTelemetria,
            'tipo' => $tipo,
            'estado_conexion' => 'online',
            'ultima_conexion' => $ultimaConexion,
            'server_time' => date(DATE_ATOM),
        ],
        'meta' => ['request_id' => $requestId ?: null, 'duplicado' => false],
    ];

    AndroidApi::guardarEvento($pdo, $requestId, 'telemetria', $payload);
    AndroidApi::responder($payload);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    AndroidApi::responder(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], 500);
}

function textoTelemetria(mixed $valor, int $maximo): ?string
{
    $texto = trim((string) ($valor ?? ''));
    return $texto === '' ? null : substr($texto, 0, $maximo);
}
