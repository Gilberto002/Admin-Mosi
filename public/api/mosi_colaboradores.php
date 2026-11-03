<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Security\Security;
use App\Telemedicina\LegacyConnection;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

/** Devuelve un identificador SQL Server entre corchetes, obtenido del catálogo. */
function sybiTabla(\PDO $pdo, string $nombre, array $preferidos = ['fernandoruiz', 'dbo']): string
{
    $stmt = $pdo->prepare("SELECT QUOTENAME(SCHEMA_NAME(schema_id))+'.'+QUOTENAME(name) tabla,SCHEMA_NAME(schema_id) esquema FROM sys.tables WHERE name=:nombre");
    $stmt->execute(['nombre' => $nombre]);
    $tablas = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($preferidos as $esquema) {
        foreach ($tablas as $tabla) {
            if (strcasecmp((string) $tabla['esquema'], $esquema) === 0) return (string) $tabla['tabla'];
        }
    }
    if ($tablas) return (string) $tablas[0]['tabla'];
    throw new RuntimeException("No se encontró la tabla requerida {$nombre} en Mosy.SYBI.");
}

function sybiGrupo(mixed $valor): int
{
    $grupo = filter_var($valor, FILTER_VALIDATE_INT);
    if (!in_array($grupo, [20, 23, 24], true)) throw new InvalidArgumentException('Grupo clínico no válido.');
    return $grupo;
}

function sybiDia(string $dia): ?string
{
    $normalizado = iconv('UTF-8', 'ASCII//TRANSLIT', $dia) ?: $dia;
    $dias = [
        'lunes' => 'Lunes', 'martes' => 'Martes', 'miercoles' => 'Miercoles',
        'jueves' => 'Jueves', 'viernes' => 'Viernes', 'sabado' => 'Sabado',
        'domingo' => 'Domingo',
    ];
    return $dias[strtolower(trim($normalizado))] ?? null;
}

