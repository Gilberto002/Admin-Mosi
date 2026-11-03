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
    case 'listar_usuarios':
        $filtroRegistros = $_GET['checkSelect'] != 'ALL' ? " AND per.st_dispositivo IN ('" . $_GET['checkSelect'] . "', 'ALL') " : ' ';

        $query = "SELECT suc.st_Nombre as nomSuc, m.st_ApellidoPaterno, m.st_ApellidoMaterno, m.st_Nombre as nomMed, m.id_Medico, per.st_dispositivo, per.st_vendings
                FROM cat_Medicos m
                LEFT JOIN cat_SucursalClinica suc ON m.id_SucursalClinica = suc.id_SucursalClinica
                LEFT JOIN dbo.tbl_permisosVending per ON per.id_medico = m.id_Medico
                WHERE m.i_videollamada = 1 
                AND per.i_activo = 1
                AND per.i_grupo = '".$_GET['grupo']."'
                " . $filtroRegistros . "
                AND m.i_Activo = 1";
        $rqury = mssql_query($query);
        //print_r($query);

        $cont = 1;
        while ($row = mssql_fetch_array($rqury)) {

            if ($row['st_dispositivo'] == 'Kiosco' || $row['st_dispositivo'] == 'ALL') {
                if (is_null($row['st_vendings']) || trim($row['st_vendings']) === '') {
                    continue;
                }
            }

            $verificar = consultarDisponibilidad($row['id_Medico'], $_GET['checkSelect'], $_GET['grupo']);
            $fechaGllobal = filtrarFecha(null, null, 1);
            if (isset($_GET['fecha1']) && isset($_GET['fecha2'])) {
                $fechaGllobal = filtrarFecha($_GET['fecha1'], $_GET['fecha2'], 2);
            }

            $qryNotificaciones = $fechaGllobal;
            $qryNotificaciones .= "SELECT nt.id_medico, nt.i_reasignacion, nt.dt_fechaFinalizacion_User, nt.dt_fechaFinalizacion_Med, nt.tiempo_espera
            FROM tbl_notificaciones nt
            LEFT JOIN dbo.tbl_permisosVending per ON nt.id_medico = per.id_medico
            WHERE nt.id_medico = '" . $row['id_Medico'] . "'
            " . $filtroRegistros . "
            AND per.i_activo = 1
            AND nt.i_Activo = 1
            AND nt.dt_fecha_registro BETWEEN @fecha1 AND @fecha2
            AND nt.id_usuarioWeb IS NOT NULL AND nt.id_usuarioWeb != 0 AND nt.id_sucursal IS NOT NULL AND nt.id_sucursal != 0
            ORDER BY nt.dt_fecha_registro DESC";

            $rqryNotificaciones = mssql_query($qryNotificaciones);

            $registradaRecibidas = 0;
            $noregistradaRecibidas = 0;
            $sumReagendada = 0;
            $registradaAgendadas = 0;
            $noregistradaAgendadas = 0;
            $registradaRecibidasT = 0;
            $noregistradaRecibidasT = 0;

            $notificaciones = array();
            while ($rowNotificacion = mssql_fetch_array($rqryNotificaciones)) {
                $notificaciones[] = $rowNotificacion;
            }

            // Procesamos las notificaciones
            foreach ($notificaciones as $rowNotificacion) {
                $i_reasignacion = $rowNotificacion['i_reasignacion'];
                $dt_fechaFinalizacion_User = $rowNotificacion['dt_fechaFinalizacion_User'];
                $dt_fechaFinalizacion_Med = $rowNotificacion['dt_fechaFinalizacion_Med'];

                if ($i_reasignacion == 2) { // Recibidas
                    if ($dt_fechaFinalizacion_User || $dt_fechaFinalizacion_Med) {
                        $registradaRecibidas++;
                    } else {
                        $noregistradaRecibidas++;
                    }
                } elseif ($i_reasignacion == 1) { // Agendadas
                    if ($dt_fechaFinalizacion_User || $dt_fechaFinalizacion_Med) {
                        $registradaAgendadas++;
                    } else {
                        $noregistradaAgendadas++;
                    }
                    //$sumReagendada++;
                } elseif ($i_reasignacion == 0) { // Terminadas
                    if (($dt_fechaFinalizacion_User || $dt_fechaFinalizacion_Med) && $rowNotificacion['tiempo_espera']) {
                        $registradaRecibidasT++;
                    } else {
                        $noregistradaRecibidasT++;
                    }
                }
            }

            $medico = utf8_encode($row['nomMed']) . ' ' . utf8_encode($row['st_ApellidoPaterno']) . ' ' . utf8_encode($row['st_ApellidoMaterno']);
            $sucursal = utf8_encode($row['nomSuc']);

            $totalRecibidas = $registradaRecibidasT + $registradaAgendadas + $registradaRecibidas + $noregistradaRecibidasT + $noregistradaAgendadas + $noregistradaRecibidas;

            $fechas = medirTiempo($row['id_Medico'], $_GET['checkSelect']);

            $json[] = array(
                'numero' => $cont,
                'nombre' => $medico,
                'sucursal' => $sucursal,
                'recibidas' => $registradaRecibidasT,
                'agendadas' => $registradaAgendadas,
                'recibidasAgenda' => $registradaRecibidas,
                'recibidasNoAtendidas' => $noregistradaRecibidasT,
                'agendadasNoAtendidas' => $noregistradaAgendadas,
                'recibidasAgendaNoAtendidas' => $noregistradaRecibidas,
                'recibidasTotales' => $totalRecibidas,
                'estatus' => $verificar,
                'fechas_atencion' => $fechas,
                'id_medico' => $row['id_Medico'],
                'plataforma' => $row['st_dispositivo']
            );
            $cont++;
        }

        echo json_encode($json);
        break;
    case 'listar_registrosD':
        $filtroRegistros = $_GET['checkSelect'] != 'ALL' ? " AND per.st_dispositivo IN ('" . $_GET['checkSelect'] . "', 'ALL') " : ' ';

        $fechaGllobal2 = '';
        $fechaGllobal = filtrarFecha(null, null, 3);
        if (isset($_GET['fecha1']) && isset($_GET['fecha2'])) {
            $fechaGllobal = filtrarFecha($_GET['fecha1'], $_GET['fecha2'], 2);
        }

        $quryS = $fechaGllobal;
        $quryS .= "SELECT nt_sub.id_medico, nt_sub.id_usuarioWeb, 
                nt_sub.id_sucursal,  nt_sub.i_reasignacion, 
                nt_sub.tiempo_espera, nt_sub.st_dispositivo,
                CONVERT(VARCHAR,  nt_sub.dt_fechaFinalizacion_Med, 108) AS dt_fechaFinalizacion_Med, 
                CONVERT(VARCHAR,  nt_sub.dt_fechaFinalizacion_User, 108) AS dt_fechaFinalizacion_User,
                CONVERT(VARCHAR,  nt_sub.dt_conexion_Med, 108) AS dt_conexion_Med,
                CONVERT(VARCHAR,  nt_sub.dt_fecha_registro, 120) AS dt_fecha_registro, 
                sc.st_Nombre as nomSuc, us.st_Nombre as nomUs, us.st_ApellidoPaterno, us.st_ApellidoMaterno, nt_sub.id
                FROM tbl_notificaciones nt_sub
                LEFT JOIN tbl_UsuariosWebCac us ON nt_sub.id_usuarioWeb = us.id_usuarioweb
                LEFT JOIN cat_SucursalClinica sc ON us.id_Sucursal = sc.id_SucursalClinica 
                LEFT JOIN dbo.tbl_permisosVending per ON nt_sub.id_medico = per.id_medico
                WHERE dt_fecha_registro BETWEEN @fecha1 AND @fecha2
                " . $filtroRegistros . "
                AND per.i_activo = 1
                AND nt_sub.i_Activo = 1
                AND per.i_grupo = '".$_GET['grupo']."'
                --AND nt_sub.id_usuarioWeb IS NOT NULL 
                --AND nt_sub.id_usuarioWeb != 0 
                --AND nt_sub.id_sucursal IS NOT NULL 
                --AND nt_sub.id_sucursal != 0
                order by dt_fecha_registro desc";
        $rquryS = mssql_query($quryS);
        //print_r($quryS);

        $cont = 1;
        while ($row = mssql_fetch_array($rquryS)) {
            $qryMed = "SELECT suc.st_Nombre as nomSuc, m.st_Nombre as nomMed,  m.st_ApellidoPaterno, m.st_ApellidoMaterno
                        FROM cat_Medicos m
                        LEFT JOIN cat_SucursalClinica suc ON suc.id_SucursalClinica = m.id_SucursalClinica
                        LEFT JOIN dbo.tbl_permisosVending per ON m.id_Medico = per.id_medico
                        WHERE m.id_Medico = '" . $row['id_medico'] . "'
                        " . $filtroRegistros . "
                        AND per.i_activo = 1
                        AND m.i_Activo = 1";
            $rqryMed = mssql_query($qryMed);
            $rowMed = mssql_fetch_array($rqryMed);

            $qryQR = "SELECT id_qrCode, id_Receta FROM tbl_CodigosQR WHERE id_notificacion = '" . $row['id'] . "' AND i_activo = 1";
            $rqryQR = mssql_query($qryQR);
            $rowQR = mssql_fetch_array($rqryQR);

            $medico = utf8_encode($rowMed['nomMed']) . ' ' . utf8_encode($rowMed['st_ApellidoPaterno']) . ' ' . utf8_encode($rowMed['st_ApellidoMaterno']);
            $sucursal = utf8_encode($rowMed['nomSuc']);
            $nomUs =  utf8_encode($row['nomUs']) . ' ' . utf8_encode($row['st_ApellidoPaterno']) . ' ' . utf8_encode($row['st_ApellidoMaterno']);
            $sucUs =  utf8_encode($row['nomSuc']);

            $sucursalDispositivo = buscarSucursalLlamada($row['st_dispositivo']);

            $json[] = array(
                'numero' => $cont,
                'nombre_M' => $medico,
                'sucursal_M' => $sucursal,
                'nombre_U' => $nomUs,
                'sucursal_U' => $sucUs,
                'fecha_registro' => $row['dt_fecha_registro'],
                'tiempo_espera' => $row['tiempo_espera'],
                'dt_fechaFinalizacion_Med' => $row['dt_fechaFinalizacion_Med'],
                'dt_fechaFinalizacion_User' => $row['dt_fechaFinalizacion_User'],
                'i_reasignacion' => $row['i_reasignacion'],
                'dt_conexion_Med' => $row['dt_conexion_Med'],
                'st_dispositivo' => $row['st_dispositivo'],
                'id_qr' => $rowQR['id_qrCode'],
                'id_Receta' => $rowQR['id_Receta'],
                'sucursalDispositivo' => utf8_encode($sucursalDispositivo)
            );
            $cont++;
        }

        $json2 = array();
        $cont = 1;
        /*foreach ($json as $rowNotificacion) {
            $i_reasignacion = $rowNotificacion['i_reasignacion'];
            $dt_fechaFinalizacion_User = $rowNotificacion['dt_fechaFinalizacion_User'];
            $dt_fechaFinalizacion_Med = $rowNotificacion['dt_fechaFinalizacion_Med'];

            if ($i_reasignacion == 2) { // Recibidas
                if ($dt_fechaFinalizacion_User || $dt_fechaFinalizacion_Med) {
                    $json2[] = omologarJson($cont, $rowNotificacion); 
                    $cont++;
                } else {
                    $noregistradaRecibidas++;
                }
            } elseif ($i_reasignacion == 1) { // Agendadas
                if ($dt_fechaFinalizacion_User || $dt_fechaFinalizacion_Med) {
                    $json2[] = omologarJson($cont, $rowNotificacion);
                    $cont++; 
                } else {
                    $noregistradaAgendadas++;
                }
                //$sumReagendada++;
            } elseif ($i_reasignacion == 0) { // Terminadas
                if (($dt_fechaFinalizacion_User || $dt_fechaFinalizacion_Med) && $rowNotificacion['tiempo_espera']) {
                    $json2[] = omologarJson($cont, $rowNotificacion); 
                    $cont++;
                } else {
                    $noregistradaRecibidasT++;
                }
            }
        }*/
        echo json_encode($json);
        break;
    case 'dash_board':
        $filtroRegistros = $_GET['checkSelect'] != 'ALL' ? " AND per.st_dispositivo IN ('" . $_GET['checkSelect'] . "', 'ALL') " : ' ';

        $query = "SELECT suc.st_Nombre as nomSuc, m.st_ApellidoPaterno, m.st_ApellidoMaterno, m.st_Nombre as nomMed, m.id_Medico, per.st_dispositivo, per.st_vendings
                    FROM cat_Medicos m
                    LEFT JOIN cat_SucursalClinica suc ON m.id_SucursalClinica = suc.id_SucursalClinica
                    LEFT JOIN dbo.tbl_permisosVending per ON m.id_Medico = per.id_medico
                    WHERE m.i_videollamada = 1 
                    " . $filtroRegistros . "
                    AND per.i_activo = 1
                    AND per.i_grupo = '".$_GET['grupo']."'
                    AND m.i_Activo = 1";
        $rqury = mssql_query($query);
        //print_r($query);
        $total_Medicos = 0;

        $total_MDisponibles = 0;
        $total_MSinRegistros = 0;
        while ($row = mssql_fetch_array($rqury)) {
            if ($row['st_dispositivo'] == 'Kiosco' || $row['st_dispositivo'] == 'ALL') {
                if (is_null($row['st_vendings']) || trim($row['st_vendings']) === '') {
                    continue;
                }
            }
            $total_Medicos++;

            $num = consultarDisponibilidad($row['id_Medico'], $_GET['checkSelect'], $_GET['grupo']);
            $fechas = medirTiempo($row['id_Medico'], $_GET['checkSelect']);
            if (!($fechas['fecha_cita'] || $fechas['fehca_notificacion']) || $fechas['sesion'] == 0) {
                $total_MSinRegistros++;
            } else {
                $total_MDisponibles = $total_MDisponibles + $num;
            }
        }

        $ultimaFecha = NULL;
        /*$llamadasEnCola = array();

            if($total_MDisponibles == 0){
                $ultimaFecha = consultarUltimaDisponibilidad($filtroRegistros);

                if($ultimaFecha){
                    //$llamadasEnCola = consultarEnCola($ultimaFecha, null, null, $_GET['checkSelect']);
                }
            }*/

        $fechaGllobal2 = '';
        $fechaGllobal = filtrarFecha(null, null, 1);
        if (isset($_GET['fecha1']) && isset($_GET['fecha2'])) {
            $fechaGllobal = filtrarFecha($_GET['fecha1'], $_GET['fecha2'], 2);
        }

        $quryS = $fechaGllobal;
        $quryS .= "SELECT nt_sub.id_medico, nt_sub.id_usuarioWeb, 
                    nt_sub.id_sucursal,  nt_sub.i_reasignacion, 
                    nt_sub.tiempo_espera, nt_sub.st_dispositivo, nt_sub.i_notificacion,
                    CONVERT(VARCHAR,  nt_sub.dt_fechaFinalizacion_Med, 108) AS dt_fechaFinalizacion_Med, 
                    CONVERT(VARCHAR,  nt_sub.dt_fechaFinalizacion_User, 108) AS dt_fechaFinalizacion_User,
                    CONVERT(VARCHAR,  nt_sub.dt_conexion_Med, 108) AS dt_conexion_Med,
                    CONVERT(VARCHAR,  nt_sub.dt_fecha_registro, 120) AS dt_fecha_registro,
                    sc.st_Nombre as nomSuc, us.st_Nombre as nomUs, us.st_ApellidoPaterno, us.st_ApellidoMaterno
                    FROM tbl_notificaciones nt_sub
                    LEFT JOIN cat_SucursalClinica sc ON nt_sub.id_sucursal = sc.id_SucursalClinica
                    LEFT JOIN tbl_UsuariosWebCac us ON nt_sub.id_usuarioWeb = us.id_usuarioweb 
                    LEFT JOIN dbo.tbl_permisosVending per ON nt_sub.id_medico = per.id_medico
                    WHERE dt_fecha_registro BETWEEN @fecha1 AND @fecha2
                    " . $filtroRegistros . "
                    AND per.i_activo = 1
                    AND per.i_grupo = '".$_GET['grupo']."'
                    AND nt_sub.i_Activo = 1
                    AND nt_sub.id_usuarioWeb IS NOT NULL 
                    AND nt_sub.id_usuarioWeb != 0 
                    AND nt_sub.id_sucursal IS NOT NULL 
                    AND nt_sub.id_sucursal != 0
                    order by dt_fecha_registro desc";
        $rquryS = mssql_query($quryS);
        $total_Llamadas = mssql_num_rows($rquryS);

        $registradaRecibidas = 0;
        $noregistradaRecibidas = 0;
        $sumReagendada = 0;
        $registradaAgendadas = 0;
        $noregistradaAgendadas = 0;
        $registradaRecibidasT = 0;
        $noregistradaRecibidasT = 0;

        $llamadas = array();
        while ($rrquryS = mssql_fetch_array($rquryS)) {
            $llamadas[] = $rrquryS;
        }

        // Procesamos las llamadas
        $tiempos = array();
        $reporteLlamadas = array();
        foreach ($llamadas as $rowLlamadas) {
            $i_reasignacion = $rowLlamadas['i_reasignacion'];
            $dt_fechaFinalizacion_User = $rowLlamadas['dt_fechaFinalizacion_User'];
            $dt_fechaFinalizacion_Med = $rowLlamadas['dt_fechaFinalizacion_Med'];
            $i_notificacion = $rowLlamadas['i_notificacion'];
            $cola = false;

            if ($i_reasignacion == 2) {
                $estatusLlamada = 'perdida';
                if ($dt_fechaFinalizacion_User || $dt_fechaFinalizacion_Med) {
                    $registradaRecibidas++;
                    $tiempos[] = omologarTiempos($rowLlamadas);
                    $estatusLlamada = 'contestada';
                } else {
                    $noregistradaRecibidas++;
                    if ($i_notificacion == 1 && $rowLlamadas['dt_conexion_Med'] == null && $rowLlamadas['dt_fechaFinalizacion_User'] == null) {
                        $cola = true;
                    }
                }
                $nomSuc = buscarSucursalLlamada($rowLlamadas['st_dispositivo']);
                $reporteLlamadas[] = array(
                    'tipo' => 'ATENDIDAS',
                    'estatusLlamada' => $estatusLlamada,
                    'id_sucursal' => $rowLlamadas['id_sucursal'],
                    'nomSuc' => utf8_encode($nomSuc),
                    'cola' => $cola
                );
            } else if ($i_reasignacion == 1) { // Agendadas
                $estatusLlamada = 'perdida';
                if ($dt_fechaFinalizacion_User || $dt_fechaFinalizacion_Med) {
                    $registradaAgendadas++;
                    $tiempos[] = omologarTiempos($rowLlamadas);
                    $estatusLlamada = 'contestada';
                } else {
                    $noregistradaAgendadas++;
                    if ($i_notificacion == 1 && $rowLlamadas['dt_conexion_Med'] == null && $rowLlamadas['dt_fechaFinalizacion_User'] == null) {
                        $cola = true;
                    }
                }
                $nomSuc = buscarSucursalLlamada($rowLlamadas['st_dispositivo']);
                $reporteLlamadas[] = array(
                    'tipo' => 'REASIGNADAS',
                    'estatusLlamada' => $estatusLlamada,
                    'id_sucursal' => $rowLlamadas['id_sucursal'],
                    'nomSuc' => utf8_encode($nomSuc),
                    'cola' => $cola
                );
                //$sumReagendada++;
            } else if ($i_reasignacion == 0) { // Terminadas
                $estatusLlamada = 'perdida';
                if (($dt_fechaFinalizacion_User || $dt_fechaFinalizacion_Med) && $rowLlamadas['tiempo_espera']) {
                    $registradaRecibidasT++;
                    $tiempos[] = omologarTiempos($rowLlamadas);
                    $estatusLlamada = 'contestada';
                } else {
                    $noregistradaRecibidasT++;
                    if ($i_notificacion == 1 && $rowLlamadas['dt_conexion_Med'] == null && $rowLlamadas['dt_fechaFinalizacion_User'] == null) {
                        $cola = true;
                    }
                }
                $nomSuc = buscarSucursalLlamada($rowLlamadas['st_dispositivo']);
                $reporteLlamadas[] = array(
                    'tipo' => 'ATENDIDAS',
                    'estatusLlamada' => $estatusLlamada,
                    'id_sucursal' => $rowLlamadas['id_sucursal'],
                    'nomSuc' => utf8_encode($nomSuc),
                    'cola' => $cola
                );
            }
        }

        $total_ColaDia = 0;
        if (isset($_GET['fecha1']) && isset($_GET['fecha2'])) {
            //$total_ColaDia = consultarEnCola($ultimaFecha, $_GET['fecha1'], $_GET['fecha2'], $_GET['checkSelect']);
            $total_ColaDia = consultarCola($_GET['fecha1'], $_GET['fecha2'], $_GET['checkSelect'], $filtroRegistros, $_GET['grupo'] );
        } else {
            //$total_ColaDia = consultarEnCola($ultimaFecha, null, null, $_GET['checkSelect']);
            $total_ColaDia = consultarCola(null, null, $_GET['checkSelect'], $filtroRegistros, $_GET['grupo'] );
        }
        $totalRecibidas = $registradaRecibidasT + $registradaAgendadas + $registradaRecibidas + $noregistradaRecibidasT + $noregistradaAgendadas + $noregistradaRecibidas;
        $llamadasPerdidas =  $noregistradaRecibidasT + $noregistradaAgendadas + $noregistradaRecibidas;

        $json[] = array(
            'total_Medicos' => $total_Medicos,
            'total_MDisponibles' => $total_MDisponibles,
            'total_MSinRegistros' => $total_MSinRegistros,
            'ultimaFecha' => $ultimaFecha,
            //'enCola_actual'=>$llamadasEnCola,
            'enCola_dia' => $total_ColaDia,
            'total_perdidas' => $llamadasPerdidas,
            'total_Llamadas' => $total_Llamadas,
            'atendidas' => $registradaRecibidasT,
            'agendadas' => $registradaAgendadas,
            'recibidasAgenda' => $registradaRecibidas,
            'recibidasNoAtendidas' => $noregistradaRecibidasT,
            'agendadasNoAtendidas' => $noregistradaAgendadas,
            'recibidasAgendaNoAtendidas' => $noregistradaRecibidas,
            'recibidasTotales' => $totalRecibidas,
            'tiempos' => $tiempos,
            'reporteLlamadas' => $reporteLlamadas
        );
        echo json_encode($json);
        break;
}

