<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Abastecimiento\Schema;
use App\Auth\Auth;
use App\Database\Connection;
use App\Security\Security;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Connection::get();
    Schema::ensure($pdo);
    $accion = trim((string) ($_GET['accion'] ?? 'dashboard'));

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        match ($accion) {
            'dashboard' => dashboard($pdo),
            'catalogos' => catalogos($pdo),
            'jornadas' => jornadas($pdo),
            'detalle_jornada' => detalleJornada($pdo),
            default => respuesta(['ok' => false, 'error' => 'Accion no valida'], 400),
        };
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Auth::requireAdmin();
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            respuesta(['ok' => false, 'error' => 'JSON invalido'], 400);
        }
        match ($accion) {
            'guardar_region' => guardarRegion($pdo, $body),
            'guardar_chofer' => guardarChofer($pdo, $body),
            'guardar_ruta' => guardarRuta($pdo, $body),
            'generar_jornada' => generarJornada($pdo, $body),
            'estatus_jornada' => estatusJornada($pdo, $body),
            'actualizar_parada' => actualizarParada($pdo, $body),
            default => respuesta(['ok' => false, 'error' => 'Accion no valida'], 400),
        };
    }

    respuesta(['ok' => false, 'error' => 'Metodo no permitido'], 405);
} catch (Throwable $e) {
    Security::logException($e, 'abastecimiento');
    respuesta(['ok' => false, 'error' => Security::publicError($e, 'api')], 500);
}

function respuesta(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function inputInt(string $nombre): ?int
{
    $valor = filter_input(INPUT_GET, $nombre, FILTER_VALIDATE_INT);
    return $valor === false || $valor === null ? null : (int) $valor;
}

function capacidadExpresion(PDO $pdo): string
{
    $tieneCapacidad = (bool) $pdo->query(
        "SELECT CASE WHEN COL_LENGTH('vendingm.carriles', 'capacidad_maxima') IS NULL THEN 0 ELSE 1 END"
    )->fetchColumn();
    return $tieneCapacidad ? 'COALESCE(NULLIF(ca.capacidad_maxima, 0), 10)' : '10';
}

function stockApply(PDO $pdo): string
{
    $capacidad = capacidadExpresion($pdo);
    return "OUTER APPLY (
        SELECT
            COALESCE(SUM(CASE WHEN ca.numero_carril % 10 <> 0 THEN ca.cantidad_actual ELSE 0 END), 0) AS piezas_actuales,
            COALESCE(SUM(CASE WHEN ca.numero_carril % 10 <> 0 THEN {$capacidad} ELSE 0 END), 0) AS capacidad_total,
            SUM(CASE WHEN ca.numero_carril % 10 <> 0 THEN 1 ELSE 0 END) AS carriles_configurados,
            SUM(CASE WHEN ca.numero_carril % 10 <> 0 AND ca.cantidad_actual <= 0 THEN 1 ELSE 0 END) AS carriles_vacios
        FROM vendingm.carriles ca
        WHERE ca.id_maquina = m.id_maquina
    ) stock";
}

function porcentajeSql(): string
{
    return "CASE WHEN stock.capacidad_total > 0
        THEN CAST(stock.piezas_actuales * 100.0 / stock.capacidad_total AS DECIMAL(6,2))
        ELSE CAST(0 AS DECIMAL(6,2)) END";
}

function prioridadSql(): string
{
    $pct = porcentajeSql();
    return "CASE
        WHEN stock.capacidad_total <= 0 OR {$pct} <= 15 THEN 'critica'
        WHEN {$pct} <= 35 THEN 'alta'
        WHEN {$pct} <= 60 THEN 'media'
        ELSE 'normal' END";
}

