<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Dispensacion\SalidaRegistrador;
use App\Api\AndroidApi;

header('Content-Type: application/json; charset=utf-8');

function responderAndroid(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function tokenAndroid(): ?string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', (string) $auth, $m)) {
        return trim($m[1]);
    }
    return trim((string) ($headers['X-Android-Token'] ?? $headers['x-android-token'] ?? '')) ?: null;
}

function asegurarIdempotencia(PDO $pdo): void
{
    $pdo->exec("
        IF OBJECT_ID('vendingm.api_eventos_android', 'U') IS NULL
        BEGIN
            CREATE TABLE vendingm.api_eventos_android (
                id_evento INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                request_id NVARCHAR(120) NOT NULL,
                tipo NVARCHAR(40) NOT NULL,
                response_json NVARCHAR(MAX) NOT NULL,
                fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_api_android_fecha DEFAULT SYSDATETIME(),
                CONSTRAINT UQ_api_eventos_android_request UNIQUE (request_id)
            );
        END
    ");
}

function eventoPrevio(PDO $pdo, string $requestId): ?array
{
    $stmt = $pdo->prepare('SELECT response_json FROM vendingm.api_eventos_android WHERE request_id = :request_id');
    $stmt->execute(['request_id' => $requestId]);
    $json = $stmt->fetchColumn();
    if ($json === false) {
        return null;
    }
    $payload = json_decode((string) $json, true);
    return is_array($payload) ? $payload : ['ok' => true, 'data' => ['request_id' => $requestId, 'duplicado' => true]];
}

function reservarEvento(PDO $pdo, string $requestId): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO vendingm.api_eventos_android (request_id, tipo, response_json)
         VALUES (:request_id, 'dispensacion', :response_json)"
    );
    $stmt->execute([
        'request_id' => $requestId,
        'response_json' => json_encode(['ok' => false, 'error' => 'Evento en proceso'], JSON_UNESCAPED_UNICODE),
    ]);
}

function actualizarEvento(PDO $pdo, string $requestId, array $payload): void
{
    $stmt = $pdo->prepare(
        "UPDATE vendingm.api_eventos_android
         SET response_json = :response_json
         WHERE request_id = :request_id"
    );
    $stmt->execute([
        'request_id' => $requestId,
        'response_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderAndroid(['ok' => false, 'error' => 'Metodo no permitido'], 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    responderAndroid(['ok' => false, 'error' => 'JSON invalido'], 400);
}

$idMaquina = filter_var($body['id_maquina'] ?? null, FILTER_VALIDATE_INT) ?: null;
$numeroSerie = trim((string) ($body['numero_serie'] ?? ''));
$numeroCarril = filter_var($body['numero_carril'] ?? null, FILTER_VALIDATE_INT) ?: null;
$codigoBarras = trim((string) ($body['codigo_barras'] ?? ''));
$cantidad = filter_var($body['cantidad'] ?? null, FILTER_VALIDATE_INT);
$requestId = trim((string) ($body['request_id'] ?? ''));
$usuario = trim((string) ($body['usuario'] ?? 'android')) ?: 'android';
$observaciones = trim((string) ($body['observaciones'] ?? 'Dispensacion Android')) ?: 'Dispensacion Android';

if (!$idMaquina && $numeroSerie === '') {
    responderAndroid(['ok' => false, 'error' => 'Falta id_maquina o numero_serie'], 422);
}
if (!$numeroCarril && $codigoBarras === '') {
    responderAndroid(['ok' => false, 'error' => 'Falta numero_carril o codigo_barras'], 422);
}
if ($numeroCarril !== null && $numeroCarril % 10 === 0) {
    responderAndroid(['ok' => false, 'error' => 'El numero_carril no corresponde a una posicion fisica'], 422);
}
if ($cantidad === false || $cantidad === null || $cantidad <= 0) {
    responderAndroid(['ok' => false, 'error' => 'Cantidad invalida'], 422);
}

try {
    $pdo = Connection::get();
    AndroidApi::autenticar($pdo, $body);
    asegurarIdempotencia($pdo);

    if ($requestId !== '') {
        $previo = eventoPrevio($pdo, $requestId);
        if ($previo) {
            if (!isset($previo['meta']) || !is_array($previo['meta'])) {
                $previo['meta'] = [];
            }
            $previo['meta']['duplicado'] = true;
            responderAndroid($previo);
        }
    }

    if (!$idMaquina) {
        $stmtM = $pdo->prepare('SELECT id_maquina FROM vendingm.maquinas WHERE numero_serie = :serie');
        $stmtM->execute(['serie' => $numeroSerie]);
        $idMaquina = (int) $stmtM->fetchColumn();
        if (!$idMaquina) {
            responderAndroid(['ok' => false, 'error' => 'Maquina no encontrada'], 404);
        }
    }

    $usarTransaccionIdempotente = $requestId !== '';
    if ($usarTransaccionIdempotente) {
        $pdo->beginTransaction();
        reservarEvento($pdo, $requestId);
    }

    $registrador = new SalidaRegistrador($pdo);
    if ($numeroCarril) {
        $stmtCarril = $pdo->prepare(
            'SELECT id_carril FROM vendingm.carriles WHERE id_maquina = :maquina AND numero_carril = :carril'
        );
        $stmtCarril->execute(['maquina' => $idMaquina, 'carril' => $numeroCarril]);
        $idCarril = (int) $stmtCarril->fetchColumn();
        if (!$idCarril) {
            throw new \RuntimeException('Carril no encontrado');
        }
        $movimientos = $registrador->dispensar($idCarril, (int) $cantidad, SalidaRegistrador::TIPO_VENTA, $observaciones, $usuario);
    } else {
        $movimientos = $registrador->dispensarPorProducto(
            (int) $idMaquina,
            $codigoBarras,
            (int) $cantidad,
            null,
            SalidaRegistrador::TIPO_VENTA,
            $observaciones,
            $usuario
        );
    }

    $payload = [
        'ok' => true,
        'data' => [
            'id_maquina' => $idMaquina,
            'numero_carril' => $numeroCarril,
            'codigo_barras' => $codigoBarras ?: null,
            'cantidad' => (int) $cantidad,
            'movimientos' => $movimientos,
        ],
        'meta' => [
            'request_id' => $requestId ?: null,
            'duplicado' => false,
        ],
    ];

    if ($usarTransaccionIdempotente) {
        actualizarEvento($pdo, $requestId, $payload);
        $pdo->commit();
    }

    responderAndroid($payload);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($requestId !== '' && isset($pdo)) {
        $previo = eventoPrevio($pdo, $requestId);
        if ($previo && ($previo['error'] ?? '') !== 'Evento en proceso') {
            if (!isset($previo['meta']) || !is_array($previo['meta'])) {
                $previo['meta'] = [];
            }
            $previo['meta']['duplicado'] = true;
            responderAndroid($previo);
        }
    }
    responderAndroid(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], 500);
}