function omologarJson($cont, $rowNotificacion)
{
    return array(
        'numero' => $cont,
        'nombre_M' => $rowNotificacion['nombre_M'],
        'sucursal_M' => $rowNotificacion['sucursal_M'],
        'nombre_U' =>  $rowNotificacion['nombre_U'],
        'sucursal_U' => $rowNotificacion['sucursal_U'],
        'fecha_registro' => $rowNotificacion['fecha_registro'],
        'tiempo_espera' => $rowNotificacion['tiempo_espera'],
        'dt_fechaFinalizacion_Med' => $rowNotificacion['dt_fechaFinalizacion_Med'],
        'dt_fechaFinalizacion_User' => $rowNotificacion['dt_fechaFinalizacion_User'],
        'estado' => $rowNotificacion['i_reasignacion'],
        'dt_conexion_Med' => $rowNotificacion['dt_conexion_Med'],
        'st_dispositivo' => $rowNotificacion['st_dispositivo']
    );
}

function omologarTiempos($rowLlamadas)
{
    return array(
        'tiempo_espera' => $rowLlamadas['tiempo_espera'],
        'dt_fechaFinalizacion_Med' => $rowLlamadas['dt_fechaFinalizacion_Med'],
        'dt_fechaFinalizacion_User' => $rowLlamadas['dt_fechaFinalizacion_User'],
        'dt_fecha_registro' => $rowLlamadas['dt_fecha_registro'],
        'st_dispositivo' => $rowLlamadas['st_dispositivo'],
        'dt_conexion_Med' => $rowLlamadas['dt_conexion_Med'],
    );
}

