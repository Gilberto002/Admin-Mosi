<?php
session_start();
require('../../../../db.php');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

$accion = NULL;

if ((isset($_POST['accion']) and $_POST['accion'] != '')) {
    $accion = $_POST['accion'];
} else if ((isset($_GET['accion']) and $_GET['accion'] != '')) {
    $accion = $_GET['accion'];
}

$json = array();

switch ($accion) {
    case 'medicos':
        $qryM = "SELECT md.id_Medico, md.st_Nombre as nomMed, md.st_ApellidoPaterno, md.st_ApellidoMaterno, cl.st_Nombre as nomSuc, pr.st_vendings,
                pr.i_activo_consulta, pr.id_permisosVending, pr.st_dispositivo, pr.i_activo, CONVERT(VARCHAR, pr.dt_fechaActivo, 120) AS dt_fechaActivo
                FROM cat_Medicos md
                LEFT JOIN cat_SucursalClinica cl ON cl.id_SucursalClinica = md.id_SucursalClinica
                LEFT JOIN dbo.tbl_permisosVending pr ON pr.id_medico = md.id_Medico
                WHERE md.i_videollamada = 1
                AND pr.i_grupo = '" . $_GET['grupo'] . "'
                AND md.i_Activo = 1";
        $rqryM = mssql_query($qryM);
        while ($rowMedico = mssql_fetch_array($rqryM)) {
            $nomMed = utf8_encode($rowMedico['nomMed']) . ' ' . utf8_encode($rowMedico['st_ApellidoPaterno']) . ' ' . utf8_encode($rowMedico['st_ApellidoMaterno']);
            $nomSuc = utf8_encode($rowMedico['nomSuc']);
            $estatusNot = consultarNotificacion($rowMedico['id_Medico']);
            $estatusCons = consultarConsultasMed($rowMedico['id_Medico']);
            $estatusSesion = consultarSesion($rowMedico['id_Medico']);
            $horaLaboral = consultarHorarioLaboral($rowMedico['id_Medico']);
            $horaComida = consultarHorarioComida($rowMedico['id_Medico']);
            $json[] = array(
                'id' => $rowMedico['id_Medico'],
                'nombre' => $nomMed,
                'sucursal' => $nomSuc,
                'estatusActivo' => $rowMedico['i_activo'],
                'dt_fechaActivo' => $rowMedico['dt_fechaActivo'],
                'dispositvo' => $rowMedico['st_dispositivo'],
                'estatusNot' => $estatusNot,
                'estatusCons' => $estatusCons,
                'estatusSesion' => $estatusSesion,
                'st_vendings' => $rowMedico['st_vendings'],
                'horaLaboral' => $horaLaboral,
                'horaComida' => $horaComida
            );
        }

        echo json_encode($json);
        break;
    case 'vaciarNotificacion':
        $upNot = "UPDATE tbl_notificaciones
                    SET i_notificacion = 0
                    WHERE i_notificacion IN (1, 2)
                    AND dt_fecha_registro = (
                        SELECT MAX(dt_fecha_registro)
                        FROM tbl_notificaciones
                        WHERE id_medico = '" . $_POST['id_medico'] . "'
                        AND CAST(dt_fecha_registro AS DATE) = CAST(GETDATE() AS DATE)
                    );";
        $rupNot = mssql_query($upNot);
        if ($rupNot) {
            $json[] = array('status' => 200);
        } else {
            $json[] = array('status' => 201);
        }
        echo json_encode($json);
        break;
    case 'vaciarConsulta':
        $upCon = " UPDATE tbl_EvCitasUsuariosWeb
                    SET EnConsulta = 0
                    WHERE EnConsulta IN (1,2)
                    AND dt_fechaRegistro = (
                        SELECT MAX(dt_fechaRegistro)
                        FROM tbl_EvCitasUsuariosWeb
                        WHERE id_medico = '" . $_POST['id_medico'] . "'
                        AND CONVERT(DATE, dt_fechaRegistro) = CONVERT(DATE, GETDATE())
                    )";
        $rupCon = mssql_query($upCon);
        if ($rupCon) {
            $json[] = array('status' => 200);
        } else {
            $json[] = array('status' => 201);
        }
        echo json_encode($json);
        break;
    case 'deshabilitar':
        $qrySelc = " SELECT i_activo FROM dbo.tbl_permisosVending
                    WHERE id_medico = '" . $_POST['id_medico'] . "'";
        $rqrySelc = mssql_query($qrySelc);
        $rowMedico = mssql_fetch_array($rqrySelc);

        $i_activo = 0;
        if ($rowMedico['i_activo'] == 0) {
            $i_activo = 1;
        }

        $upDes = " UPDATE dbo.tbl_permisosVending
                    SET i_activo = " . $i_activo . "
                    WHERE id_medico = '" . $_POST['id_medico'] . "'";
        $rupDes = mssql_query($upDes);
        if ($rupDes) {
            $json[] = array('status' => 200);
        } else {
            $json[] = array('status' => 201);
        }
        echo json_encode($json);
        break;
    case 'cambiarPermisos':
        $upPerm = " UPDATE dbo.tbl_permisosVending
                    SET st_dispositivo = '" . $_POST['dispositivo'] . "',
                    dt_fechaActualizar = GETDATE()
                    WHERE id_medico = '" . $_POST['id_medico'] . "'";
        $rupPerm = mssql_query($upPerm);
        if ($upPerm) {
            $json[] = array('status' => 200);
        } else {
            $json[] = array('status' => 201);
        }
        echo json_encode($json);
        break;
    case 'sucursalesVending':
        $selSuc = " SELECT id_SucursalClinica, st_Nombre, st_vendingMachine
                    FROM cat_SucursalClinica
                    WHERE st_vendingMachine IS NOT NULL
                    AND st_vendingMachine <> ''
                    ANd i_Activo = 1";
        $rselSuc = mssql_query($selSuc);
        if ($rselSuc) {
            while ($rowsuc = mssql_fetch_array($rselSuc)) {
                $json[] = array('st_Nombre' => utf8_encode($rowsuc['st_Nombre']), 'st_vendingMachine' => trim($rowsuc['st_vendingMachine']));
            }
        }
        echo json_encode($json);
        break;
    case 'insertarSucursales':
        $upSucSelect = " SELECT st_vendings 
                        FROM dbo.tbl_permisosVending
                        WHERE id_medico = '" . $_POST['id_medico'] . "'";
        $rupSucSelect = mssql_query($upSucSelect);
        $rowPermisos = mssql_fetch_array($rupSucSelect);

        session_start();

        $usuarioActual = isset($_SESSION['id_Medico']) ? $_SESSION['id_Medico'] : 'Administrador';

        $user_agent = $_SERVER['HTTP_USER_AGENT'];
        $InLog = "INSERT INTO tbl_logAPIML(navegador,api,descripcion) values('" . $user_agent . "','Permisos Videollamadas','Cambio permisos de : " . $rowPermisos['st_vendings'] . " a : " . $_POST['ids'] . " por el Medico " . $usuarioActual . "')";
        mssql_query($InLog);

        $upSuc = " UPDATE dbo.tbl_permisosVending
                    SET st_vendings = '" . $_POST['ids'] . "',
                    dt_fechaActualizar = GETDATE()
                    WHERE id_medico = '" . $_POST['id_medico'] . "'";
        $rupSuc = mssql_query($upSuc);


        if ($rupSuc) {
            $json[] = array('status' => 200);
        } else {
            $json[] = array('status' => 201);
        }
        echo json_encode($json);
        break;
    case 'desactivarTodosMedicos':
        $upSuc = " UPDATE dbo.tbl_permisosVending
                    SET i_activo = 0,
                    WHERE i_grupo = '" . $_POST['grupo'] . "'
                    dt_fechaActualizar = GETDATE() ";
        $rupSuc = mssql_query($upSuc);
        if ($rupSuc) {
            $json[] = array('status' => 200);
        } else {
            $json[] = array('status' => 201);
        }
        echo json_encode($json);
        break;
}

