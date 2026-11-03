<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Api\AndroidApi;
use App\Database\Connection;
use App\Maquinas\TecnicoAndroid;
use App\Security\Security;

AndroidApi::requirePost();
Security::enforceJsonBodyLimit();
$body = AndroidApi::body();

try {
    $pdo = Connection::get();
    $tecnico = TecnicoAndroid::autenticar($pdo);
    if (!$tecnico) {
        AndroidApi::responder(['ok' => false, 'error' => 'Sesion tecnica invalida o expirada'], 401);
    }

    $accion = trim((string) ($body['accion'] ?? ''));
    if ($accion === '') {
        AndroidApi::responder(['ok' => false, 'error' => 'Falta accion'], 422);
    }

    TecnicoAndroid::registrarEvento(
        $pdo,
        (int) $tecnico['id_usuario'],
        filter_var($body['id_maquina'] ?? null, FILTER_VALIDATE_INT) ?: null,
        trim((string) ($body['numero_serie'] ?? '')) ?: null,
        $accion,
        trim((string) ($body['resultado'] ?? 'ok')) ?: 'ok',
        trim((string) ($body['mensaje'] ?? '')) ?: null,
        trim((string) ($body['app_version'] ?? '')) ?: null,
        isset($body['detalle']) && is_array($body['detalle']) ? $body['detalle'] : null
    );

    AndroidApi::responder(['ok' => true, 'data' => ['registrado' => true]]);
} catch (\Throwable $e) {
    AndroidApi::responder(['ok' => false, 'error' => Security::publicError($e, 'android_tecnico_evento')], 500);
}