function filtrarFecha($fecha1, $fecha2, $tipo)
{
    $fechaFiltro = "";
    switch ($tipo) {
        case 1:
            $fechaFiltro = "DECLARE @fecha1 DATETIME = CAST(GETDATE() AS DATE); 
                            DECLARE @fecha2 DATETIME = DATEADD(SECOND, -1, DATEADD(DAY, 1, @fecha1))";
            break;
        case 2:
            $fechaFiltro = "DECLARE @fecha1 DATETIME = '" . $fecha1 . " 00:00:00.000' 
                            DECLARE @fecha2 DATETIME = '" . $fecha2 . " 23:59:59.900' ";
            break;
        case 3:
            $fechaFiltro = "DECLARE @fecha1 DATETIME = DATEFROMPARTS(YEAR(GETDATE()), MONTH(GETDATE()), 1)
                            DECLARE @fecha2 DATETIME = DATEADD(SECOND, -1, DATEADD(MONTH, 1, @fecha1))";
            break;
    }
    return $fechaFiltro;
}

function consultarDisponibilidad($idUsuario, $getSelect, $i_grupo)
{
    $filtroRegistros = $getSelect != 'ALL' ? " AND per.st_dispositivo IN ('" . $getSelect . "', 'ALL') " : ' ';
    $qryDisp = "SELECT TOP 1
                    CONCAT(md.st_Nombre, ' ', md.st_ApellidoPaterno, ' ', md.st_ApellidoMaterno) AS nombre,
                        md.id_Medico,
                        md.id_SucursalClinica,
                        ISNULL(
                            (SELECT MAX(f) FROM (VALUES (nt.dt_fecha_registro), (ev_sub.dt_FechaSalida)) AS val(f)),
                            per.dt_fechaActivo
                        ) AS fecha_referencia,
                    CASE 
                        WHEN nt.dt_fecha_registro IS NOT NULL AND (ev_sub.dt_FechaSalida IS NULL OR nt.dt_fecha_registro >= ev_sub.dt_FechaSalida) THEN 'notificación'
                        WHEN ev_sub.dt_FechaSalida IS NOT NULL THEN 'cita'
                        ELSE 'activación'
                    END AS origen_fecha,
                    CONVERT(varchar, GETDATE(), 108) AS hora_actual
                FROM 
                    cat_Medicos md
                LEFT JOIN 
                    dbo.tbl_permisosVending per ON md.id_Medico = per.id_medico
                OUTER APPLY (
                    SELECT TOP 1 nt.dt_fecha_registro
                    FROM tbl_notificaciones nt
                    WHERE nt.id_medico = md.id_Medico
                    --AND nt.tiempo_espera IS NOT NULL
                    AND CAST(nt.dt_fecha_registro AS DATE) = CAST(GETDATE() AS DATE)
                    ORDER BY nt.dt_fecha_registro DESC
                ) nt
                OUTER APPLY (
                    SELECT TOP 1 ev.dt_FechaSalida
                    FROM tbl_EvCitasUsuariosWeb ev
                    WHERE ev.id_medico = md.id_Medico
                    AND CAST(ev.dt_FechaSalida AS DATE) = CAST(GETDATE() AS DATE)
                    ORDER BY ev.dt_FechaSalida DESC
                ) ev_sub
                WHERE 
                    md.i_Activo = 1
                    AND md.i_videollamada = 1
                    AND per.i_grupo = '" . $i_grupo . "'
                    AND md.id_Medico = '" . $idUsuario . "'
                    " . $filtroRegistros . "
                    AND CAST(per.dt_fechaActivo AS DATE) = CAST(GETDATE() AS DATE)
                    AND per.i_activo_consulta = 1
                    AND per.i_activo = 1
                    AND md.id_Medico NOT IN (
                        SELECT id_medico
                        FROM tbl_notificaciones nt_sub
                        WHERE nt_sub.i_notificacion IN (1, 2)
                        AND CAST(nt_sub.dt_fecha_registro AS DATE) = CAST(GETDATE() AS DATE)
                        AND nt_sub.dt_fecha_registro = (
                            SELECT MAX(dt_fecha_registro)
                            FROM tbl_notificaciones
                            WHERE id_medico = nt_sub.id_medico
                            AND CAST(dt_fecha_registro AS DATE) = CAST(GETDATE() AS DATE)
                        )
                    )
                    AND md.id_Medico NOT IN (
                        SELECT id_medico
                        FROM tbl_EvCitasUsuariosWeb con_sub
                        WHERE con_sub.EnConsulta IN (1,2)
                        AND con_sub.dt_fechaRegistro = (
                            SELECT MAX(dt_fechaRegistro)
                            FROM tbl_EvCitasUsuariosWeb
                            WHERE id_medico = con_sub.id_medico
                            AND CAST(dt_fechaRegistro AS DATE) = CAST(GETDATE() AS DATE)
                        )
                    )
                    AND md.id_Medico IN (
                        SELECT id_Medico
                        FROM tbl_HorariosMedicos
                        WHERE id_Medico = md.id_Medico
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
                        AND DATEPART(HOUR, GETDATE()) BETWEEN DATEPART(HOUR, dt_FechaInicio) AND DATEPART(HOUR, dt_FechaFin)
                    )
                    AND (
                        NOT EXISTS (
                            SELECT 1
                            FROM tbl_HorariosMedicos
                            WHERE id_Medico = md.id_Medico
                            AND id_TipoPermiso = 2
                            AND st_Descripcion COLLATE Latin1_General_CI_AI = CASE DATEPART(WEEKDAY, GETDATE())
                                            WHEN 1 THEN 'Domingo'
                                            WHEN 2 THEN 'Lunes'
                                            WHEN 3 THEN 'Martes'
                                            WHEN 4 THEN 'Miercoles' 
                                            WHEN 5 THEN 'Jueves'
                                            WHEN 6 THEN 'Viernes'
                                            WHEN 7 THEN 'Sabado'
                                            END COLLATE Latin1_General_CI_AI
                        )
                        OR md.id_Medico IN (
                            SELECT id_Medico
                            FROM tbl_HorariosMedicos
                            WHERE id_TipoPermiso = 2
                            AND st_Descripcion COLLATE Latin1_General_CI_AI = CASE DATEPART(WEEKDAY, GETDATE())
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
                            )
                        )
                    )
                ORDER BY 
                ISNULL(
                    (SELECT MAX(f) FROM (VALUES (nt.dt_fecha_registro), (ev_sub.dt_FechaSalida)) AS val(f)),
                    per.dt_fechaActivo
                )";
    $rqryDisp = mssql_query($qryDisp);
    //print_r($qryDisp);
    $total_registros = mssql_num_rows($rqryDisp);

    return $total_registros;
}