function consultarNotificacion($id_medico)
{
    $qryNot = "SELECT id_medico
                FROM tbl_notificaciones nt_sub
                WHERE nt_sub.i_notificacion IN (1, 2)
                AND CAST(nt_sub.dt_fecha_registro AS DATE) = CAST(GETDATE() AS DATE)
                AND nt_sub.dt_fecha_registro = (
                    SELECT MAX(dt_fecha_registro)
                    FROM tbl_notificaciones
                    WHERE id_medico = '" . $id_medico . "'
                    AND CAST(dt_fecha_registro AS DATE) = CAST(GETDATE() AS DATE)
                )";
    $rqryNot = mssql_query($qryNot);
    $total_notificaciones = mssql_num_rows($rqryNot);

    return $total_notificaciones;
}

function consultarConsultasMed($id_medico)
{
    $qryCons = " SELECT id_medico
                FROM tbl_EvCitasUsuariosWeb con_sub
                WHERE con_sub.EnConsulta IN (1,2)
                AND con_sub.dt_fechaRegistro = (
                    SELECT MAX(dt_fechaRegistro)
                    FROM tbl_EvCitasUsuariosWeb
                    WHERE id_medico = '" . $id_medico . "'
                    AND CAST(dt_fechaRegistro AS DATE) = CAST(GETDATE() AS DATE)
                )";
    $rqryCons = mssql_query($qryCons);
    $total_consultas = mssql_num_rows($rqryCons);

    return $total_consultas;
}

