<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2).'/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Security\Security;
use App\Telemedicina\Schema;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');
$body=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($body)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'JSON invalido']);exit;}

try{
    $pdo=Connection::get(); Schema::ensure($pdo); asegurarRolesClinicos($pdo);
    $id=filter_var($body['id_personal']??null,FILTER_VALIDATE_INT)?:null;
    $tipo=(string)($body['tipo_profesional']??'medico');
    $nombre=trim((string)($body['nombre']??'')); $apellido=trim((string)($body['apellido']??''));
    $email=strtolower(trim((string)($body['email']??''))); $password=(string)($body['password']??'');
    $estado=(string)($body['estado_operativo']??'desconectado'); $activo=!empty($body['activo'])?1:0;
    $mosis=array_values(array_unique(array_filter(array_map('intval',(array)($body['mosis']??[])))));
    if($nombre==='')throw new InvalidArgumentException('El nombre es obligatorio.');
    if(!in_array($tipo,['medico','psicologo','nutriologo'],true))throw new InvalidArgumentException('Tipo de profesional no valido.');
    if(!in_array($estado,['disponible','en_consulta','desconectado'],true))throw new InvalidArgumentException('Estado operativo no valido.');
    if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('El correo no es valido.');
    if($password!==''&&strlen($password)<8)throw new InvalidArgumentException('La contrasena debe tener al menos 8 caracteres.');
    if(!empty($body['crear_acceso'])&&$email==='' )throw new InvalidArgumentException('El correo es obligatorio para dar acceso al tablero.');

    $actual=null;
    if($id){$s=$pdo->prepare('SELECT * FROM vendingm.telemedicina_personal WHERE id_personal=:id');$s->execute(['id'=>$id]);$actual=$s->fetch();if(!$actual)throw new InvalidArgumentException('El profesional no existe.');}
    $pdo->beginTransaction();
    $idUsuario=$actual['id_usuario']??null;
    if(!empty($body['crear_acceso'])){
        if(!$idUsuario){
            $u=$pdo->prepare('SELECT id_usuario FROM vendingm.usuarios WHERE email=:email');$u->execute(['email'=>$email]);$idUsuario=$u->fetchColumn()?:null;
        }
        if($idUsuario){
            $sql='UPDATE vendingm.usuarios SET nombre=:nombre,apellido=:apellido,email=:email,rol=:rol,activo=1,fecha_actualizacion=SYSDATETIME()'.($password!==''?',password_hash=:hash':'').' WHERE id_usuario=:id';
            $p=['nombre'=>$nombre,'apellido'=>$apellido?:'-','email'=>$email,'rol'=>$tipo,'id'=>$idUsuario];if($password!=='')$p['hash']=password_hash($password,PASSWORD_DEFAULT);$pdo->prepare($sql)->execute($p);
        }else{
            if(strlen($password)<8)throw new InvalidArgumentException('Indica una contrasena de al menos 8 caracteres para crear el acceso.');
            $u=$pdo->prepare('INSERT INTO vendingm.usuarios(nombre,apellido,email,password_hash,rol,activo) OUTPUT INSERTED.id_usuario VALUES(:nombre,:apellido,:email,:hash,:rol,1)');
            $u->execute(['nombre'=>$nombre,'apellido'=>$apellido?:'-','email'=>$email,'hash'=>password_hash($password,PASSWORD_DEFAULT),'rol'=>$tipo]);$idUsuario=(int)$u->fetchColumn();
        }
    } elseif ($idUsuario) {
        $pdo->prepare('UPDATE vendingm.usuarios SET activo=0, fecha_actualizacion=SYSDATETIME() WHERE id_usuario=:id')->execute(['id'=>$idUsuario]);
    }
    $f=['usuario'=>$idUsuario?:null,'tipo'=>$tipo,'nombre'=>$nombre,'apellido'=>nullText($apellido),'email'=>nullText($email),'telefono'=>nullText($body['telefono']??null),'cedula'=>nullText($body['cedula_profesional']??null),'especialidad'=>nullText($body['especialidad']??null),'estado'=>$estado,'inicio'=>nullText($body['hora_inicio']??null),'fin'=>nullText($body['hora_fin']??null),'activo'=>$activo];
    if($id){$pdo->prepare('UPDATE vendingm.telemedicina_personal SET id_usuario=:usuario,tipo_profesional=:tipo,nombre=:nombre,apellido=:apellido,email=:email,telefono=:telefono,cedula_profesional=:cedula,especialidad=:especialidad,estado_operativo=:estado,hora_inicio=:inicio,hora_fin=:fin,activo=:activo,fecha_actualizacion=SYSDATETIME() WHERE id_personal=:id')->execute($f+['id'=>$id]);}
    else{$s=$pdo->prepare('INSERT INTO vendingm.telemedicina_personal(id_usuario,tipo_profesional,nombre,apellido,email,telefono,cedula_profesional,especialidad,estado_operativo,hora_inicio,hora_fin,activo) OUTPUT INSERTED.id_personal VALUES(:usuario,:tipo,:nombre,:apellido,:email,:telefono,:cedula,:especialidad,:estado,:inicio,:fin,:activo)');$s->execute($f);$id=(int)$s->fetchColumn();}
    $pdo->prepare('DELETE FROM vendingm.telemedicina_personal_mosi WHERE id_personal=:id')->execute(['id'=>$id]);
    if($mosis){$valid=$pdo->prepare('INSERT INTO vendingm.telemedicina_personal_mosi(id_personal,id_mosi,asignado_por) SELECT :personal,id_mosi,:usuario FROM vendingm.telemedicina_mosi WHERE id_mosi=:mosi AND estatus<>\'baja\'');foreach($mosis as $m)$valid->execute(['personal'=>$id,'usuario'=>Auth::id(),'mosi'=>$m]);}
    $pdo->commit(); echo json_encode(['ok'=>true,'data'=>['id_personal'=>$id,'id_usuario'=>$idUsuario]],JSON_UNESCAPED_UNICODE);
}catch(InvalidArgumentException $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}catch(\PDOException $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();http_response_code($e->getCode()==='23000'?409:500);echo json_encode(['ok'=>false,'error'=>$e->getCode()==='23000'?'El correo o la vinculacion ya estan registrados.':Security::publicError($e,'personal_guardar')],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();http_response_code(500);echo json_encode(['ok'=>false,'error'=>Security::publicError($e,'personal_guardar')],JSON_UNESCAPED_UNICODE);}

function nullText(mixed $v):?string{$s=trim((string)$v);return $s===''?null:$s;}
function asegurarRolesClinicos(\PDO $pdo):void{$pdo->exec("IF EXISTS(SELECT 1 FROM sys.check_constraints WHERE name='CK_usuarios_rol' AND parent_object_id=OBJECT_ID('vendingm.usuarios') AND (definition NOT LIKE '%medico%' OR definition NOT LIKE '%psicologo%' OR definition NOT LIKE '%nutriologo%')) ALTER TABLE vendingm.usuarios DROP CONSTRAINT CK_usuarios_rol; IF NOT EXISTS(SELECT 1 FROM sys.check_constraints WHERE name='CK_usuarios_rol' AND parent_object_id=OBJECT_ID('vendingm.usuarios')) ALTER TABLE vendingm.usuarios ADD CONSTRAINT CK_usuarios_rol CHECK(rol IN('admin','cliente','colaborador','medico','psicologo','nutriologo','tecnico_instalador','tecnico_soporte')); ");}