function medirTiempo($id_medico, $getSelect)
{
    $filtroRegistros = $getSelect != 'ALL' ? " AND per.st_dispositivo IN ('" . $getSelect . "', 'ALL') " : ' ';

    //Verificamos la videollamadas en 1 si agendo, en 2 si contesto una agendada
    $qryNot = "SELECT TOP 1 nt_sub.id_medico, CONVERT(VARCHAR, nt_sub.dt_fecha_registro, 120) AS dt_fecha_registro
        FROM tbl_notificaciones nt_sub
        LEFT JOIN dbo.tbl_permisosVending per ON nt_sub.id_medico = per.id_medico
        WHERE nt_sub.i_notificacion IN (1, 2, 0)
        " . $filtroRegistros . "
        AND per.i_activo = 1
        AND nt_sub.id_medico = '" . $id_medico . "'
        AND nt_sub.i_Activo = 1
        AND CAST(nt_sub.dt_fecha_registro AS DATE) = CAST(GETDATE() AS DATE)
        ORDER BY nt_sub.dt_fecha_registro DESC";
    $rqryNot = mssql_query($qryNot);
    //print_r($qryNot);
    $rowNot = mssql_fetch_array($rqryNot);
    $fechaNotificacion = $rowNot['dt_fecha_registro'];

    //Verificamos la ultima hora que atendio a un paciente
    $qryCita = "SELECT TOP 1 con_sub.id_medico, CONVERT(VARCHAR, con_sub.dt_FechaRegistro, 120) AS dt_FechaRegistro 
                FROM tbl_EvCitasUsuariosWeb con_sub
                LEFT JOIN dbo.tbl_permisosVending per ON con_sub.id_medico = per.id_medico
                WHERE con_sub.EnConsulta IN (1, 2, 0)
                " . $filtroRegistros . "
                AND per.i_activo = 1
                AND con_sub.id_medico = '" . $id_medico . "'
                AND CAST(con_sub.dt_fechaRegistro AS DATE) = CAST(GETDATE() AS DATE)
                ORDER BY con_sub.dt_FechaRegistro DESC";
    $rqryCita = mssql_query($qryCita);
    //print_r($qryCita);
    $rowCita = mssql_fetch_array($rqryCita);
    $fechaCita = $rowCita['dt_FechaRegistro'];
    //print_r($qryCita);


    $qrySession = "SELECT id_medico 
                    FROM dbo.tbl_permisosVending per
                    WHERE id_medico  = '" . $id_medico . "'
                   " . $filtroRegistros . "
                    AND i_activo_consulta = 1
                    AND i_activo = 1
                    AND CAST(dt_fechaActivo AS DATE) = CAST(GETDATE() AS DATE)";
    $rqrySession = mssql_query($qrySession);
    //print_r($qrySession);
    $inSesion = mssql_num_rows($rqrySession);

    return array('fehca_notificacion' => $fechaNotificacion, 'fecha_cita' => $fechaCita, 'sesion' => $inSesion);
}


