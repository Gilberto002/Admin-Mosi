<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Security\Security;
use App\Telemedicina\MosiApi;

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(405, ['ok' => false, 'error' => 'Metodo no permitido']);

$raw = (string) file_get_contents('php://input');
$maxBytes = max(16384, (int) ($_ENV['MOSI_JSON_MAX_BYTES'] ?? 262144));
if (strlen($raw) > $maxBytes) responder(413, ['ok' => false, 'error' => 'La telemetria excede el tamano permitido']);
$body = json_decode($raw, true);
if (!is_array($body)) responder(400, ['ok' => false, 'error' => 'JSON invalido']);
$telemetria = is_array($body['telemetria'] ?? null) ? $body['telemetria'] : $body;
$serie = strtoupper(trim((string) ($body['numero_serie'] ?? $telemetria['numero_serie'] ?? '')));
if ($serie === '') responder(422, ['ok' => false, 'error' => 'numero_serie es obligatorio']);

try {
    $pdo = Connection::get();
    $mosi = MosiApi::autenticar($pdo, $serie);
    $idMosi = (int) $mosi['id_mosi'];
    $requestId = texto($body['request_id'] ?? $telemetria['request_id'] ?? null, 120);
    $sensores = sensoresNormalizados($telemetria['sensores'] ?? $telemetria['sensors'] ?? null);
    $eventos = is_array($telemetria['eventos'] ?? null) ? array_slice($telemetria['eventos'], 0, 20) : [];
    $errores = entero($telemetria['errores_recientes'] ?? $telemetria['recent_errors'] ?? count(array_filter($eventos, static fn($e): bool => is_array($e) && in_array(($e['nivel'] ?? $e['level'] ?? ''), ['error'], true))));
    $versionCode = entero($telemetria['version_code'] ?? $body['version_code'] ?? null);
    $versionName = texto($telemetria['version_name'] ?? $telemetria['app_version'] ?? $body['version_name'] ?? null, 60);
    $tipoConexion = texto($telemetria['tipo_conexion'] ?? $telemetria['network_type'] ?? null, 50);
    $ultimaSync = fecha($telemetria['ultima_sincronizacion'] ?? $telemetria['last_sync_at'] ?? null);
    $payloadTecnico = [
        'device_id' => texto($body['device_id'] ?? $telemetria['device_id'] ?? null, 100),
        'android_version' => texto($telemetria['android_version'] ?? null, 40),
        'bateria_porcentaje' => entero($telemetria['bateria_porcentaje'] ?? $telemetria['battery_percent'] ?? null, 100),
        'almacenamiento_libre_mb' => entero($telemetria['almacenamiento_libre_mb'] ?? $telemetria['free_storage_mb'] ?? null),
        'device_owner' => bit($telemetria['device_owner'] ?? null),
        'sensores' => $sensores,
    ];

    $pdo->beginTransaction();
    if ($requestId !== null) {
        $dup = $pdo->prepare('SELECT id_telemetria,CONVERT(NVARCHAR(33),fecha_recepcion,126) fecha_recepcion FROM vendingm.telemedicina_mosi_telemetria WITH (UPDLOCK,HOLDLOCK) WHERE id_mosi=:id AND request_id=:request_id');
        $dup->execute(['id' => $idMosi, 'request_id' => $requestId]);
        if ($anterior = $dup->fetch(PDO::FETCH_ASSOC)) {
            $pdo->commit();
            responder(200, ['ok' => true, 'data' => ['id_mosi' => $idMosi, 'duplicado' => true, 'id_telemetria' => (int) $anterior['id_telemetria'], 'fecha_recepcion' => $anterior['fecha_recepcion']]]);
        }
    }

    $insert = $pdo->prepare(
        'INSERT INTO vendingm.telemedicina_mosi_telemetria
         (id_mosi,request_id,version_code,version_name,tipo_conexion,internet_disponible,wifi_conectado,ethernet_conectado,pendientes_sincronizacion,errores_recientes,ultima_sincronizacion,payload_json)
         OUTPUT INSERTED.id_telemetria,CONVERT(NVARCHAR(33),INSERTED.fecha_recepcion,126)
         VALUES (:id,:request_id,:version_code,:version_name,:conexion,:internet,:wifi,:ethernet,:pendientes,:errores,:ultima_sync,:payload)'
    );
    $params = [
        'id' => $idMosi, 'request_id' => $requestId, 'version_code' => $versionCode, 'version_name' => $versionName,
        'conexion' => $tipoConexion, 'internet' => bit($telemetria['internet_disponible'] ?? $telemetria['internet'] ?? null),
        'wifi' => bit($telemetria['wifi_conectado'] ?? $telemetria['wifi'] ?? null), 'ethernet' => bit($telemetria['ethernet_conectado'] ?? $telemetria['ethernet'] ?? null),
        'pendientes' => entero($telemetria['pendientes_sincronizacion'] ?? $telemetria['pending_sync'] ?? null), 'errores' => $errores,
        'ultima_sync' => $ultimaSync, 'payload' => json_encode($payloadTecnico, JSON_UNESCAPED_UNICODE),
    ];
    $insert->execute($params);
    $registro = $insert->fetch(PDO::FETCH_NUM);

    $update = $pdo->prepare(
        'UPDATE vendingm.telemedicina_mosi SET ultima_conexion=SYSDATETIME(),identificador_dispositivo=COALESCE(:device_id,identificador_dispositivo),
         version_code=COALESCE(:version_code,version_code),version_software=COALESCE(:version_name,version_software),tipo_conexion=COALESCE(:conexion,tipo_conexion),
         internet_disponible=COALESCE(:internet,internet_disponible),wifi_conectado=COALESCE(:wifi,wifi_conectado),ethernet_conectado=COALESCE(:ethernet,ethernet_conectado),
         nombre_red=COALESCE(:ssid,nombre_red),sensores_json=COALESCE(:sensores,sensores_json),pendientes_sincronizacion=COALESCE(:pendientes,pendientes_sincronizacion),
         errores_recientes=COALESCE(:errores,errores_recientes),ultima_medicion=COALESCE(:ultima_medicion,ultima_medicion),ultima_sincronizacion=COALESCE(:ultima_sync,ultima_sincronizacion),
         device_owner=COALESCE(:device_owner,device_owner),fecha_actualizacion=SYSDATETIME() WHERE id_mosi=:id'
    );
    $update->execute([
        'id' => $idMosi, 'device_id' => $payloadTecnico['device_id'], 'version_code' => $versionCode, 'version_name' => $versionName,
        'conexion' => $tipoConexion, 'internet' => $params['internet'], 'wifi' => $params['wifi'], 'ethernet' => $params['ethernet'],
        'pendientes' => $params['pendientes'], 'errores' => $errores, 'ultima_sync' => $ultimaSync,
        'ssid' => texto($telemetria['nombre_red'] ?? $telemetria['ssid'] ?? null, 120),
        'sensores' => $sensores === null ? null : json_encode($sensores, JSON_UNESCAPED_UNICODE),
        'ultima_medicion' => fecha($telemetria['ultima_medicion'] ?? $telemetria['last_measurement_at'] ?? null), 'device_owner' => $payloadTecnico['device_owner'],
    ]);

    if ($eventos) {
        $eventoStmt = $pdo->prepare('INSERT INTO vendingm.telemedicina_mosi_eventos(id_mosi,nivel,codigo,mensaje) VALUES(:id,:nivel,:codigo,:mensaje)');
        foreach ($eventos as $evento) {
            if (!is_array($evento) || ($mensaje = texto($evento['mensaje'] ?? $evento['message'] ?? null, 500)) === null) continue;
            $nivel = strtolower((string) ($evento['nivel'] ?? $evento['level'] ?? 'info'));
            if ($nivel === 'warn') $nivel = 'warning';
            if (!in_array($nivel, ['info', 'warning', 'error'], true)) $nivel = 'info';
            $eventoStmt->execute(['id' => $idMosi, 'nivel' => $nivel, 'codigo' => texto($evento['codigo'] ?? $evento['code'] ?? null, 80), 'mensaje' => $mensaje]);
        }
    }
    $pdo->commit();
    responder(200, ['ok' => true, 'data' => ['id_mosi' => $idMosi, 'duplicado' => false, 'id_telemetria' => (int) $registro[0], 'fecha_recepcion' => $registro[1]]]);
} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    responder(401, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    responder(500, ['ok' => false, 'error' => Security::publicError($e, 'mosi_telemetria')]);
}

