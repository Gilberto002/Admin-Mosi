<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Actualizaciones\AppActualizaciones;
use App\Database\Connection;
use App\Security\Security;
use App\Telemedicina\MosiApi;

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') salida(['ok'=>false,'error'=>'Metodo no permitido'],405);

try {
    $body=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($body)) salida(['ok'=>false,'error'=>'JSON invalido'],400);
    $serie=trim((string)($body['numero_serie']??''));
    if($serie==='') salida(['ok'=>false,'error'=>'numero_serie es obligatorio'],422);
    $pdo=Connection::get();
    $mosi=MosiApi::autenticar($pdo,$serie);
    $version=filter_var($body['version_code_actual']??$body['version_code']??0,FILTER_VALIDATE_INT);
    $version=$version===false?0:max(0,(int)$version);
    $actualizacion=AppActualizaciones::actualizacionPara($pdo,$version,AppActualizaciones::CANAL_MOSI);
    $ultima=$actualizacion?:AppActualizaciones::ultimaActiva($pdo,AppActualizaciones::CANAL_MOSI);
    foreach (['actualizacion','ultima'] as $clave) {
        $ref = $clave==='actualizacion' ? $actualizacion : $ultima;
        if ($ref) {
            $ref['apk_url']=urlDescargaMosi((int)$ref['id_actualizacion'],(string)$mosi['numero_serie']);
            if ($clave==='actualizacion') $actualizacion=$ref; else $ultima=$ref;
        }
    }
    $respuesta=['ok'=>true,'data'=>['actualizacion_disponible'=>$actualizacion!==null,'actualizacion'=>$actualizacion,'ultima_activa'=>$ultima,'server_time'=>date(DATE_ATOM)]];
    AppActualizaciones::registrarEvento($pdo,[
        'id_actualizacion'=>$ultima['id_actualizacion']??null,'canal_app'=>AppActualizaciones::CANAL_MOSI,'id_mosi'=>(int)$mosi['id_mosi'],
        'numero_serie'=>(string)$mosi['numero_serie'],'tipo_evento'=>AppActualizaciones::EVENTO_CONSULTA,'version_code_actual'=>$version,
        'version_name_actual'=>trim((string)($body['version_name_actual']??$body['version_name']??''))?:null,
        'version_code_objetivo'=>$actualizacion['version_code']??$ultima['version_code']??null,'request_id'=>trim((string)($body['request_id']??''))?:null,
        'payload_json'=>json_encode(['version_code_actual'=>$version,'request_id'=>$body['request_id']??null],JSON_UNESCAPED_UNICODE),
        'respuesta_json'=>json_encode($respuesta,JSON_UNESCAPED_UNICODE),
    ]);
    salida($respuesta);
} catch(RuntimeException $e){ salida(['ok'=>false,'error'=>$e->getMessage()],401); }
catch(Throwable $e){ salida(['ok'=>false,'error'=>Security::publicError($e,'mosi_actualizacion_consultar')],500); }

function urlDescargaMosi(int $id,string $serie): string {
    $https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||(($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https');
    $host=$_SERVER['HTTP_HOST']??'localhost'; $base=rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/api')),'/');
    return ($https?'https://':'http://').$host.$base.'/mosi_actualizacion_descargar.php?id_actualizacion='.$id.'&numero_serie='.rawurlencode($serie);
}
function salida(array $data,int $status=200): never { http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE);exit; }
