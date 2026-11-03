<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Security\Security;
use App\Telemedicina\LegacyConnection;
use App\Telemedicina\Schema;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

$idPersonal=filter_input(INPUT_GET,'id_personal',FILTER_VALIDATE_INT);
$body=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    $body=json_decode((string)file_get_contents('php://input'),true);
    $idPersonal=filter_var($body['id_personal']??null,FILTER_VALIDATE_INT);
}
if(!$idPersonal){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Falta id_personal'],JSON_UNESCAPED_UNICODE);exit;}

try{
    $pdo=Connection::get();Schema::ensure($pdo);$legacy=LegacyConnection::get();
    $st=$pdo->prepare('SELECT id_personal,id_medico_legacy,tipo_profesional,nombre,apellido FROM vendingm.telemedicina_personal WHERE id_personal=:id');
    $st->execute(['id'=>$idPersonal]);$persona=$st->fetch();
    if(!$persona){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Profesional no encontrado'],JSON_UNESCAPED_UNICODE);exit;}
    if(empty($persona['id_medico_legacy'])){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Este profesional no esta vinculado con un registro del sistema medico anterior.'],JSON_UNESCAPED_UNICODE);exit;}
    $legacyId=(int)$persona['id_medico_legacy'];
    $required=(bool)$legacy->query("SELECT CASE WHEN OBJECT_ID('dbo.tbl_permisosVending','U') IS NOT NULL AND OBJECT_ID('fernandoruiz.tbl_HorariosMedicos','U') IS NOT NULL AND OBJECT_ID('fernandoruiz.cat_SucursalClinica','U') IS NOT NULL THEN 1 ELSE 0 END")->fetchColumn();
    if(!$required)throw new RuntimeException('No se encontraron dbo.tbl_permisosVending o las tablas requeridas de fernandoruiz.');

    if($_SERVER['REQUEST_METHOD']==='GET'){
        $p=$legacy->prepare('SELECT TOP 1 id_permisosVending,st_dispositivo,st_vendings,i_activo,i_activo_consulta,i_grupo,dt_fechaActivo FROM dbo.tbl_permisosVending WHERE id_medico=:id');
        $p->execute(['id'=>$legacyId]);$permisos=$p->fetch()?:[];
        $h=$legacy->prepare("SELECT id_TipoPermiso,st_Descripcion,LEFT(CONVERT(VARCHAR(8),dt_FechaInicio,108),5) hora_inicio,LEFT(CONVERT(VARCHAR(8),dt_FechaFin,108),5) hora_fin FROM fernandoruiz.tbl_HorariosMedicos WHERE id_Medico=:id AND id_TipoPermiso IN(1,2) ORDER BY CASE st_Descripcion WHEN 'Lunes' THEN 1 WHEN 'Martes' THEN 2 WHEN 'Miercoles' THEN 3 WHEN 'Jueves' THEN 4 WHEN 'Viernes' THEN 5 WHEN 'Sabado' THEN 6 WHEN 'Domingo' THEN 7 ELSE 8 END,id_TipoPermiso");
        $h->execute(['id'=>$legacyId]);$horarios=[];
        foreach($h->fetchAll() as $row){$dia=normalizarDia((string)$row['st_Descripcion']);if(!$dia)continue;$key=(int)$row['id_TipoPermiso']===2?'comida':'laboral';$horarios[$dia][$key]=['inicio'=>$row['hora_inicio'],'fin'=>$row['hora_fin']];}
        $sucursales=$legacy->query("SELECT id_SucursalClinica,st_Nombre,LTRIM(RTRIM(CONVERT(NVARCHAR(100),st_vendingMachine))) st_vendingMachine FROM fernandoruiz.cat_SucursalClinica WHERE st_vendingMachine IS NOT NULL AND LTRIM(RTRIM(CONVERT(NVARCHAR(100),st_vendingMachine)))<>'' AND i_Activo=1 ORDER BY st_Nombre")->fetchAll();
        echo json_encode(['ok'=>true,'data'=>['persona'=>$persona,'permisos'=>$permisos,'horarios'=>$horarios,'sucursales'=>$sucursales]],JSON_UNESCAPED_UNICODE);exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Metodo no permitido']);exit;}

    $dispositivo=(string)($body['st_dispositivo']??'ALL');
    if(!in_array($dispositivo,['ALL','Kiosco','APK'],true))throw new InvalidArgumentException('Plataforma no valida.');
    $vendings=array_values(array_unique(array_filter(array_map(static fn($v)=>trim((string)$v),(array)($body['vendings']??[])))));
    foreach($vendings as $v){if(strlen($v)>100)throw new InvalidArgumentException('Identificador de vending no valido.');}
    $vendingsCsv=implode(',',$vendings);$activo=!empty($body['activo'])?1:0;$consulta=!empty($body['activo_consulta'])?1:0;
    $grupo=match((string)$persona['tipo_profesional']){'psicologo'=>24,'nutriologo'=>23,default=>20};
    $dias=['Lunes','Martes','Miercoles','Jueves','Viernes','Sabado','Domingo'];$horarios=(array)($body['horarios']??[]);

    $legacy->beginTransaction();
    $upd=$legacy->prepare('UPDATE dbo.tbl_permisosVending SET st_dispositivo=:dispositivo,st_vendings=:vendings,i_activo=:activo,i_activo_consulta=:consulta,i_grupo=:grupo,dt_fechaActualizar=GETDATE() WHERE id_medico=:medico');
    $upd->execute(['dispositivo'=>$dispositivo,'vendings'=>$vendingsCsv?:null,'activo'=>$activo,'consulta'=>$consulta,'grupo'=>$grupo,'medico'=>$legacyId]);
    if($upd->rowCount()===0){$exists=$legacy->prepare('SELECT COUNT(*) FROM dbo.tbl_permisosVending WHERE id_medico=:id');$exists->execute(['id'=>$legacyId]);if(!(int)$exists->fetchColumn())throw new RuntimeException('El medico no tiene un registro en dbo.tbl_permisosVending.');}

    $existsH=$legacy->prepare('SELECT COUNT(*) FROM fernandoruiz.tbl_HorariosMedicos WHERE id_Medico=:medico AND id_TipoPermiso=:tipo AND st_Descripcion COLLATE Latin1_General_CI_AI=:dia COLLATE Latin1_General_CI_AI');
    $updH=$legacy->prepare('UPDATE fernandoruiz.tbl_HorariosMedicos SET dt_FechaInicio=CONVERT(DATETIME,:inicio,108),dt_FechaFin=CONVERT(DATETIME,:fin,108) WHERE id_Medico=:medico AND id_TipoPermiso=:tipo AND st_Descripcion COLLATE Latin1_General_CI_AI=:dia COLLATE Latin1_General_CI_AI');
    $insH=$legacy->prepare('INSERT INTO fernandoruiz.tbl_HorariosMedicos(id_Medico,id_TipoPermiso,st_Descripcion,dt_FechaInicio,dt_FechaFin) VALUES(:medico,:tipo,:dia,CONVERT(DATETIME,:inicio,108),CONVERT(DATETIME,:fin,108))');
    $delH=$legacy->prepare('DELETE FROM fernandoruiz.tbl_HorariosMedicos WHERE id_Medico=:medico AND id_TipoPermiso=:tipo AND st_Descripcion COLLATE Latin1_General_CI_AI=:dia COLLATE Latin1_General_CI_AI');
    foreach($dias as $dia){foreach(['laboral'=>1,'comida'=>2] as $key=>$tipo){$item=(array)($horarios[$dia][$key]??[]);$inicio=trim((string)($item['inicio']??''));$fin=trim((string)($item['fin']??''));$base=['medico'=>$legacyId,'tipo'=>$tipo,'dia'=>$dia];if($inicio===''&&$fin===''){$delH->execute($base);continue;}if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$inicio)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$fin))throw new InvalidArgumentException("Horario incompleto o invalido para {$dia}.");$existsH->execute($base);$values=$base+['inicio'=>$inicio,'fin'=>$fin];if((int)$existsH->fetchColumn())$updH->execute($values);else$insH->execute($values);}}
    $legacy->commit();

    $hoy=['Sunday'=>'Domingo','Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miercoles','Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sabado'][date('l')];$actual=(array)($horarios[$hoy]['laboral']??[]);
    $pdo->prepare('UPDATE vendingm.telemedicina_personal SET grupo_legacy=:grupo,vendings_legacy=:vendings,sesion_activa=:sesion,estado_operativo=:estado,hora_inicio=:inicio,hora_fin=:fin,activo=:activo,fecha_sincronizacion=SYSDATETIME(),fecha_actualizacion=SYSDATETIME() WHERE id_personal=:id')->execute(['grupo'=>$grupo,'vendings'=>$vendingsCsv?:null,'sesion'=>$consulta,'estado'=>$consulta?'disponible':'desconectado','inicio'=>($actual['inicio']??'')?:null,'fin'=>($actual['fin']??'')?:null,'activo'=>$activo,'id'=>$idPersonal]);
    $link=$pdo->prepare('INSERT INTO vendingm.telemedicina_personal_mosi(id_personal,id_mosi,asignado_por) SELECT :personal,mm.id_mosi,:usuario FROM vendingm.maquinas m INNER JOIN vendingm.telemedicina_mosi_maquinas mm ON mm.id_maquina=m.id_maquina WHERE (m.numero_serie=:t1 OR m.codigo_interno=:t2 OR CONVERT(NVARCHAR(30),m.id_maquina)=:t3) AND NOT EXISTS(SELECT 1 FROM vendingm.telemedicina_personal_mosi x WHERE x.id_personal=:personal2 AND x.id_mosi=mm.id_mosi)');
    foreach($vendings as $token)$link->execute(['personal'=>$idPersonal,'usuario'=>Auth::id(),'t1'=>$token,'t2'=>$token,'t3'=>$token,'personal2'=>$idPersonal]);
    echo json_encode(['ok'=>true,'data'=>['id_personal'=>$idPersonal,'vendings'=>count($vendings),'horarios_actualizados'=>true]],JSON_UNESCAPED_UNICODE);
}catch(InvalidArgumentException|RuntimeException $e){if(isset($legacy)&&$legacy->inTransaction())$legacy->rollBack();http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){if(isset($legacy)&&$legacy->inTransaction())$legacy->rollBack();http_response_code(500);echo json_encode(['ok'=>false,'error'=>Security::publicError($e,'personal_permisos')],JSON_UNESCAPED_UNICODE);}

function normalizarDia(string $dia):?string{$d=iconv('UTF-8','ASCII//TRANSLIT',$dia)?:$dia;$map=['lunes'=>'Lunes','martes'=>'Martes','miercoles'=>'Miercoles','jueves'=>'Jueves','viernes'=>'Viernes','sabado'=>'Sabado','domingo'=>'Domingo'];return $map[strtolower(trim($d))]??null;}