function consultarSesion($id_medico)
{
    $qSesion = " SELECT id_medico
                FROM dbo.tbl_permisosVending
                WHERE i_activo_consulta = 1
                AND id_medico = '" . $id_medico . "'
                AND CAST(dt_fechaActivo AS DATE) = CAST(GETDATE() AS DATE)";

    $rqSesion = mssql_query($qSesion);
    $total_Sesion = mssql_num_rows($rqSesion);

    return $total_Sesion;
}


function consultarHorarioComida($id_medico)
{
    $qry = "SELECT LEFT(CONVERT(VARCHAR(8), dt_FechaInicio, 108),5) AS Horaentrada, LEFT(CONVERT(VARCHAR(8), dt_FechaFin, 108),5) AS HoraSalida
            FROM tbl_HorariosMedicos
            WHERE id_Medico = '" . $id_medico . "'
            AND id_TipoPermiso = 2
            AND st_Descripcion COLLATE Latin1_General_CI_AI = 
                CASE DATEPART(WEEKDAY, GETDATE())
                    WHEN 1 THEN 'Domingo'
                    WHEN 2 THEN 'Lunes'
                    WHEN 3 THEN 'Martes'
                    WHEN 4 THEN 'Miercoles'
                    WHEN 5 THEN 'Jueves'
                    WHEN 6 THEN 'Viernes'
                    WHEN 7 THEN 'Sabado'
                END COLLATE Latin1_General_CI_AI

            UNION

            SELECT LEFT(CONVERT(VARCHAR(8), dt_FechaInicio, 108),5) AS Horaentrada, LEFT(CONVERT(VARCHAR(8), dt_FechaFin, 108),5) AS HoraSalida
            FROM tbl_HorariosMedicos
            WHERE id_Medico = '" . $id_medico . "'
            AND id_TipoPermiso = 2
            AND st_Descripcion COLLATE Latin1_General_CI_AI = 
                CASE DATEPART(WEEKDAY, GETDATE())
                    WHEN 1 THEN 'Domingo'
                    WHEN 2 THEN 'Lunes'
                    WHEN 3 THEN 'Martes'
                    WHEN 4 THEN 'Miercoles'
                    WHEN 5 THEN 'Jueves'
                    WHEN 6 THEN 'Viernes'
                    WHEN 7 THEN 'Sabado'
                END COLLATE Latin1_General_CI_AI
            AND (
                    DATEPART(HOUR, GETDATE()) NOT BETWEEN DATEPART(HOUR, dt_FechaInicio) AND DATEPART(HOUR, dt_FechaFin)
                    OR (DATEPART(HOUR, GETDATE()) = DATEPART(HOUR, dt_FechaInicio) AND DATEPART(MINUTE, GETDATE()) < DATEPART(MINUTE, dt_FechaInicio))
                    OR (DATEPART(HOUR, GETDATE()) = DATEPART(HOUR, dt_FechaFin) AND DATEPART(MINUTE, GETDATE()) > DATEPART(MINUTE, dt_FechaFin))
                );
            ";
    $rqry = mssql_query($qry);
    $total_horario = mssql_num_rows($rqry);

    $horario = array('inicio' => null, 'fin' => null);

    if ($total_horario > 0) {
        $rowH = mssql_fetch_array($rqry);
        $horario = array('inicio' => $rowH['Horaentrada'], 'fin' => $rowH['HoraSalida']);
    }

    return $horario;
}

function consultarHorarioLaboral($id_medico)
{
    $qry = "SELECT LEFT(CONVERT(VARCHAR(8), dt_FechaInicio, 108),5) AS Horaentrada, LEFT(CONVERT(VARCHAR(8), dt_FechaFin, 108),5) AS HoraSalida
            FROM tbl_HorariosMedicos
            WHERE id_Medico = '" . $id_medico . "'
            AND id_TipoPermiso = 1
            AND st_Descripcion COLLATE Latin1_General_CI_AI = CASE DATEPART(WEEKDAY, GETDATE())
                                WHEN 1 THEN 'Domingo'
                                WHEN 2 THEN 'Lunes'
                                WHEN 3 THEN 'Martes'
                                WHEN 4 THEN 'Miercoles' 
                                WHEN 5 THEN 'Jueves'
                                WHEN 6 THEN 'Viernes'
                                WHEN 7 THEN 'Sabado'
                                END COLLATE Latin1_General_CI_AI
            --AND DATEPART(HOUR, GETDATE()) BETWEEN DATEPART(HOUR, dt_FechaInicio) AND DATEPART(HOUR, dt_FechaFin)";
    $rqry = mssql_query($qry);
    $total_horario = mssql_num_rows($rqry);

    $horario = array('inicio' => null, 'fin' => null);

    if ($total_horario > 0) {
        $rowH = mssql_fetch_array($rqry);
        $horario = array('inicio' => $rowH['Horaentrada'], 'fin' => $rowH['HoraSalida']);
    }

    return $horario;
}