function consultarUltimaDisponibilidad($filtroRegistros)
{
    $qryCon = "SELECT TOP 1 id_medico, CONVERT(VARCHAR, dt_fechaRegistro, 120) AS dt_fechaRegistro
                FROM tbl_EvCitasUsuariosWeb con_sub
                WHERE con_sub.EnConsulta IN (1,2)
                AND con_sub.dt_fechaRegistro = (
                    SELECT MAX(dt_fechaRegistro)
                    FROM tbl_EvCitasUsuariosWeb
                    WHERE " . $filtroRegistros . "
                    AND CAST(dt_fechaRegistro AS DATE) = CAST(GETDATE() AS DATE)
                )
                ORDER BY dt_fechaRegistro DESC";
    $rqryCon = mssql_query($qryCon);
    $rowCons = mssql_fetch_array($rqryCon);

    $fechaCon = ($rowCons) ? strtotime($rowCons['dt_fechaRegistro']) : 0;


    $qryNot = "SELECT TOP 1 id_medico, CONVERT(VARCHAR, dt_fecha_registro, 120) AS dt_fecha_registro
                FROM tbl_notificaciones nt_sub
                WHERE nt_sub.i_notificacion IN (1, 2)
                AND nt_sub.dt_fecha_registro = (
                    SELECT MAX(dt_fecha_registro)
                    FROM tbl_notificaciones
                    WHERE " . $filtroRegistros . "
                    AND CAST(dt_fecha_registro AS DATE) = CAST(GETDATE() AS DATE)
                )
                ORDER BY dt_fecha_registro DESC";
    $rqryNot = mssql_query($qryNot);
    $rowNot = mssql_fetch_array($rqryNot);

    $fechaNot = ($rowNot) ? strtotime($rowNot['dt_fecha_registro']) : 0;

    $ultimaFecha = NULL;

    if ($fechaCon > $fechaNot) {
        $ultimaFecha =  $rowCons['dt_fechaRegistro'];
    } elseif ($fechaNot > $fechaCon) {
        $ultimaFecha =  $rowNot['dt_fecha_registro'];
    }

    return $ultimaFecha;
}


