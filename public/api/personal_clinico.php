<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Security\Security;
use App\Telemedicina\Schema;
use App\Telemedicina\LegacyConnection;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Connection::get();
    Schema::ensure($pdo);
    $q = trim((string) ($_GET['q'] ?? ''));
    $tipo = trim((string) ($_GET['tipo'] ?? ''));
    $estado = trim((string) ($_GET['estado'] ?? ''));
    $idMosi = filter_input(INPUT_GET, 'id_mosi', FILTER_VALIDATE_INT) ?: null;
    $idPersonal = filter_input(INPUT_GET, 'id_personal', FILTER_VALIDATE_INT) ?: null;
    $page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
    $pageSize = min(100, max(1, filter_input(INPUT_GET, 'page_size', FILTER_VALIDATE_INT) ?: 30));
    $offset = ($page - 1) * $pageSize;

    $where = ['p.activo = 1'];
    $params = [];
    if (Auth::isClinico()) {
        $where[] = 'p.id_usuario = :auth_usuario';
        $params['auth_usuario'] = Auth::id();
    }
    if ($idPersonal) { $where[] = 'p.id_personal = :id_personal'; $params['id_personal'] = $idPersonal; }
    if ($q !== '') {
        $where[] = "(p.nombre LIKE :q1 OR p.apellido LIKE :q2 OR p.email LIKE :q3 OR p.especialidad LIKE :q4 OR p.sucursal_legacy LIKE :q5 OR EXISTS (SELECT 1 FROM vendingm.telemedicina_personal_mosi px INNER JOIN vendingm.telemedicina_mosi tx ON tx.id_mosi=px.id_mosi WHERE px.id_personal=p.id_personal AND (tx.nombre LIKE :q6 OR tx.numero_serie LIKE :q7)))";
        for ($i=1;$i<=7;$i++) $params['q'.$i] = "%{$q}%";
    }
    if (in_array($tipo, ['medico','psicologo','nutriologo'], true)) { $where[]='p.tipo_profesional=:tipo'; $params['tipo']=$tipo; }
    if (in_array($estado, ['disponible','en_consulta','desconectado'], true)) { $where[]='p.estado_operativo=:estado'; $params['estado']=$estado; }
    if ($idMosi) { $where[]='EXISTS (SELECT 1 FROM vendingm.telemedicina_personal_mosi pf WHERE pf.id_personal=p.id_personal AND pf.id_mosi=:id_mosi)'; $params['id_mosi']=$idMosi; }
    $whereSql = 'WHERE '.implode(' AND ', $where);

    $count = $pdo->prepare("SELECT COUNT(*) FROM vendingm.telemedicina_personal p {$whereSql}");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $stmt = $pdo->prepare("SELECT p.*, u.ultimo_acceso, u.activo AS usuario_activo,
        CASE WHEN p.id_usuario IS NOT NULL AND u.activo = 1 THEN 1 ELSE 0 END AS tiene_acceso
        FROM vendingm.telemedicina_personal p
        LEFT JOIN vendingm.usuarios u ON u.id_usuario=p.id_usuario
        {$whereSql}
        ORDER BY p.tipo_profesional, p.nombre, p.apellido
        OFFSET :offset ROWS FETCH NEXT :page_size ROWS ONLY");
    foreach($params as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->bindValue('offset',$offset,\PDO::PARAM_INT);
    $stmt->bindValue('page_size',$pageSize,\PDO::PARAM_INT);
    $stmt->execute();
    $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $ids = array_map('intval', array_column($data,'id_personal'));
    $asignaciones = [];
    if ($ids) {
        $in = implode(',',$ids);
        $a = $pdo->query("SELECT pm.id_personal, t.id_mosi, t.nombre, t.numero_serie, t.ubicacion, g.nombre AS grupo_nombre,
            CASE WHEN t.ultima_conexion IS NULL THEN 'nunca_conectada' WHEN t.ultima_conexion>=DATEADD(minute,-10,SYSDATETIME()) THEN 'online' ELSE 'offline' END AS estado_conexion
            FROM vendingm.telemedicina_personal_mosi pm
            INNER JOIN vendingm.telemedicina_mosi t ON t.id_mosi=pm.id_mosi
            LEFT JOIN vendingm.telemedicina_grupos g ON g.id_grupo=t.id_grupo
            WHERE pm.id_personal IN ({$in}) ORDER BY t.nombre");
        foreach($a->fetchAll(\PDO::FETCH_ASSOC) as $row) $asignaciones[(int)$row['id_personal']][]=$row;

        $v = $pdo->query("SELECT pm.id_personal, mm.id_mosi, m.id_maquina, m.numero_serie, m.nombre_maquina
            FROM vendingm.telemedicina_personal_mosi pm
            INNER JOIN vendingm.telemedicina_mosi_maquinas mm ON mm.id_mosi=pm.id_mosi
            INNER JOIN vendingm.maquinas m ON m.id_maquina=mm.id_maquina
            WHERE pm.id_personal IN ({$in}) ORDER BY m.numero_serie");
        $vendings=[];
        foreach($v->fetchAll(\PDO::FETCH_ASSOC) as $row) $vendings[(int)$row['id_personal']][]=$row;
        foreach($data as &$p) { $p['mosis']=$asignaciones[(int)$p['id_personal']]??[]; $p['vendings']=$vendings[(int)$p['id_personal']]??[]; }
        unset($p);
    }

    $statWhere = Auth::isClinico() ? 'WHERE p.id_usuario=:uid AND p.activo=1' : 'WHERE p.activo=1';
    $stats = $pdo->prepare("SELECT COUNT(*) total,
        SUM(CASE WHEN tipo_profesional='medico' THEN 1 ELSE 0 END) medicos,
        SUM(CASE WHEN tipo_profesional='psicologo' THEN 1 ELSE 0 END) psicologos,
        SUM(CASE WHEN tipo_profesional='nutriologo' THEN 1 ELSE 0 END) nutriologos,
        SUM(CASE WHEN estado_operativo='disponible' THEN 1 ELSE 0 END) disponibles,
        SUM(CASE WHEN estado_operativo='en_consulta' THEN 1 ELSE 0 END) en_consulta,
        SUM(CASE WHEN id_usuario IS NOT NULL THEN 1 ELSE 0 END) con_acceso
        FROM vendingm.telemedicina_personal p {$statWhere}");
    $stats->execute(Auth::isClinico()?['uid'=>Auth::id()]:[]);

    $mosis = [];
    if (Auth::isAdmin()) $mosis=$pdo->query("SELECT id_mosi,nombre,numero_serie,ubicacion FROM vendingm.telemedicina_mosi WHERE estatus<>'baja' ORDER BY nombre")->fetchAll(\PDO::FETCH_ASSOC);
    $legacyDisponible = false;
    if (Auth::isAdmin()) {
        try {
            $legacy = LegacyConnection::get();
            $legacyDisponible = (bool)$legacy->query("SELECT CASE WHEN
                (OBJECT_ID('fernandoruiz.cta_medicos','U') IS NOT NULL OR OBJECT_ID('fernandoruiz.cat_Medicos','U') IS NOT NULL)
                AND OBJECT_ID('dbo.tbl_permisosVending','U') IS NOT NULL
                AND OBJECT_ID('fernandoruiz.cat_SucursalClinica','U') IS NOT NULL
                THEN 1 ELSE 0 END")->fetchColumn();
        } catch (Throwable) { $legacyDisponible = false; }
    }

    if ($idPersonal && !$data) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Profesional no encontrado'],JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(['ok'=>true,'data'=>$idPersonal?$data[0]:$data,'meta'=>['total'=>$total,'page'=>$page,'page_size'=>$pageSize], 'stats'=>$stats->fetch(\PDO::FETCH_ASSOC)?:[], 'catalogos'=>['mosis'=>$mosis], 'legacy_disponible'=>$legacyDisponible],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>Security::publicError($e,'personal_clinico')],JSON_UNESCAPED_UNICODE);
}