function dashboard(PDO $pdo): never
{
    $idRegion = inputInt('region');
    $idRuta = inputInt('ruta');
    $idChofer = inputInt('chofer');
    $prioridad = trim((string) ($_GET['prioridad'] ?? ''));
    $q = trim((string) ($_GET['q'] ?? ''));
    $asignacion = trim((string) ($_GET['asignacion'] ?? ''));
    $params = [];
    $where = ['m.rol_maquina = 1', 'm.id_estatus <> 3'];
    $scope = Auth::machineCondition('m', $params);
    if ($scope) $where[] = $scope;
    if ($idRegion) { $where[] = 'r.id_region = :id_region'; $params['id_region'] = $idRegion; }
    if ($idRuta) { $where[] = 'r.id_ruta = :id_ruta'; $params['id_ruta'] = $idRuta; }
    if ($idChofer) { $where[] = 'r.id_chofer = :id_chofer'; $params['id_chofer'] = $idChofer; }
    if (in_array($prioridad, ['critica', 'alta', 'media', 'normal'], true)) {
        $where[] = prioridadSql() . ' = :prioridad';
        $params['prioridad'] = $prioridad;
    }
    if ($asignacion === 'asignadas') $where[] = 'r.id_ruta IS NOT NULL';
    if ($asignacion === 'sin_ruta') $where[] = 'r.id_ruta IS NULL';
    if ($q !== '') {
        $where[] = '(m.numero_serie LIKE :q1 OR m.nombre_ubicacion LIKE :q2 OR m.nombre_maquina LIKE :q3 OR r.nombre LIKE :q4 OR reg.nombre LIKE :q5)';
        foreach (range(1, 5) as $i) $params['q' . $i] = '%' . $q . '%';
    }

    $apply = stockApply($pdo);
    $pct = porcentajeSql();
    $prioridadExpr = prioridadSql();
    $sqlBase = "
        FROM vendingm.maquinas m
        LEFT JOIN vendingm.abasto_ruta_maquinas rm ON rm.id_maquina = m.id_maquina
            AND EXISTS (SELECT 1 FROM vendingm.abasto_rutas ra WHERE ra.id_ruta = rm.id_ruta AND ra.estatus <> 'inactiva')
        LEFT JOIN vendingm.abasto_rutas r ON r.id_ruta = rm.id_ruta AND r.estatus <> 'inactiva'
        LEFT JOIN vendingm.abasto_regiones reg ON reg.id_region = r.id_region
        LEFT JOIN vendingm.abasto_choferes ch ON ch.id_chofer = r.id_chofer
        LEFT JOIN cat_SucursalClinica suc ON suc.id_SucursalClinica = m.id_sucursal_clinica
        LEFT JOIN cat_Estados est ON est.id_Estado = suc.id_Ciudad
        {$apply}
        WHERE " . implode(' AND ', $where);

    $stmt = $pdo->prepare("SELECT TOP 500
        m.id_maquina, m.numero_serie, m.nombre_maquina, m.nombre_ubicacion,
        COALESCE(NULLIF(est.st_Estado, ''), NULLIF(m.estado_geo, ''), 'Sin region') AS region_geografica,
        r.id_ruta, r.clave AS ruta_clave, r.nombre AS ruta_nombre,
        reg.id_region, reg.nombre AS region_nombre,
        ch.id_chofer, ch.nombre AS chofer_nombre,
        stock.piezas_actuales, stock.capacidad_total, stock.carriles_configurados, stock.carriles_vacios,
        {$pct} AS porcentaje_llenado,
        {$prioridadExpr} AS prioridad,
        CASE WHEN stock.capacidad_total > stock.piezas_actuales THEN stock.capacidad_total - stock.piezas_actuales ELSE 0 END AS piezas_faltantes
        {$sqlBase}
        ORDER BY CASE {$prioridadExpr} WHEN 'critica' THEN 0 WHEN 'alta' THEN 1 WHEN 'media' THEN 2 ELSE 3 END,
                 {$pct}, m.numero_serie");
    $stmt->execute($params);
    $maquinas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resumen = ['total' => count($maquinas), 'criticas' => 0, 'altas' => 0, 'medias' => 0, 'sin_ruta' => 0, 'piezas_faltantes' => 0];
    foreach ($maquinas as &$m) {
        $m['id_maquina'] = (int) $m['id_maquina'];
        $m['porcentaje_llenado'] = (float) $m['porcentaje_llenado'];
        $m['piezas_actuales'] = (int) $m['piezas_actuales'];
        $m['capacidad_total'] = (int) $m['capacidad_total'];
        $m['piezas_faltantes'] = (int) $m['piezas_faltantes'];
        if ($m['prioridad'] === 'critica') $resumen['criticas']++;
        if ($m['prioridad'] === 'alta') $resumen['altas']++;
        if ($m['prioridad'] === 'media') $resumen['medias']++;
        if ($m['id_ruta'] === null) $resumen['sin_ruta']++;
        $resumen['piezas_faltantes'] += $m['piezas_faltantes'];
    }
    unset($m);

    respuesta(['ok' => true, 'data' => ['resumen' => $resumen, 'maquinas' => $maquinas]]);
}

function catalogos(PDO $pdo): never
{
    $regiones = $pdo->query("SELECT id_region, clave, nombre, responsable, telefono, activo FROM vendingm.abasto_regiones WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
    $choferes = $pdo->query("SELECT ch.id_chofer, ch.id_region, ch.numero_empleado, ch.nombre, ch.telefono, ch.licencia,
        CONVERT(VARCHAR(10), ch.licencia_vigencia, 23) AS licencia_vigencia, ch.vehiculo, ch.placas,
        ch.capacidad_carga, ch.estatus, ch.notas, reg.nombre AS region_nombre
        FROM vendingm.abasto_choferes ch LEFT JOIN vendingm.abasto_regiones reg ON reg.id_region = ch.id_region
        WHERE ch.activo = 1 ORDER BY ch.nombre")->fetchAll(PDO::FETCH_ASSOC);
    $rutas = $pdo->query("SELECT r.id_ruta, r.id_region, r.id_chofer, r.clave, r.nombre, r.dias_operacion,
        CONVERT(VARCHAR(5), r.hora_salida, 108) AS hora_salida, r.capacidad_piezas, r.estatus, r.notas,
        reg.nombre AS region_nombre, ch.nombre AS chofer_nombre,
        COUNT(rm.id_maquina) AS total_maquinas
        FROM vendingm.abasto_rutas r
        INNER JOIN vendingm.abasto_regiones reg ON reg.id_region = r.id_region
        LEFT JOIN vendingm.abasto_choferes ch ON ch.id_chofer = r.id_chofer
        LEFT JOIN vendingm.abasto_ruta_maquinas rm ON rm.id_ruta = r.id_ruta
        GROUP BY r.id_ruta, r.id_region, r.id_chofer, r.clave, r.nombre, r.dias_operacion, r.hora_salida,
                 r.capacidad_piezas, r.estatus, r.notas, reg.nombre, ch.nombre
        ORDER BY reg.nombre, r.nombre")->fetchAll(PDO::FETCH_ASSOC);

    $params = [];
    $scope = Auth::machineScope('m', $params);
    $stmt = $pdo->prepare("SELECT m.id_maquina, m.numero_serie, m.nombre_ubicacion,
        COALESCE(NULLIF(est.st_Estado, ''), NULLIF(m.estado_geo, ''), 'Sin region') AS region_geografica,
        rm.id_ruta
        FROM vendingm.maquinas m
        LEFT JOIN cat_SucursalClinica suc ON suc.id_SucursalClinica = m.id_sucursal_clinica
        LEFT JOIN cat_Estados est ON est.id_Estado = suc.id_Ciudad
        LEFT JOIN vendingm.abasto_ruta_maquinas rm ON rm.id_maquina = m.id_maquina
            AND EXISTS (SELECT 1 FROM vendingm.abasto_rutas ra WHERE ra.id_ruta = rm.id_ruta AND ra.estatus <> 'inactiva')
        WHERE m.rol_maquina = 1 AND m.id_estatus <> 3 {$scope}
        ORDER BY region_geografica, m.numero_serie");
    $stmt->execute($params);
    $maquinas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    respuesta(['ok' => true, 'data' => compact('regiones', 'choferes', 'rutas', 'maquinas')]);
}

function jornadas(PDO $pdo): never
{
    $estado = trim((string) ($_GET['estado'] ?? 'activas'));
    $where = $estado === 'activas' ? "j.estatus IN ('programada','en_ruta')" : '1=1';
    if (in_array($estado, ['programada', 'en_ruta', 'completada', 'cancelada'], true)) $where = 'j.estatus = :estado';
    $params = str_contains($where, ':estado') ? ['estado' => $estado] : [];
    $scope = Auth::machineScope('m_scope', $params);
    if ($scope !== '') {
        $where .= " AND EXISTS (
            SELECT 1 FROM vendingm.abasto_paradas p_scope
            INNER JOIN vendingm.maquinas m_scope ON m_scope.id_maquina = p_scope.id_maquina
            WHERE p_scope.id_jornada = j.id_jornada {$scope}
        )";
    }
    $stmt = $pdo->prepare("SELECT TOP 100 j.id_jornada, j.id_ruta, j.id_chofer, CONVERT(VARCHAR(10), j.fecha_operacion, 23) fecha_operacion,
        j.estatus, j.fecha_inicio, j.fecha_fin, j.piezas_cargadas, j.piezas_entregadas, j.observaciones,
        r.clave ruta_clave, r.nombre ruta_nombre, reg.nombre region_nombre, ch.nombre chofer_nombre,
        COUNT(p.id_parada) total_paradas,
        SUM(CASE WHEN p.estatus = 'atendida' THEN 1 ELSE 0 END) paradas_atendidas,
        SUM(CASE WHEN p.prioridad_snapshot = 'critica' THEN 1 ELSE 0 END) paradas_criticas
        FROM vendingm.abasto_jornadas j
        INNER JOIN vendingm.abasto_rutas r ON r.id_ruta = j.id_ruta
        INNER JOIN vendingm.abasto_regiones reg ON reg.id_region = r.id_region
        LEFT JOIN vendingm.abasto_choferes ch ON ch.id_chofer = j.id_chofer
        LEFT JOIN vendingm.abasto_paradas p ON p.id_jornada = j.id_jornada
        WHERE {$where}
        GROUP BY j.id_jornada, j.id_ruta, j.id_chofer, j.fecha_operacion, j.estatus, j.fecha_inicio, j.fecha_fin,
                 j.piezas_cargadas, j.piezas_entregadas, j.observaciones, r.clave, r.nombre, reg.nombre, ch.nombre
        ORDER BY j.fecha_operacion DESC, j.id_jornada DESC");
    $stmt->execute($params);
    respuesta(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function detalleJornada(PDO $pdo): never
{
    $id = inputInt('id');
    if (!$id) respuesta(['ok' => false, 'error' => 'Jornada no valida'], 422);
    $params = ['id' => $id];
    $scope = Auth::machineScope('m', $params);
    $stmt = $pdo->prepare("SELECT p.id_parada, p.id_jornada, p.id_maquina, p.orden_visita,
        p.prioridad_snapshot, p.porcentaje_llenado_snapshot, p.piezas_sugeridas,
        p.piezas_entregadas, p.estatus, p.fecha_llegada, p.fecha_salida, p.incidencia,
        p.observaciones, m.numero_serie, m.nombre_ubicacion
        FROM vendingm.abasto_paradas p
        INNER JOIN vendingm.maquinas m ON m.id_maquina = p.id_maquina
        WHERE p.id_jornada = :id {$scope}
        ORDER BY p.orden_visita");
    $stmt->execute($params);
    respuesta(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function guardarRegion(PDO $pdo, array $b): never
{
    $id = filter_var($b['id_region'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $clave = strtoupper(trim((string) ($b['clave'] ?? '')));
    $nombre = trim((string) ($b['nombre'] ?? ''));
    if ($clave === '' || $nombre === '') respuesta(['ok' => false, 'error' => 'Clave y nombre de region son obligatorios'], 422);
    if ($id) {
        $stmt = $pdo->prepare('UPDATE vendingm.abasto_regiones SET clave=:clave,nombre=:nombre,responsable=:responsable,telefono=:telefono,fecha_actualizacion=SYSDATETIME() WHERE id_region=:id');
    } else {
        $stmt = $pdo->prepare('INSERT INTO vendingm.abasto_regiones(clave,nombre,responsable,telefono) OUTPUT INSERTED.id_region VALUES(:clave,:nombre,:responsable,:telefono)');
    }
    $params = ['clave' => mb_substr($clave, 0, 30), 'nombre' => mb_substr($nombre, 0, 120), 'responsable' => nulo($b['responsable'] ?? null), 'telefono' => nulo($b['telefono'] ?? null)];
    if ($id) $params['id'] = $id;
    $stmt->execute($params);
    $id = $id ?: (int) $stmt->fetchColumn();
    evento($pdo, 'region', $id, 'guardada', $nombre);
    respuesta(['ok' => true, 'data' => ['id_region' => $id]]);
}

function guardarChofer(PDO $pdo, array $b): never
{
    $id = filter_var($b['id_chofer'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $nombre = trim((string) ($b['nombre'] ?? ''));
    $estatus = (string) ($b['estatus'] ?? 'disponible');
    if ($nombre === '' || !in_array($estatus, ['disponible', 'en_ruta', 'descanso', 'inactivo'], true)) respuesta(['ok' => false, 'error' => 'Datos de chofer no validos'], 422);
    $campos = ['id_region','numero_empleado','telefono','licencia','licencia_vigencia','vehiculo','placas','capacidad_carga','notas'];
    $p = ['nombre' => mb_substr($nombre, 0, 160), 'estatus' => $estatus];
    foreach ($campos as $campo) $p[$campo] = nulo($b[$campo] ?? null);
    if ($id) {
        $p['id'] = $id;
        $stmt = $pdo->prepare('UPDATE vendingm.abasto_choferes SET id_region=:id_region,numero_empleado=:numero_empleado,nombre=:nombre,telefono=:telefono,licencia=:licencia,licencia_vigencia=:licencia_vigencia,vehiculo=:vehiculo,placas=:placas,capacidad_carga=:capacidad_carga,estatus=:estatus,notas=:notas,fecha_actualizacion=SYSDATETIME() WHERE id_chofer=:id');
    } else {
        $stmt = $pdo->prepare('INSERT INTO vendingm.abasto_choferes(id_region,numero_empleado,nombre,telefono,licencia,licencia_vigencia,vehiculo,placas,capacidad_carga,estatus,notas) OUTPUT INSERTED.id_chofer VALUES(:id_region,:numero_empleado,:nombre,:telefono,:licencia,:licencia_vigencia,:vehiculo,:placas,:capacidad_carga,:estatus,:notas)');
    }
    $stmt->execute($p);
    $id = $id ?: (int) $stmt->fetchColumn();
    evento($pdo, 'chofer', $id, 'guardado', $nombre);
    respuesta(['ok' => true, 'data' => ['id_chofer' => $id]]);
}

function guardarRuta(PDO $pdo, array $b): never
{
    $id = filter_var($b['id_ruta'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $idRegion = filter_var($b['id_region'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $idChofer = filter_var($b['id_chofer'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $clave = strtoupper(trim((string) ($b['clave'] ?? '')));
    $nombre = trim((string) ($b['nombre'] ?? ''));
    $maquinas = array_values(array_unique(array_filter(array_map('intval', (array) ($b['maquinas'] ?? [])))));
    if (!$idRegion || $clave === '' || $nombre === '') respuesta(['ok' => false, 'error' => 'Region, clave y nombre de ruta son obligatorios'], 422);
    if ($maquinas) {
        $marks = implode(',', array_fill(0, count($maquinas), '?'));
        $stmt = $pdo->prepare("SELECT TOP 1 m.numero_serie, r.nombre FROM vendingm.abasto_ruta_maquinas rm INNER JOIN vendingm.abasto_rutas r ON r.id_ruta=rm.id_ruta INNER JOIN vendingm.maquinas m ON m.id_maquina=rm.id_maquina WHERE rm.id_maquina IN ({$marks}) AND r.estatus <> 'inactiva' AND r.id_ruta <> ?");
        $stmt->execute([...$maquinas, $id ?: 0]);
        if ($conflicto = $stmt->fetch(PDO::FETCH_ASSOC)) respuesta(['ok' => false, 'error' => "La maquina {$conflicto['numero_serie']} ya pertenece a la ruta {$conflicto['nombre']}"], 422);
    }
    $pdo->beginTransaction();
    try {
        $p = ['region'=>$idRegion,'chofer'=>$idChofer,'clave'=>mb_substr($clave,0,30),'nombre'=>mb_substr($nombre,0,140),'dias'=>nulo($b['dias_operacion']??null),'hora'=>nulo($b['hora_salida']??null),'capacidad'=>nulo($b['capacidad_piezas']??null),'notas'=>nulo($b['notas']??null)];
        if ($id) {
            $p['id']=$id;
            $stmt=$pdo->prepare("UPDATE vendingm.abasto_rutas SET id_region=:region,id_chofer=:chofer,clave=:clave,nombre=:nombre,dias_operacion=:dias,hora_salida=:hora,capacidad_piezas=:capacidad,notas=:notas,fecha_actualizacion=SYSDATETIME() WHERE id_ruta=:id");
        } else {
            $stmt=$pdo->prepare("INSERT INTO vendingm.abasto_rutas(id_region,id_chofer,clave,nombre,dias_operacion,hora_salida,capacidad_piezas,notas) OUTPUT INSERTED.id_ruta VALUES(:region,:chofer,:clave,:nombre,:dias,:hora,:capacidad,:notas)");
        }
        $stmt->execute($p); $id=$id ?: (int)$stmt->fetchColumn();
        $pdo->prepare('DELETE FROM vendingm.abasto_ruta_maquinas WHERE id_ruta=?')->execute([$id]);
        $ins=$pdo->prepare('INSERT INTO vendingm.abasto_ruta_maquinas(id_ruta,id_maquina,orden_base) VALUES(?,?,?)');
        foreach ($maquinas as $i=>$idMaquina) $ins->execute([$id,$idMaquina,$i+1]);
        evento($pdo,'ruta',$id,'guardada',$nombre . ' | ' . count($maquinas) . ' maquinas');
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    respuesta(['ok'=>true,'data'=>['id_ruta'=>$id]]);
}

function generarJornada(PDO $pdo, array $b): never
{
    $idRuta = filter_var($b['id_ruta'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $fecha = trim((string) ($b['fecha_operacion'] ?? date('Y-m-d')));
    if (!$idRuta || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) respuesta(['ok'=>false,'error'=>'Ruta y fecha son obligatorias'],422);
    $ruta=$pdo->prepare("SELECT id_ruta,id_chofer FROM vendingm.abasto_rutas WHERE id_ruta=? AND estatus='activa'"); $ruta->execute([$idRuta]); $ruta=$ruta->fetch(PDO::FETCH_ASSOC);
    if (!$ruta) respuesta(['ok'=>false,'error'=>'La ruta no existe o no esta activa'],422);
    $ya=$pdo->prepare("SELECT id_jornada FROM vendingm.abasto_jornadas WHERE id_ruta=? AND fecha_operacion=? AND estatus<>'cancelada'"); $ya->execute([$idRuta,$fecha]);
    if ($ya->fetchColumn()) respuesta(['ok'=>false,'error'=>'Ya existe una jornada activa de esta ruta para la fecha indicada'],422);
    if ($ruta['id_chofer'] !== null) {
        $ocupado=$pdo->prepare("SELECT TOP 1 r.nombre FROM vendingm.abasto_jornadas j INNER JOIN vendingm.abasto_rutas r ON r.id_ruta=j.id_ruta WHERE j.id_chofer=? AND j.fecha_operacion=? AND j.estatus<>'cancelada'");
        $ocupado->execute([$ruta['id_chofer'],$fecha]);
        if ($otra=$ocupado->fetchColumn()) respuesta(['ok'=>false,'error'=>"El chofer ya tiene la ruta {$otra} programada en esa fecha"],422);
    }
    $apply=stockApply($pdo); $pct=porcentajeSql(); $pri=prioridadSql();
    $st=$pdo->prepare("SELECT m.id_maquina,{$pct} pct,{$pri} prioridad,CASE WHEN stock.capacidad_total>stock.piezas_actuales THEN stock.capacidad_total-stock.piezas_actuales ELSE 0 END faltantes
        FROM vendingm.abasto_ruta_maquinas rm INNER JOIN vendingm.maquinas m ON m.id_maquina=rm.id_maquina {$apply}
        WHERE rm.id_ruta=? ORDER BY CASE {$pri} WHEN 'critica' THEN 0 WHEN 'alta' THEN 1 WHEN 'media' THEN 2 ELSE 3 END, {$pct}, COALESCE(rm.orden_base,9999)");
    $st->execute([$idRuta]); $paradas=$st->fetchAll(PDO::FETCH_ASSOC);
    if (!$paradas) respuesta(['ok'=>false,'error'=>'La ruta no tiene maquinas asignadas'],422);
    $pdo->beginTransaction();
    try {
        $ins=$pdo->prepare('INSERT INTO vendingm.abasto_jornadas(id_ruta,id_chofer,fecha_operacion,observaciones,creado_por) OUTPUT INSERTED.id_jornada VALUES(?,?,?,?,?)');
        $ins->execute([$idRuta,$ruta['id_chofer'],$fecha,nulo($b['observaciones']??null),Auth::id()]); $id=(int)$ins->fetchColumn();
        $ip=$pdo->prepare('INSERT INTO vendingm.abasto_paradas(id_jornada,id_maquina,orden_visita,prioridad_snapshot,porcentaje_llenado_snapshot,piezas_sugeridas) VALUES(?,?,?,?,?,?)');
        foreach($paradas as $i=>$p) $ip->execute([$id,$p['id_maquina'],$i+1,$p['prioridad'],$p['pct'],$p['faltantes']]);
        evento($pdo,'jornada',$id,'generada',count($paradas).' paradas priorizadas'); $pdo->commit();
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    respuesta(['ok'=>true,'data'=>['id_jornada'=>$id,'paradas'=>count($paradas)]]);
}

function estatusJornada(PDO $pdo,array $b): never
{
    $id=filter_var($b['id_jornada']??null,FILTER_VALIDATE_INT)?:null; $estado=(string)($b['estatus']??'');
    if(!$id||!in_array($estado,['programada','en_ruta','completada','cancelada'],true)) respuesta(['ok'=>false,'error'=>'Estatus no valido'],422);
    $pdo->beginTransaction();
    try {
        $choferStmt=$pdo->prepare('SELECT id_chofer FROM vendingm.abasto_jornadas WHERE id_jornada=?'); $choferStmt->execute([$id]); $idChofer=$choferStmt->fetchColumn();
        $sql="UPDATE vendingm.abasto_jornadas SET estatus=:e,fecha_inicio=CASE WHEN :e2='en_ruta' AND fecha_inicio IS NULL THEN SYSDATETIME() ELSE fecha_inicio END,fecha_fin=CASE WHEN :e3='completada' THEN SYSDATETIME() ELSE fecha_fin END,fecha_actualizacion=SYSDATETIME() WHERE id_jornada=:id";
        $pdo->prepare($sql)->execute(['e'=>$estado,'e2'=>$estado,'e3'=>$estado,'id'=>$id]);
        if ($idChofer && $estado === 'en_ruta') $pdo->prepare("UPDATE vendingm.abasto_choferes SET estatus='en_ruta',fecha_actualizacion=SYSDATETIME() WHERE id_chofer=?")->execute([$idChofer]);
        if ($idChofer && in_array($estado,['completada','cancelada'],true)) $pdo->prepare("UPDATE vendingm.abasto_choferes SET estatus='disponible',fecha_actualizacion=SYSDATETIME() WHERE id_chofer=? AND NOT EXISTS(SELECT 1 FROM vendingm.abasto_jornadas WHERE id_chofer=? AND estatus='en_ruta')")->execute([$idChofer,$idChofer]);
        evento($pdo,'jornada',$id,'estatus',$estado); $pdo->commit();
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    respuesta(['ok'=>true,'data'=>['id_jornada'=>$id,'estatus'=>$estado]]);
}

function actualizarParada(PDO $pdo,array $b): never
{
    $id=filter_var($b['id_parada']??null,FILTER_VALIDATE_INT)?:null; $estado=(string)($b['estatus']??'');
    if(!$id||!in_array($estado,['pendiente','en_sitio','atendida','omitida','incidencia'],true)) respuesta(['ok'=>false,'error'=>'Parada no valida'],422);
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare("UPDATE vendingm.abasto_paradas SET estatus=:e,piezas_entregadas=:p,incidencia=:i,observaciones=:o,fecha_llegada=CASE WHEN :e2='en_sitio' AND fecha_llegada IS NULL THEN SYSDATETIME() ELSE fecha_llegada END,fecha_salida=CASE WHEN :e3 IN ('atendida','omitida','incidencia') THEN SYSDATETIME() ELSE fecha_salida END,fecha_actualizacion=SYSDATETIME() WHERE id_parada=:id");
        $stmt->execute(['e'=>$estado,'p'=>nulo($b['piezas_entregadas']??null),'i'=>nulo($b['incidencia']??null),'o'=>nulo($b['observaciones']??null),'e2'=>$estado,'e3'=>$estado,'id'=>$id]);
        $pdo->prepare("UPDATE j SET piezas_entregadas=x.total,fecha_actualizacion=SYSDATETIME() FROM vendingm.abasto_jornadas j CROSS APPLY(SELECT COALESCE(SUM(p.piezas_entregadas),0) total FROM vendingm.abasto_paradas p WHERE p.id_jornada=j.id_jornada)x WHERE j.id_jornada=(SELECT id_jornada FROM vendingm.abasto_paradas WHERE id_parada=?)")->execute([$id]);
        evento($pdo,'parada',$id,'estatus',$estado); $pdo->commit();
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    respuesta(['ok'=>true,'data'=>['id_parada'=>$id,'estatus'=>$estado]]);
}

function evento(PDO $pdo,string $entidad,int $id,string $tipo,?string $detalle=null): void
{
    $pdo->prepare('INSERT INTO vendingm.abasto_eventos(entidad,id_entidad,tipo_evento,detalle,id_usuario) VALUES(?,?,?,?,?)')->execute([$entidad,$id,$tipo,$detalle,Auth::id()]);
}

function nulo(mixed $valor): mixed
{
    if ($valor === null) return null;
    if (is_string($valor)) { $valor=trim($valor); return $valor===''?null:$valor; }
    return $valor;
}