function consultarEnCola($ultimaFecha, $fecha1, $fecha2, $filtroAPP)
{
    $qryCola = "";
    $filtroCola = "";
    switch ($filtroAPP) {
        case 'All':
            $filtrar = " ";
            break;
        case 'APK':
            $filtrar = " AND st_dispositivo <> 'Kiosco' ";
            break;
        case 'Kiosco':
            $filtrar = " AND st_dispositivo = 'Kiosco' ";
            break;
    }
    if ($ultimaFecha) {
        $qryCola = "DECLARE @fecha1 DATETIME = '" . $ultimaFecha . "' ";
        $filtroCola = "WHERE lT.dt_fechaRegistro > @fecha1";
    } else {
        $filtroCola = "WHERE CAST(lT.dt_fechaRegistro AS DATE) = CAST(GETDATE() AS DATE)";
    }

    if ($fecha1 && $fecha2) {
        $qryCola = filtrarFecha($fecha1, $fecha2, 2);
        $filtroCola = "WHERE lT.dt_fechaRegistro BETWEEN @fecha1 AND @fecha2";
    }

    $qryCola .= " SELECT lT.st_dispositivo, us.st_Nombre, us.st_ApellidoPaterno, us.st_ApellidoMaterno, suc.st_Nombre AS nomSuc,
                CONVERT(VARCHAR, lT.dt_fechaRegistro, 120) AS dt_fechaRegistro 
                FROM dbo.tbl_logTelemedicina lT
                LEFT JOIN tbl_UsuariosWebCac us ON lT.id_usuarioWeb = us.id_UsuarioWeb
                LEFT JOIN cat_SucursalClinica suc ON suc.id_SucursalClinica = lT.id_sucursal
                " . $filtroCola . "
                " . $filtrar . "
                ORDER BY  lT.dt_fechaRegistro DESC";
    $rqryNot = mssql_query($qryCola);
    $total_Cola = mssql_num_rows($rqryNot);

    $cola = array();

    while ($rowCola = mssql_fetch_array($rqryNot)) {
        $nombreColab = utf8_encode($rowCola['st_Nombre']) . ' ' . utf8_encode($rowCola['st_ApellidoPaterno']) . ' ' . utf8_encode($rowCola['st_ApellidoMaterno']);
        $nombreSucursal = utf8_encode($rowCola['nomSuc']);
        $cola[] = array(
            'st_dispositivo' => $rowCola['st_dispositivo'],
            'dt_fechaRegistro' => $rowCola['dt_fechaRegistro'],
            'nombreSucursal' => $nombreSucursal,
            'nombreColab' => $nombreColab
        );
    }

    return array('usuarios_cola' => $cola, 'total_cola' => $total_Cola);
}

