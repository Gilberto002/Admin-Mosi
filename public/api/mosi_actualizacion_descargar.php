<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Actualizaciones\AppActualizaciones;
use App\Database\Connection;
use App\Telemedicina\MosiApi;

if($_SERVER['REQUEST_METHOD']!=='GET') errorDescarga('Metodo no permitido',405);
$id=filter_input(INPUT_GET,'id_actualizacion',FILTER_VALIDATE_INT); $serie=trim((string)($_GET['numero_serie']??$_SERVER['HTTP_X_MOSI_SERIAL']??''));
if(!$id||$serie==='') errorDescarga('Falta id_actualizacion o numero_serie',422);
try {
    $pdo=Connection::get(); MosiApi::autenticar($pdo,$serie); AppActualizaciones::asegurarTablas($pdo);
    $stmt=$pdo->prepare('SELECT apk_archivo,apk_url,sha256,activa FROM vendingm.app_actualizaciones WHERE id_actualizacion=:id AND canal_app=:canal');
    $stmt->execute(['id'=>$id,'canal'=>AppActualizaciones::CANAL_MOSI]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row||!(bool)$row['activa']) errorDescarga('APK no disponible',404);
    $archivo=trim((string)($row['apk_archivo']??'')); if($archivo==='')$archivo=basename(parse_url((string)$row['apk_url'],PHP_URL_PATH)?:''); $archivo=basename($archivo);
    if(strtolower(pathinfo($archivo,PATHINFO_EXTENSION))!=='apk') errorDescarga('Archivo APK invalido',404);
    $ruta=dirname(__DIR__).'/uploads/apks/'.$archivo; if(!is_file($ruta)||!is_readable($ruta)) errorDescarga('Archivo APK no encontrado',404);
    $tamano=filesize($ruta); if($tamano===false||$tamano<=0) errorDescarga('Archivo APK vacio',404);
    @set_time_limit(0);@ini_set('zlib.output_compression','0');while(ob_get_level()>0)ob_end_clean();
    header('Content-Type: application/vnd.android.package-archive');header('Content-Disposition: attachment; filename="'.addcslashes($archivo,'"\\').'"');header('Content-Length: '.$tamano);header('X-APK-SHA256: '.$row['sha256']);header('Cache-Control: private, no-store');
    $stream=fopen($ruta,'rb');if($stream===false)throw new RuntimeException('No se pudo abrir el APK');
    try{while(!feof($stream)){ $bloque=fread($stream,1024*1024);if($bloque===false)throw new RuntimeException('No se pudo leer el APK');echo $bloque;flush();if(connection_aborted())break; }}finally{fclose($stream);} exit;
}catch(RuntimeException $e){errorDescarga($e->getMessage(),401);}catch(Throwable $e){errorDescarga('No se pudo descargar el APK',500);}
function errorDescarga(string $mensaje,int $status):never{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$mensaje],JSON_UNESCAPED_UNICODE);exit;}
