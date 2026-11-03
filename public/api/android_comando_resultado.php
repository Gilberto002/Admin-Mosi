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

    $idComando = filter_var($body['id_comando'] ?? null, FILTER_VALIDATE_INT);
    if (!$idComando) {
        AndroidApi::responder(['ok' => false, 'error' => 'Falta id_comando'], 422);
    }

    $estado = strtolower(trim((string) ($body['estado'] ?? 'completado')));
    $resultado = [
        'estado' => $estado,
        'mensaje' => trim((string) ($body['mensaje'] ?? '')) ?: null,
        'payload' => $body['payload'] ?? null,
        'version_code' => $body['version_code'] ?? $body['version_code_actual'] ?? null,
        'version_name' => $body['version_name'] ?? $body['version_name_actual'] ?? null,
        'fecha_cliente' => $body['fecha_cliente'] ?? null,
    ];

    $actualizado = AppActualizaciones::resolverComando(
        $pdo,
        (int) $maquina['id_maquina'],
        (int) $idComando,
        $estado,
        $resultado
    );

    AndroidApi::responder([
        'ok' => true,
        'data' => [
            'id_comando' => (int) $idComando,
            'actualizado' => $actualizado,
            'server_time' => date(DATE_ATOM),
        ],
    ]);
} catch (\Throwable $e) {
    AndroidApi::responder(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], 500);
}
