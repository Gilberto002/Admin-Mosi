<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Actualizaciones\AppActualizaciones;
use App\Database\Connection;
use App\Security\Security;
use App\Telemedicina\MosiApi;

header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST') responderEvento(['ok'=>false,'error'=>'Metodo no permitido'],405);
try {
    $body=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($body)) responderEvento(['ok'=>false,'error'=>'JSON invalido'],400);
    $serie=trim((string)($body['numero_serie']??''));
    $pdo=Connection::get(); $mosi=MosiApi::autenticar($pdo,$serie);
    $tipo=strtolower(trim((string)($body['tipo_evento']??$body['evento']??'')));
    if(!in_array($tipo,[AppActualizaciones::EVENTO_DESCARGA,AppActualizaciones::EVENTO_INSTALACION,AppActualizaciones::EVENTO_ERROR],true)) responderEvento(['ok'=>false,'error'=>'tipo_evento debe ser descarga, instalacion o error'],422);
    $versionCode=filter_var($body['version_code_actual']??$body['version_code']??null,FILTER_VALIDATE_INT); $versionCode=$versionCode===false?null:(int)$versionCode;
    $versionName=trim((string)($body['version_name_actual']??$body['version_name']??''))?:null;
    $pdo->beginTransaction();
    $idEvento=AppActualizaciones::registrarEvento($pdo,[
        'id_actualizacion'=>filter_var($body['id_actualizacion']??null,FILTER_VALIDATE_INT)?:null,'canal_app'=>AppActualizaciones::CANAL_MOSI,
        'id_mosi'=>(int)$mosi['id_mosi'],'numero_serie'=>(string)$mosi['numero_serie'],'tipo_evento'=>$tipo,'version_code_actual'=>$versionCode,
        'version_name_actual'=>$versionName,'version_code_objetivo'=>filter_var($body['version_code_objetivo']??null,FILTER_VALIDATE_INT)?:null,
        'codigo_error'=>substr(trim((string)($body['codigo_error']??'')),0,120)?:null,'mensaje'=>substr(trim((string)($body['mensaje']??'')),0,800)?:null,
        'request_id'=>substr(trim((string)($body['request_id']??'')),0,120)?:null,
        'payload_json'=>json_encode(['tipo_evento'=>$tipo,'version_code_actual'=>$versionCode,'codigo_error'=>$body['codigo_error']??null],JSON_UNESCAPED_UNICODE),
    ]);
    if($tipo===AppActualizaciones::EVENTO_INSTALACION){
        $stmt=$pdo->prepare('UPDATE vendingm.telemedicina_mosi SET version_code=COALESCE(:code,version_code),version_software=COALESCE(:name,version_software),actualizacion_disponible=0,version_disponible=NULL,fecha_actualizacion=SYSDATETIME() WHERE id_mosi=:id');
        $stmt->execute(['code'=>$versionCode,'name'=>$versionName,'id'=>(int)$mosi['id_mosi']]);
    }
    $pdo->commit(); responderEvento(['ok'=>true,'data'=>['id_evento'=>$idEvento,'server_time'=>date(DATE_ATOM)]]);
} catch(RuntimeException $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();responderEvento(['ok'=>false,'error'=>$e->getMessage()],401);}
catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();responderEvento(['ok'=>false,'error'=>Security::publicError($e,'mosi_actualizacion_evento')],500);}
function responderEvento(array $data,int $status=200): never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE);exit;}