function responder(int $status, array $data): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function texto(mixed $v, int $max): ?string { $s=trim((string)($v??'')); return $s===''?null:(function_exists('mb_substr')?mb_substr($s,0,$max):substr($s,0,$max)); }
function bit(mixed $v): ?int { if ($v===null||$v==='') return null; if(is_bool($v)) return $v?1:0; $s=strtolower(trim((string)$v)); if(in_array($s,['1','true','si','sí','online','connected'],true)) return 1; if(in_array($s,['0','false','no','offline','disconnected'],true)) return 0; return null; }
function entero(mixed $v, int $max=1000000): ?int { if($v===null||$v===''||filter_var($v,FILTER_VALIDATE_INT)===false) return null; return max(0,min($max,(int)$v)); }
function fecha(mixed $v): ?string { $s=trim((string)($v??'')); if($s==='') return null; try{return (new DateTimeImmutable($s))->format('Y-m-d H:i:s');}catch(Throwable){return null;} }
function sensoresNormalizados(mixed $v): ?array { if(!is_array($v)) return null; $out=[]; foreach(array_slice($v,0,40) as $s){ if(is_string($s)){$n=texto($s,100);if($n!==null)$out[]=['nombre'=>$n,'conectado'=>null,'estado'=>null];continue;} if(!is_array($s))continue;$n=texto($s['nombre']??$s['name']??$s['id']??null,100);if($n===null)continue;$out[]=['nombre'=>$n,'conectado'=>bit($s['conectado']??$s['connected']??null),'estado'=>texto($s['estado']??$s['status']??null,60)];} return $out; }
