<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Api\AndroidApi;
use App\Database\Connection;
use App\Auth\Auth;
use App\Branding\MaquinaBranding;
use App\Maquinas\ActivacionMaquina;
use App\Maquinas\ConexionMaquina;
use App\Maquinas\SegmentoMaquina;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$id = filter_input(INPUT_GET, 'id_maquina', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta id_maquina']);
    exit;
}

try {
    $pdo = Connection::get();
    ActivacionMaquina::asegurarColumnas($pdo);
    ConexionMaquina::asegurarColumnas($pdo);
    SegmentoMaquina::asegurarColumnas($pdo);
    AndroidApi::asegurarTablas($pdo);
    if (!Auth::canAccessMachine($pdo, $id)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No tienes acceso a esta maquina']);
        exit;
    }
    $stmt = $pdo->prepare(
        "SELECT m.id_maquina, m.numero_serie, m.codigo_interno, m.nombre_maquina, m.nombre_ubicacion, m.direccion,
                m.latitud, m.longitud, m.rol_maquina, m.id_maquina_padre, m.numero_carriles,
                COALESCE(m.id_sucursal_clinica, mp.id_sucursal_clinica) AS id_sucursal_clinica,
                sc.st_Nombre AS sucursal_nombre,
                m.tipo_maquina, m.fecha_instalacion, m.id_estatus, em.nombre AS estatus_nombre,
                m.activada, m.fecha_activacion, m.fecha_ultima_activacion, m.activada_por_app_version, m.permitir_reactivacion,
                m.id_tecnico_instalador, m.tecnico_instalador_nombre,
                m.segmento_operacion,
                m.estado_geo, m.municipio,
                m.fecha_ultimo_mantenimiento,
                m.ultima_conexion, COALESCE(m.tipo_conexion, mp.tipo_conexion) AS tipo_conexion,
                COALESCE(m.version_app, mp.version_app) AS version_app,
                CASE
                    WHEN m.ultima_conexion IS NULL THEN 'nunca_conectada'
                    WHEN m.ultima_conexion >= DATEADD(minute, -10, SYSDATETIME()) THEN 'online'
                    ELSE 'offline'
                END AS estado_conexion,
                DATEDIFF(second, m.ultima_conexion, SYSDATETIME()) AS segundos_desde_conexion,
                tok.fecha_creacion AS token_android_generado,
                DATEDIFF(day, m.fecha_ultimo_mantenimiento, SYSDATETIME()) AS dias_sin_mantenimiento,
                mp.numero_serie AS numero_serie_padre
         FROM vendingm.maquinas m
         LEFT JOIN vendingm.maquinas mp ON mp.id_maquina = m.id_maquina_padre
         LEFT JOIN vendingm.estatus_maquina em ON em.id_estatus = m.id_estatus
         LEFT JOIN fernandoruiz.cat_SucursalClinica sc ON sc.id_SucursalClinica = COALESCE(m.id_sucursal_clinica, mp.id_sucursal_clinica)
         OUTER APPLY (
             SELECT TOP 1 fecha_creacion FROM vendingm.api_tokens_maquina
             WHERE id_maquina = m.id_maquina AND revocado = 0
             ORDER BY fecha_creacion DESC
         ) tok
         WHERE m.id_maquina = :id"
    );
    $stmt->execute(['id' => $id]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fila) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Máquina no encontrada']);
        exit;
    }

    // Si es maestra, incrustamos sus esclavas para que la vista de detalle
    // pueda mostrar la unidad completa (maestra + esclavas) con una sola llamada.
    if ((int) $fila['rol_maquina'] === 1) {
        $stmtE = $pdo->prepare(
            "SELECT e.id_maquina, e.numero_serie, e.codigo_interno, e.nombre_maquina, e.nombre_ubicacion, e.direccion,
                    COALESCE(e.id_sucursal_clinica, p.id_sucursal_clinica) AS id_sucursal_clinica,
                    sc.st_Nombre AS sucursal_nombre,
                    e.estado_geo, e.municipio,
                    e.activada, e.fecha_activacion, e.fecha_ultima_activacion, e.activada_por_app_version, e.permitir_reactivacion,
                    e.id_tecnico_instalador, e.tecnico_instalador_nombre,
                    COALESCE(e.segmento_operacion, p.segmento_operacion, 'cliente') AS segmento_operacion,
                    e.numero_carriles, e.id_estatus, em.nombre AS estatus_nombre, e.fecha_ultimo_mantenimiento,
                    e.ultima_conexion, COALESCE(e.tipo_conexion, p.tipo_conexion) AS tipo_conexion,
                    COALESCE(e.version_app, p.version_app) AS version_app,
                    CASE
                        WHEN e.ultima_conexion IS NULL THEN 'nunca_conectada'
                        WHEN e.ultima_conexion >= DATEADD(minute, -10, SYSDATETIME()) THEN 'online'
                        ELSE 'offline'
                    END AS estado_conexion,
                    DATEDIFF(second, e.ultima_conexion, SYSDATETIME()) AS segundos_desde_conexion,
                    tok.fecha_creacion AS token_android_generado,
                    DATEDIFF(day, e.fecha_ultimo_mantenimiento, SYSDATETIME()) AS dias_sin_mantenimiento
             FROM vendingm.maquinas e
             INNER JOIN vendingm.maquinas p ON p.id_maquina = e.id_maquina_padre
             LEFT JOIN vendingm.estatus_maquina em ON em.id_estatus = e.id_estatus
             LEFT JOIN fernandoruiz.cat_SucursalClinica sc ON sc.id_SucursalClinica = COALESCE(e.id_sucursal_clinica, p.id_sucursal_clinica)
             OUTER APPLY (
                 SELECT TOP 1 fecha_creacion FROM vendingm.api_tokens_maquina
                 WHERE id_maquina = e.id_maquina AND revocado = 0
                 ORDER BY fecha_creacion DESC
             ) tok
             WHERE e.id_maquina_padre = :id
             ORDER BY e.numero_serie"
        );
        $stmtE->execute(['id' => $id]);
        $fila['esclavas'] = $stmtE->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $fila['esclavas'] = [];
    }

    $fila['branding'] = MaquinaBranding::serializarParaAdmin(MaquinaBranding::obtener($pdo, (int) $fila['id_maquina']));

    echo json_encode(['ok' => true, 'data' => $fila]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')]);
}
