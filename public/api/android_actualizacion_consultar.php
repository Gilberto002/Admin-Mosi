<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Actualizaciones\AppActualizaciones;
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

    $versionCodeActual = filter_var(
        $body['version_code_actual'] ?? $body['current_version_code'] ?? $body['version_code'] ?? 0,
        FILTER_VALIDATE_INT
    );
    $versionCodeActual = $versionCodeActual !== false ? max(0, (int) $versionCodeActual) : 0;
    $versionNameActual = trim((string) ($body['version_name_actual'] ?? $body['current_version_name'] ?? $body['version_name'] ?? '')) ?: null;
    $requestId = trim((string) ($body['request_id'] ?? ''));

    $canalApp = AppActualizaciones::CANAL_CLIENTES;
    $actualizacion = AppActualizaciones::actualizacionPara($pdo, $versionCodeActual, $canalApp);
    $ultimaActiva = $actualizacion ?: AppActualizaciones::ultimaActiva($pdo, $canalApp);
    $comandosPendientes = AppActualizaciones::comandosPendientes($pdo, (int) $maquina['id_maquina']);
    $respuesta = [
        'ok' => true,
        'data' => [
            'actualizacion_disponible' => $actualizacion !== null,
            'actualizacion' => $actualizacion,
            'ultima_activa' => $ultimaActiva,
            'comandos_pendientes' => $comandosPendientes,
            'server_time' => date(DATE_ATOM),
        ],
    ];

    AppActualizaciones::registrarEvento($pdo, [
        'id_actualizacion' => $ultimaActiva['id_actualizacion'] ?? null,
        'canal_app' => $canalApp,
        'id_maquina' => (int) $maquina['id_maquina'],
        'numero_serie' => (string) $maquina['numero_serie'],
        'tipo_evento' => AppActualizaciones::EVENTO_CONSULTA,
        'version_code_actual' => $versionCodeActual,
        'version_name_actual' => $versionNameActual,
        'version_code_objetivo' => $actualizacion['version_code'] ?? $ultimaActiva['version_code'] ?? null,
        'request_id' => $requestId ?: null,
        'payload_json' => json_encode($body, JSON_UNESCAPED_UNICODE),
        'respuesta_json' => json_encode($respuesta, JSON_UNESCAPED_UNICODE),
    ]);

    AndroidApi::responder($respuesta);
} catch (\Throwable $e) {
    AndroidApi::responder(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], 500);
}