function consultarCola($fecha1, $fecha2, $filtroAPP, $filtroRegistros, $grupo)
{
    $qryCola = "";
    $qryColaNot = "";
    $filtroCola = "";
    $filtroFechaCola = " AND CAST(lT.dt_fechaRegistro AS DATE) = CAST(GETDATE() AS DATE) ";
    $filtroFechaNot = " AND CAST(dt_fecha_registro AS DATE) = CAST(GETDATE() AS DATE) ";
    switch ($filtroAPP) {
        case 'All':
            $filtroCola = " ";
            break;
        case 'APP':
            $filtroCola = " AND st_dispositivo <> 'Kiosco' ";
            break;
        case 'Kiosco':
            $filtroCola = " AND st_dispositivo = 'Kiosco' ";
            break;
    }

    if ($fecha1 && $fecha2) {
        $qryCola = filtrarFecha($fecha1, $fecha2, 2);
        $qryColaNot = filtrarFecha($fecha1, $fecha2, 2);
        $filtroFechaCola = " AND lT.dt_fechaRegistro BETWEEN @fecha1 AND @fecha2 ";
        $filtroFechaNot = " AND dt_fecha_registro BETWEEN @fecha1 AND @fecha2 ";
    }

    $qryCola .= "SELECT id_usuarioWeb, id_sucursal, st_dispositivo, CONVERT(VARCHAR,  lT.dt_fechaRegistro, 121) AS dt_fechaRegistro, i_atencion, id_cabinaMedica, CONVERT(VARCHAR,  lT.dt_fechaSalida, 108) AS dt_fechaSalida
                FROM dbo.tbl_logTelemedicina lT
                WHERE lT.i_activo = 1
                AND lT.i_atencion in (1, 2, 3)
                " . $filtroFechaCola . "
                " . $filtroCola . "
                AND lT.i_grupo = '".$grupo."'
                ORDER BY lT.dt_fechaRegistro DESC";
    $rqryCola2 = mssql_query($qryCola);
    $total_registrosCola = mssql_num_rows($rqryCola2);
    //print_r($qryCola);

    $cont = 0;
    $cola = array();
    $contResp = 0;
    while ($rowCola = mssql_fetch_array($rqryCola2)) {
        if ($rowCola['id_usuarioWeb']) {
            if ($rowCola['i_atencion'] == 1) {
                $cont++;
                $buscNombre = $rowCola['st_dispositivo'] == 'Kiosco' ? $rowCola['id_cabinaMedica'] : $rowCola['st_dispositivo'];
                $nombreSuc = buscarSucursalLlamada($buscNombre);
                $cola[] = array(
                    'dt_fechaSalida' => $rowCola['dt_fechaSalida'],
                    'st_dispositivo' => $rowCola['st_dispositivo'],
                    'dt_fechaRegistro' => $rowCola['dt_fechaRegistro'],
                    'nomSuc' => utf8_encode($nombreSuc),
                    'tipo' => 'COLA',
                    'estatusLlamada' => 'perdida'
                );
            } else if ($rowCola['i_atencion'] == 3) {
                $contResp++;
            }
        }
    }

    $qryUltimaHora = "SELECT TOP 1 CONVERT(VARCHAR,  dt_fecha_registro, 121) AS dt_fecha_registro
                FROM tbl_notificaciones
                WHERE i_Activo = 1
                " . $filtroAPP . "
                ORDER BY dt_fecha_registro DESC";
    $rqryUltimaHora = mssql_query($qryUltimaHora);
    $rowUltimaHora = mssql_fetch_array($rqryUltimaHora);

    return array('encolaActual' => $cont, 'totalCola' => $total_registrosCola, 'encolaResp' => $contResp, 'datosCola' => $cola, 'ultimaHora' => $rowUltimaHora['dt_fecha_registro']);
}

