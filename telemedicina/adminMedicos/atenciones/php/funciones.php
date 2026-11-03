<?php
$ref = '../../../../../';
require($ref . "db.php");

error_reporting(E_ALL);
ini_set('display_errors', '1');

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    $accion = $_GET['accion'];
    $id_MOSI = isset($_GET['id_mosi']) ? $_GET['id_mosi'] : null;
    $fechaInicio = isset($_GET['fechaInicio']) ? $_GET['fechaInicio'] : null;

    $tipoBusqueda = isset($_GET['tipo']) ? $_GET['tipo'] : null;
    $nombreUsuario = isset($_GET['nombre']) ? $_GET['nombre'] : '';
    $apellidoP = isset($_GET['apellidoP']) ? $_GET['apellidoP'] : '';
    $apellidoM = isset($_GET['apellidoM']) ? $_GET['apellidoM'] : '';
    $idBusqueda = isset($_GET['id']) ? $_GET['id'] : '';
}
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $accion = $_POST['accion'];
    $idReceta = isset($_POST['id_receta']) ? $_POST['id_receta'] : null;
    $fechaNueva = isset($_POST['fecha']) ? $_POST['fecha'] : null;
    $idMedico = isset($_POST['medico']) ? $_POST['medico'] : null;
    $idSucursal = isset($_POST['sucursal']) ? $_POST['sucursal'] : null;
    $datosModificados = json_encode($_POST);
}

$json = array();