try {
    $legacy = LegacyConnection::get();
    $grupo = sybiGrupo($_GET['grupo'] ?? 20);
    $medicos = sybiTabla($legacy, 'cat_Medicos');
    $sucursales = sybiTabla($legacy, 'cat_SucursalClinica');
    $permisos = sybiTabla($legacy, 'tbl_permisosVending', ['dbo', 'fernandoruiz']);
    $notificaciones = sybiTabla($legacy, 'tbl_notificaciones');
    $consultas = sybiTabla($legacy, 'tbl_EvCitasUsuariosWeb');
    $horarios = sybiTabla($legacy, 'tbl_HorariosMedicos');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) throw new InvalidArgumentException('Solicitud no válida.');
        $grupo = sybiGrupo($body['grupo'] ?? $grupo);
        $idMedico = filter_var($body['id_medico'] ?? null, FILTER_VALIDATE_INT);
        if (!$idMedico) throw new InvalidArgumentException('Selecciona un colaborador válido.');
        $accion = trim((string) ($body['accion'] ?? ''));

        $existe = $legacy->prepare("SELECT COUNT(*) FROM {$permisos} WHERE id_medico=:medico AND i_grupo=:grupo");
        $existe->execute(['medico' => $idMedico, 'grupo' => $grupo]);
        if (!(int) $existe->fetchColumn()) throw new RuntimeException('El colaborador no pertenece al grupo seleccionado.');

        if ($accion === 'toggle_active') {
            $stmt = $legacy->prepare("UPDATE {$permisos} SET i_activo=CASE WHEN i_activo=1 THEN 0 ELSE 1 END,dt_fechaActualizar=GETDATE() WHERE id_medico=:medico AND i_grupo=:grupo");
            $stmt->execute(['medico' => $idMedico, 'grupo' => $grupo]);
        } elseif ($accion === 'toggle_session') {
            $stmt = $legacy->prepare("UPDATE {$permisos} SET i_activo_consulta=CASE WHEN i_activo_consulta=1 THEN 0 ELSE 1 END,dt_fechaActivo=CASE WHEN i_activo_consulta=1 THEN dt_fechaActivo ELSE GETDATE() END,dt_fechaActualizar=GETDATE() WHERE id_medico=:medico AND i_grupo=:grupo");
            $stmt->execute(['medico' => $idMedico, 'grupo' => $grupo]);
        } elseif ($accion === 'save_access') {
            $plataforma = trim((string) ($body['plataforma'] ?? ''));
            if (!in_array($plataforma, ['ALL', 'APP', 'Kiosco'], true)) throw new InvalidArgumentException('Plataforma no válida.');
            $solicitados = array_values(array_unique(array_filter(array_map(static fn($v): string => trim((string) $v), (array) ($body['mosis'] ?? [])), static fn(string $v): bool => $v !== '')));
            if (count($solicitados) > 250) throw new InvalidArgumentException('Demasiadas MOSI seleccionadas.');
            $catalogoStmt = $legacy->query("SELECT DISTINCT LTRIM(RTRIM(CONVERT(NVARCHAR(100),st_vendingMachine))) id FROM {$sucursales} WHERE st_vendingMachine IS NOT NULL AND LTRIM(RTRIM(CONVERT(NVARCHAR(100),st_vendingMachine)))<>'' AND i_Activo=1");
            $permitidos = array_fill_keys(array_map('strval', $catalogoStmt->fetchAll(\PDO::FETCH_COLUMN)), true);
            foreach ($solicitados as $id) {
                if (strlen($id) > 100 || !isset($permitidos[$id])) throw new InvalidArgumentException('Una de las MOSI seleccionadas ya no está disponible.');
            }
            $stmt = $legacy->prepare("UPDATE {$permisos} SET st_dispositivo=:plataforma,st_vendings=:mosis,dt_fechaActualizar=GETDATE() WHERE id_medico=:medico AND i_grupo=:grupo");
            $stmt->execute(['plataforma' => $plataforma, 'mosis' => $solicitados ? implode(',', $solicitados) : null, 'medico' => $idMedico, 'grupo' => $grupo]);
        } elseif ($accion === 'save_platform') {
            $plataforma = trim((string) ($body['plataforma'] ?? ''));
            if (!in_array($plataforma, ['ALL', 'APP', 'Kiosco'], true)) throw new InvalidArgumentException('Plataforma no válida.');
            $stmt = $legacy->prepare("UPDATE {$permisos} SET st_dispositivo=:plataforma,dt_fechaActualizar=GETDATE() WHERE id_medico=:medico AND i_grupo=:grupo");
            $stmt->execute(['plataforma' => $plataforma, 'medico' => $idMedico, 'grupo' => $grupo]);
        } elseif ($accion === 'save_assignments') {
            $solicitados = array_values(array_unique(array_filter(array_map(static fn($v): string => trim((string) $v), (array) ($body['mosis'] ?? [])), static fn(string $v): bool => $v !== '')));
            if (count($solicitados) > 250) throw new InvalidArgumentException('Demasiadas MOSI seleccionadas.');
            $catalogoStmt = $legacy->query("SELECT DISTINCT LTRIM(RTRIM(CONVERT(NVARCHAR(100),st_vendingMachine))) id FROM {$sucursales} WHERE st_vendingMachine IS NOT NULL AND LTRIM(RTRIM(CONVERT(NVARCHAR(100),st_vendingMachine)))<>'' AND i_Activo=1");
            $permitidos = array_fill_keys(array_map('strval', $catalogoStmt->fetchAll(\PDO::FETCH_COLUMN)), true);
            foreach ($solicitados as $id) {
                if (strlen($id) > 100 || !isset($permitidos[$id])) throw new InvalidArgumentException('Una de las MOSI seleccionadas ya no está disponible.');
            }
            $stmt = $legacy->prepare("UPDATE {$permisos} SET st_vendings=:mosis,dt_fechaActualizar=GETDATE() WHERE id_medico=:medico AND i_grupo=:grupo");
            $stmt->execute(['mosis' => $solicitados ? implode(',', $solicitados) : null, 'medico' => $idMedico, 'grupo' => $grupo]);
        } elseif ($accion === 'save_schedule') {
            $dias = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'];
            $nuevosHorarios = (array) ($body['horarios'] ?? []);
            $existeHorario = $legacy->prepare("SELECT COUNT(*) FROM {$horarios} WHERE id_Medico=:medico AND id_TipoPermiso=:tipo AND st_Descripcion COLLATE Latin1_General_CI_AI=:dia COLLATE Latin1_General_CI_AI");
            $actualizarHorario = $legacy->prepare("UPDATE {$horarios} SET dt_FechaInicio=CONVERT(DATETIME,:inicio,108),dt_FechaFin=CONVERT(DATETIME,:fin,108) WHERE id_Medico=:medico AND id_TipoPermiso=:tipo AND st_Descripcion COLLATE Latin1_General_CI_AI=:dia COLLATE Latin1_General_CI_AI");
            $insertarHorario = $legacy->prepare("INSERT INTO {$horarios}(id_Medico,id_TipoPermiso,st_Descripcion,dt_FechaInicio,dt_FechaFin) VALUES(:medico,:tipo,:dia,CONVERT(DATETIME,:inicio,108),CONVERT(DATETIME,:fin,108))");
            $eliminarHorario = $legacy->prepare("DELETE FROM {$horarios} WHERE id_Medico=:medico AND id_TipoPermiso=:tipo AND st_Descripcion COLLATE Latin1_General_CI_AI=:dia COLLATE Latin1_General_CI_AI");
            $legacy->beginTransaction();
            foreach ($dias as $dia) {
                foreach (['laboral' => 1, 'comida' => 2] as $tipoNombre => $tipoId) {
                    $item = (array) ($nuevosHorarios[$dia][$tipoNombre] ?? []);
                    $inicio = trim((string) ($item['inicio'] ?? ''));
                    $fin = trim((string) ($item['fin'] ?? ''));
                    $base = ['medico' => $idMedico, 'tipo' => $tipoId, 'dia' => $dia];
                    if ($inicio === '' && $fin === '') {
                        $eliminarHorario->execute($base);
                        continue;
                    }
                    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $inicio) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $fin)) {
                        throw new InvalidArgumentException("Completa correctamente el horario de {$dia}.");
                    }
                    if ($inicio >= $fin) throw new InvalidArgumentException("La hora final debe ser posterior a la inicial en {$dia}.");
                    $existeHorario->execute($base);
                    $valores = $base + ['inicio' => $inicio, 'fin' => $fin];
                    if ((int) $existeHorario->fetchColumn()) $actualizarHorario->execute($valores);
                    else $insertarHorario->execute($valores);
                }
            }
            $legacy->commit();
        } elseif ($accion === 'release_attention') {
            $legacy->beginTransaction();
            $liberarNotificacion = $legacy->prepare("UPDATE {$notificaciones} SET i_notificacion=0 WHERE id_medico=:medico AND i_notificacion IN(1,2) AND CAST(dt_fecha_registro AS date)=CAST(GETDATE() AS date)");
            $liberarNotificacion->execute(['medico' => $idMedico]);
            $notificacionesLiberadas = $liberarNotificacion->rowCount();
            $liberarConsulta = $legacy->prepare("UPDATE {$consultas} SET EnConsulta=0 WHERE id_medico=:medico AND EnConsulta IN(1,2) AND CAST(dt_fechaRegistro AS date)=CAST(GETDATE() AS date)");
            $liberarConsulta->execute(['medico' => $idMedico]);
            $consultasLiberadas = $liberarConsulta->rowCount();
            $legacy->commit();
        } else {
            throw new InvalidArgumentException('Acción no permitida.');
        }

        echo json_encode(['ok' => true, 'data' => [
            'id_medico' => (int) $idMedico,
            'accion' => $accion,
            'notificaciones_liberadas' => $notificacionesLiberadas ?? 0,
            'consultas_liberadas' => $consultasLiberadas ?? 0,
        ]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (($_GET['accion'] ?? '') === 'schedule') {
        $idMedico = filter_input(INPUT_GET, 'id_medico', FILTER_VALIDATE_INT);
        if (!$idMedico) throw new InvalidArgumentException('Selecciona un colaborador valido.');
        $existe = $legacy->prepare("SELECT COUNT(*) FROM {$permisos} WHERE id_medico=:medico AND i_grupo=:grupo");
        $existe->execute(['medico' => $idMedico, 'grupo' => $grupo]);
        if (!(int) $existe->fetchColumn()) throw new RuntimeException('El colaborador no pertenece al grupo seleccionado.');
        $stmt = $legacy->prepare("SELECT id_TipoPermiso,st_Descripcion,LEFT(CONVERT(VARCHAR(8),dt_FechaInicio,108),5) inicio,LEFT(CONVERT(VARCHAR(8),dt_FechaFin,108),5) fin FROM {$horarios} WHERE id_Medico=:medico AND id_TipoPermiso IN(1,2)");
        $stmt->execute(['medico' => $idMedico]);
        $semana = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $dia = sybiDia((string) $row['st_Descripcion']);
            if (!$dia) continue;
            $tipo = (int) $row['id_TipoPermiso'] === 2 ? 'comida' : 'laboral';
            $semana[$dia][$tipo] = ['inicio' => $row['inicio'], 'fin' => $row['fin']];
        }
        echo json_encode(['ok' => true, 'data' => ['id_medico' => $idMedico, 'horarios' => $semana]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dia = "CASE DATEPART(WEEKDAY,GETDATE()) WHEN 1 THEN 'Domingo' WHEN 2 THEN 'Lunes' WHEN 3 THEN 'Martes' WHEN 4 THEN 'Miercoles' WHEN 5 THEN 'Jueves' WHEN 6 THEN 'Viernes' WHEN 7 THEN 'Sabado' END";
    $sql = "SELECT md.id_Medico,md.st_Nombre,md.st_ApellidoPaterno,md.st_ApellidoMaterno,
        cl.st_Nombre sucursal,pr.st_vendings,pr.i_activo_consulta,pr.st_dispositivo,pr.i_activo,
        CONVERT(VARCHAR,pr.dt_fechaActivo,120) dt_fechaActivo,
        ISNULL(notif.ocupado,0) estatus_notificacion,notif.fecha_notificacion,
        ISNULL(cons.en_consulta,0) estatus_consulta,
        CASE WHEN pr.i_activo_consulta=1 AND CAST(pr.dt_fechaActivo AS date)=CAST(GETDATE() AS date) THEN 1 ELSE 0 END estatus_sesion,
        horario.laboral_inicio,horario.laboral_fin,horario.comida_inicio,horario.comida_fin
        FROM {$medicos} md
        LEFT JOIN {$sucursales} cl ON cl.id_SucursalClinica=md.id_SucursalClinica
        INNER JOIN {$permisos} pr ON pr.id_medico=md.id_Medico
        OUTER APPLY (
            SELECT TOP 1 CASE WHEN n.i_notificacion IN(1,2) THEN 1 ELSE 0 END ocupado,
                n.dt_fecha_registro fecha_notificacion
            FROM {$notificaciones} n WHERE n.id_medico=md.id_Medico AND CAST(n.dt_fecha_registro AS date)=CAST(GETDATE() AS date)
            ORDER BY n.dt_fecha_registro DESC
        ) notif
        OUTER APPLY (
            SELECT TOP 1 CASE WHEN c.EnConsulta IN(1,2) THEN 1 ELSE 0 END en_consulta
            FROM {$consultas} c WHERE c.id_medico=md.id_Medico AND CAST(c.dt_fechaRegistro AS date)=CAST(GETDATE() AS date)
            ORDER BY c.dt_fechaRegistro DESC
        ) cons
        OUTER APPLY (
            SELECT
                MAX(CASE WHEN h.id_TipoPermiso=1 THEN LEFT(CONVERT(VARCHAR(8),h.dt_FechaInicio,108),5) END) laboral_inicio,
                MAX(CASE WHEN h.id_TipoPermiso=1 THEN LEFT(CONVERT(VARCHAR(8),h.dt_FechaFin,108),5) END) laboral_fin,
                MAX(CASE WHEN h.id_TipoPermiso=2 THEN LEFT(CONVERT(VARCHAR(8),h.dt_FechaInicio,108),5) END) comida_inicio,
                MAX(CASE WHEN h.id_TipoPermiso=2 THEN LEFT(CONVERT(VARCHAR(8),h.dt_FechaFin,108),5) END) comida_fin
            FROM {$horarios} h WHERE h.id_Medico=md.id_Medico AND h.st_Descripcion COLLATE Latin1_General_CI_AI={$dia} COLLATE Latin1_General_CI_AI
        ) horario
        WHERE md.i_videollamada=1 AND md.i_Activo=1 AND pr.i_grupo=:grupo
        ORDER BY md.st_Nombre,md.st_ApellidoPaterno,md.st_ApellidoMaterno";
    $stmt = $legacy->prepare($sql);
    $stmt->execute(['grupo' => $grupo]);
    $data = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $asignaciones = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;|]+/', (string) ($row['st_vendings'] ?? '')) ?: []))));
        $data[] = [
            'id_medico' => (int) $row['id_Medico'],
            'nombre' => trim(implode(' ', array_filter([$row['st_Nombre'], $row['st_ApellidoPaterno'], $row['st_ApellidoMaterno']]))),
            'sucursal' => (string) ($row['sucursal'] ?? ''),
            'activo' => (int) $row['i_activo'],
            'sesion_habilitada' => (int) $row['i_activo_consulta'],
            'sesion_hoy' => (int) $row['estatus_sesion'],
            'en_notificacion' => (int) $row['estatus_notificacion'],
            'fecha_notificacion' => $row['fecha_notificacion'],
            'en_consulta' => (int) $row['estatus_consulta'],
            'plataforma' => (string) ($row['st_dispositivo'] ?? 'ALL'),
            'fecha_actividad' => $row['dt_fechaActivo'],
            'mosis' => $asignaciones,
            'horario' => ['inicio' => $row['laboral_inicio'], 'fin' => $row['laboral_fin']],
            'comida' => ['inicio' => $row['comida_inicio'], 'fin' => $row['comida_fin']],
        ];
    }

    $catalogo = $legacy->query("SELECT id_SucursalClinica,LTRIM(RTRIM(CONVERT(NVARCHAR(100),st_vendingMachine))) id_mosi,st_Nombre nombre FROM {$sucursales} WHERE st_vendingMachine IS NOT NULL AND LTRIM(RTRIM(CONVERT(NVARCHAR(100),st_vendingMachine)))<>'' AND i_Activo=1 ORDER BY st_Nombre")->fetchAll(\PDO::FETCH_ASSOC);
    $stats = ['total' => count($data), 'activos' => 0, 'disponibles' => 0, 'en_consulta' => 0, 'desactivados' => 0, 'sin_mosi' => 0];
    foreach ($data as $persona) {
        $stats[$persona['activo'] ? 'activos' : 'desactivados']++;
        if (!$persona['mosis']) $stats['sin_mosi']++;
        if ($persona['activo'] && ($persona['en_consulta'] || $persona['en_notificacion'])) $stats['en_consulta']++;
        elseif ($persona['activo'] && $persona['sesion_hoy']) $stats['disponibles']++;
    }

    echo json_encode(['ok' => true, 'data' => $data, 'stats' => $stats, 'catalogos' => ['mosis' => $catalogo], 'grupo' => $grupo], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException|RuntimeException $e) {
    if (isset($legacy) && $legacy->inTransaction()) $legacy->rollBack();
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($legacy) && $legacy->inTransaction()) $legacy->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => Security::publicError($e, 'mosi_colaboradores')], JSON_UNESCAPED_UNICODE);
}