function consultarAtendidasCola($rowCola, $qryColaNot, $filtroFechaNot, $filtroRegistros, $cont, $idsContados)
{
    $filtrarDisp = $rowCola['st_dispositivo'] == 'Kiosco' ? ' IN (2,3) ' : " LIKE '%" . $rowCola['st_dispositivo'] . "%' ";
    $qryColaNot .= " SELECT tiempo_espera, dt_fechaFinalizacion_User, dt_fechaFinalizacion_Med, id, st_motivoMedico
                    FROM tbl_notificaciones
                    WHERE i_Activo = 1
                    AND id_usuarioWeb = '" . $rowCola['id_usuarioWeb'] . "'
                    AND id_sucursal = '" . $rowCola['id_sucursal'] . "'
                    AND st_dispositivo " . $filtrarDisp . "
                    " . $filtroFechaNot . "
                    AND " . $filtroRegistros . "
                    AND dt_fecha_registro > '" . $rowCola['dt_fechaRegistro'] . "'";
    $rqryColaNot = mssql_query($qryColaNot);
    $total_registrosAtendidos = mssql_num_rows($rqryColaNot);
    //print_r($qryColaNot);
    $validado = false;
    while ($rowValidarCola = mssql_fetch_assoc($rqryColaNot)) {
        $id = trim($rowValidarCola['id']); // Asegurar que no tenga espacios en blanco

        // Verificar si el ID ya ha sido contado
        if (isset($idsContados[$id])) {
            continue; // Evita contarlo de nuevo y pasa a la siguiente iteración
        }

        if (!$rowValidarCola['id']) {
            $cont++;
            $idsContados[$id] = true; // Agrega el ID al array para que no se repita
        } else if (
            empty($rowValidarCola['tiempo_espera']) &&
            empty($rowValidarCola['dt_fechaFinalizacion_User']) &&
            empty($rowValidarCola['dt_fechaFinalizacion_Med'])
            && $rowValidarCola['st_motivoMedico'] != 'Cerrado por sistema'
        ) {
            $cont++;
            //print_r($rowValidarCola);
            $idsContados[$id] = true; // Marca el ID como contado
        } else {
            $idsContados[$id] = true; // Marca el ID como contado
        }
    }


    return array('cont' => $cont, 'array' => $idsContados);
}

function buscarSucursalLlamada($st_dispositivo)
{
    $qrySuc = "SELECT st_Nombre FROM cat_SucursalClinica WHERE st_vendingMachine = " . trim($st_dispositivo) . "";
    $rqrySuc = mssql_query($qrySuc);
    $total_Suc = mssql_num_rows($rqrySuc);

    $nombreSucursal = '';
    if ($total_Suc > 0) {
        $rowSuc = mssql_fetch_array($rqrySuc);
        $nombreSucursal = $rowSuc['st_Nombre'];
    } else {
        $qrySuPr = "SELECT st_Nombre FROM cat_SucursalClinica WHERE st_Prefix = '" . $st_dispositivo . "'";
        $rqrySuPr = mssql_query($qrySuPr);
        $rowSucPr = mssql_fetch_array($rqrySuPr);
        $nombreSucursal = $rowSucPr['st_Nombre'];
    }

    return $nombreSucursal;
}