switch ($accion) {
    case 'traerSucursales':
        $qry = "SELECT suc.st_Nombre, suc.id_SucursalClinica, suc.st_vendingMachine 
                    FROM cat_SucursalClinica suc
                    WHERE suc.st_vendingMachine IS NOT NULL 
                    AND suc.st_vendingMachine <> ''
                    AND i_Activo = 1";

        $resp = mssql_query($qry);
        while ($row = mssql_fetch_array($resp)) {
            $json[] = array(
                'st_nombre' => utf8_encode($row['st_Nombre']),
                'id_SucursalClinica' => $row['id_SucursalClinica'],
                'st_vendingMachine' => TRIM($row['st_vendingMachine']),
            );
        }

        echo json_encode($json);
        break;
    case 'traerMedicos':
        $qry = "SELECT pr.id_medico, CONCAT(md.st_Nombre, ' ', md.st_ApellidoPaterno, ' ', md.st_ApellidoMaterno) AS st_nombre 
                    FROM tbl_permisosVending pr
                    LEFT JOIN cat_Medicos md ON pr.id_medico = md.id_Medico
                    WHERE pr.i_activo = 1
                    AND pr.st_vendings IS NOT NULL
                    AND pr.st_vendings <> ''
                    AND pr.st_dispositivo IS NOT NULL";

        $resp = mssql_query($qry);
        while ($row = mssql_fetch_array($resp)) {
            $json[] = array(
                'st_nombre' => utf8_encode($row['st_nombre']),
                'id_medico' => $row['id_medico'],
            );
        }

        echo json_encode($json);
        break;
    case 'traerPacientes':
        $qry = "SELECT us.id_UsuarioWeb, CONCAT(us.name, ' ', us.surname) AS st_nombre, us.folio
                    FROM usuariosCabinas us
                    LEFT JOIN cat_Kiosco ks ON ks.st_serialKiosco = us.device_id
                    WHERE ks.id_MOSI = '" . $id_MOSI . "'";

        $resp = mssql_query($qry);
        while ($row = mssql_fetch_array($resp)) {
            $json[] = array(
                'st_nombre' => utf8_encode($row['st_nombre']),
                'id_UsuarioWeb' => $row['id_UsuarioWeb'],
                'folio' => $row['folio']
            );
        }
        $qry2 = "SELECT id_UsuarioWeb, CONCAT(st_Nombre ,' ', st_ApellidoPaterno  ,' ', st_ApellidoMaterno)  AS st_nombre, st_foliotarjeta AS folio
					FROM tbl_UsuariosWebCac
					WHERE id_usuarioweb IN (4292586, 3590069)";

        $resp2 = mssql_query($qry2);
        while ($row2 = mssql_fetch_array($resp2)) {
            $json[] = array(
                'st_nombre' => utf8_encode($row2['st_nombre']),
                'id_UsuarioWeb' => $row2['id_UsuarioWeb'],
                'folio' => $row2['folio']
            );
        }

        echo json_encode($json);
        break;
    case 'traerTabla':
        $qry = "DECLARE @fechaInicio DATETIME = '" . $fechaInicio . " 00:00:00';
                DECLARE @fechaFin    DATETIME = '" . $fechaFin . " 23:59:59.997';
                DECLARE @mosi INT = " . $id_MOSI . ";

                SELECT 
                    us.folio, 
                    CONCAT(us.name, ' ', us.surname) AS nomUs,
                    rec.id_Receta,
                    CONVERT(VARCHAR(10), rec.dt_FechaRegistro, 23) AS fecha,
                    suc.st_Nombre AS nomSuc,
                    CONCAT(md.st_Nombre, ' ', md.st_ApellidoPaterno, ' ', md.st_ApellidoMaterno) AS nomMed,
                    rec.id_EventoCita, md.i_group, qr.id_notificacion, us.id_UsuarioWeb,
                    rec.id_Sucursal, qr.id_qrCode
                FROM usuariosCabinas us
                LEFT JOIN cat_Kiosco ks ON ks.st_serialKiosco = us.device_id
                LEFT JOIN tbl_RecetasUsuariosWeb rec ON rec.id_UsuarioWeb = us.id_UsuarioWeb
                LEFT JOIN cat_SucursalClinica suc ON rec.id_Sucursal = suc.id_SucursalClinica
                LEFT JOIN cat_Medicos md ON md.id_Medico = rec.id_Medico
                LEFT JOIN tbl_CodigosQR qr ON qr.id_Receta = rec.id_Receta
                WHERE ks.id_MOSI = @mosi
                AND rec.dt_FechaRegistro >= @fechaInicio
                AND rec.dt_FechaRegistro <= @fechaFin
                AND suc.st_vendingmachine IS NOT NULL
                AND suc.st_vendingmachine <> ''";

        $resp = mssql_query($qry);
        while ($row = mssql_fetch_array($resp)) {
            $json[] = array(
                'folio' => $row['folio'],
                'nomUs' => utf8_encode($row['nomUs']),
                'nomSuc' => utf8_encode($row['nomSuc']),
                'nomMed' => utf8_encode($row['nomMed']),
                'id_Receta' => $row['id_Receta'],
                'fecha' => $row['fecha'],
                'id_EventoCita' => $row['id_EventoCita'],
                'i_group' => $row['i_group'],
                'id_notificacion' => $row['id_notificacion'],
                'id_UsuarioWeb' => $row['id_UsuarioWeb'],
                'id_Sucursal' => $row['id_Sucursal'],
                'id_qrCode' => $row['id_qrCode']
            );
        }

        echo json_encode($json);
        break;
    case 'editarFechaConsulta':
        $qry = "SELECT id_EventoCita
                FROM tbl_RecetasUsuariosWeb WHERE id_Receta = '" . $idReceta . "'";

        $resp = mssql_query($qry);
        $rowEv = mssql_fetch_array($resp);

        $update = "
                DECLARE @nuevaFecha DATE = '" . $fechaNueva . "';
                DECLARE @idEvento INT = " . $rowEv['id_EventoCita'] . ";
                DECLARE @idReceta INT = " . $idReceta . ";
                DECLARE @idMedico INT = " . $idMedico . ";
                DECLARE @idSucursal INT = " . $idSucursal . ";

                UPDATE tbl_RecetasUsuariosWeb
                SET
                    id_Medico =  @idMedico,
                    id_Sucursal = @idSucursal,
                    dt_FechaRegistro = CAST(@nuevaFecha AS DATETIME) + CAST(dt_FechaRegistro AS TIME)
                WHERE id_Receta = @idReceta;

                UPDATE tbl_RecetaProductosUsuarioWeb
                SET
                    id_Medico = @idMedico,
                    id_Sucursal = @idSucursal,
                    dt_FechaRegistro = CAST(@nuevaFecha AS DATETIME) + CAST(dt_FechaRegistro AS TIME)
                WHERE id_Receta = @idReceta;

                UPDATE tbl_EvCitasUsuariosWeb
                SET 
                    dt_FechaRegistro = CAST(@nuevaFecha AS DATETIME) + CAST(dt_FechaRegistro AS TIME),
                    dt_FechaCita = CAST(@nuevaFecha AS DATETIME) + CAST(dt_FechaCita AS TIME),
                    dt_FechaSalida = CAST(@nuevaFecha AS DATETIME) + CAST(dt_FechaSalida AS TIME),
                    dt_FechaInterconsulta = CAST(@nuevaFecha AS DATETIME) + CAST(dt_FechaInterconsulta AS TIME),
                    id_Medico = @idMedico
                WHERE id_Evento = @idEvento;

                UPDATE tbl_EventoCitaSumatologia
                SET
                    dt_FechaRegistro = CAST(@nuevaFecha AS DATETIME) + CAST(dt_FechaRegistro AS TIME)
                WHERE id_EventoCita = @idEvento;

                UPDATE tbl_HCHistoriaClinica
                SET
                    id_Medico = @idMedico,
                    id_Sucursal = @idSucursal,
                    dt_FechaRegistro = CAST(@nuevaFecha AS DATETIME) + CAST(dt_FechaRegistro AS TIME)
                WHERE id_EventoCita = @idEvento;
        ";

        $respUp = mssql_query($update);

        actualizarLog($idReceta, 'tbl_RecetasUsuariosWeb', 'id_Receta', $datosModificados, 'Se actualizo los datos de de las videollamadas');

        if ($respUp) {
            $json = array('status' => 200);
        } else {
            $json = array('status' => 400);
        }

        echo json_encode($json);
        break;
    case 'registrarLogVideollamada':

        actualizarLog(null, '', '', $datosModificados, 'Se realizo la emulacion de videollamadas');
        break;
    case 'buscarUsuarioSybi':
        $filtro = '';
        switch ($tipoBusqueda) {
            case 'tarjeta':
                $filtro = " AND TRY_CAST(LTRIM(RTRIM(us.st_FolioTarjeta)) AS BIGINT) = '" . $idBusqueda . "' ";
                break;
            case 'id_paciente':
                $filtro = " AND us.id_UsuarioWeb = '" . $idBusqueda . "' ";
                break;
            case 'nombre':
                $filtro .= $nombreUsuario != '' ? " AND us.st_Nombre like '%" . $nombreUsuario . "%' " : '';
                $filtro .= $apellidoP != '' ? " AND us.st_ApellidoPaterno like '%" . $apellidoP . "%' " : '';
                $filtro .= $apellidoM != '' ? " AND us.st_ApellidoMaterno like '%" . $apellidoM . "%' " : '';
                break;
        }

        $qrubus = "SELECT top 100 us.i_Activo AS usaurioActivo, sub.i_Activo AS tarjetaActiva, 
                    CONCAT(us.st_Nombre, ' ', us.st_ApellidoPaterno, ' ', us.st_ApellidoMaterno) AS nombre,
                    us.id_UsuarioWeb, us.st_FolioTarjeta, pr.st_Parentesco
                    FROM cat_SucursalClinica suc
                    LEFT JOIN tbl_UsuariosWebCac us ON us.id_Cliente = suc.id_cliente
                    LEFT JOIN cat_SUBEmpleados sub ON sub.id_UsuarioWeb = us.id_UsuarioWeb
                    LEFT JOIN cat_Parentescos pr ON pr.id_Parentesco = sub.id_Parentesco
                    WHERE CAST(LTRIM(RTRIM(suc.st_vendingMachine)) AS INT) = '" . $id_MOSI . "'
                     " . $filtro . " 
                     AND suc.i_Activo = 1";

        $rqrubus = mssql_query($qrubus);

        while ($row = mssql_fetch_array($rqrubus)) {
            $json[] = array(
                'id' => $row['id_UsuarioWeb'],
                'tarjeta' => $row['st_FolioTarjeta'],
                'nombreCompleto' => utf8_encode($row['nombre']),
                'usuarioActivo' => $row['usaurioActivo'],
                'tarjetaActiva' => $row['tarjetaActiva'],
                'parentesco' => utf8_encode($row['st_Parentesco']),
            );
        }

        echo json_encode($json);
        break;
}

function actualizarLog($id, $tabla, $campo, $data, $descripcion)
{
    $qryLog = "INSERT INTO tbl_videollamadasLog(
                id_referencia,
                st_tablaReferencia,
                st_campoReferencia,
                st_datosModificados,
                st_descripcion,
                st_modulo
                )
                VALUES(
                '" . $id . "',
                '" . $tabla . "',
                '" . $campo . "',
                '" . $data . "',
                '" . $descripcion . "',
                'Emulacion de Videollamadas'
                )";
    mssql_query($qryLog);
}
