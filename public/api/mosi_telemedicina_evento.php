<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Security\Security;
use App\Telemedicina\MosiApi;

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') responderEvento(405, ['ok' => false, 'error' => 'Metodo no permitido']);

$raw = (string) file_get_contents('php://input');
if (strlen($raw) > 32768) responderEvento(413, ['ok' => false, 'error' => 'Solicitud demasiado grande']);
$body = json_decode($raw, true);
if (!is_array($body)) responderEvento(400, ['ok' => false, 'error' => 'JSON invalido']);

$serie = strtoupper(trim((string) ($body['numero_serie'] ?? '')));
$requestId = textoEvento($body['request_id'] ?? null, 120);
$sesionId = textoEvento($body['sesion_id'] ?? null, 120);
$evento = strtolower(trim((string) ($body['evento'] ?? '')));
$permitidos = ['inicio', 'fin', 'cancelacion', 'error'];
if ($serie === '') responderEvento(422, ['ok' => false, 'error' => 'numero_serie es obligatorio']);
if ($requestId === null) responderEvento(422, ['ok' => false, 'error' => 'request_id es obligatorio']);
if ($sesionId === null) responderEvento(422, ['ok' => false, 'error' => 'sesion_id es obligatorio']);
if (!in_array($evento, $permitidos, true)) responderEvento(422, ['ok' => false, 'error' => 'evento no valido']);

try {
    $pdo = Connection::get();
    $mosi = MosiApi::autenticar($pdo, $serie);
    $idMosi = (int) $mosi['id_mosi'];
    $pdo->beginTransaction();

    $duplicado = $pdo->prepare('SELECT id_evento,CONVERT(NVARCHAR(33),fecha_evento,126) fecha_evento FROM vendingm.telemedicina_mosi_eventos WITH (UPDLOCK,HOLDLOCK) WHERE id_mosi=:id AND request_id=:request_id');
    $duplicado->execute(['id' => $idMosi, 'request_id' => $requestId]);
    if ($anterior = $duplicado->fetch(PDO::FETCH_ASSOC)) {
        $pdo->commit();
        responderEvento(200, ['ok' => true, 'data' => ['id_evento' => (int) $anterior['id_evento'], 'fecha_evento' => $anterior['fecha_evento'], 'duplicado' => true]]);
    }

    $config = [
        'inicio' => ['info', 'TELEMEDICINA_INICIO', 'La cabina abrio una consulta de telemedicina.'],
        'fin' => ['info', 'TELEMEDICINA_FIN', 'La cabina finalizo una consulta de telemedicina.'],
        'cancelacion' => ['warning', 'TELEMEDICINA_CANCELADA', 'La consulta de telemedicina se cerro sin finalizar normalmente.'],
        'error' => ['error', 'TELEMEDICINA_ERROR', 'La cabina reporto un error al abrir o cerrar telemedicina.'],
    ][$evento];
    $detalle = textoEvento($body['detalle'] ?? null, 300);
    $payload = json_encode([
        'sesion_id' => $sesionId,
        'detalle' => $detalle,
        'version_name' => textoEvento($body['version_name'] ?? null, 60),
        'version_code' => enteroEvento($body['version_code'] ?? null),
    ], JSON_UNESCAPED_UNICODE);
    $insertar = $pdo->prepare('INSERT INTO vendingm.telemedicina_mosi_eventos(id_mosi,nivel,codigo,mensaje,payload_json,request_id,sesion_id,tipo_evento) OUTPUT INSERTED.id_evento,CONVERT(NVARCHAR(33),INSERTED.fecha_evento,126) VALUES(:id,:nivel,:codigo,:mensaje,:payload,:request_id,:sesion_id,:tipo)');
    $insertar->execute([
        'id' => $idMosi, 'nivel' => $config[0], 'codigo' => $config[1], 'mensaje' => $config[2],
        'payload' => $payload, 'request_id' => $requestId, 'sesion_id' => $sesionId, 'tipo' => $evento,
    ]);
    $registro = $insertar->fetch(PDO::FETCH_NUM);
    $pdo->prepare('UPDATE vendingm.telemedicina_mosi SET ultima_conexion=SYSDATETIME(),fecha_actualizacion=SYSDATETIME() WHERE id_mosi=:id')->execute(['id' => $idMosi]);
    $pdo->commit();
    responderEvento(201, ['ok' => true, 'data' => ['id_evento' => (int) $registro[0], 'fecha_evento' => $registro[1], 'duplicado' => false]]);
} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    responderEvento(401, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    responderEvento(500, ['ok' => false, 'error' => Security::publicError($e, 'mosi_telemedicina_evento')]);
}

function responderEvento(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function textoEvento(mixed $valor, int $maximo): ?string
{
    $texto = trim((string) ($valor ?? ''));
    if ($texto === '') return null;
    return function_exists('mb_substr') ? mb_substr($texto, 0, $maximo) : substr($texto, 0, $maximo);
}

function enteroEvento(mixed $valor): ?int
{
    return filter_var($valor, FILTER_VALIDATE_INT) === false ? null : (int) $valor;
}
