<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Api\AndroidApi;
use App\Database\Connection;
use App\Maquinas\ActivacionMaquina;
use App\Maquinas\CarrilesFisicos;
use App\Maquinas\ConexionMaquina;

AndroidApi::requirePost();
$body = AndroidApi::body();

try {
    $pdo = Connection::get();
    ActivacionMaquina::asegurarColumnas($pdo);
    ConexionMaquina::asegurarColumnas($pdo);
    $maquina = AndroidApi::autenticar($pdo, $body);

    if (!$maquina) {
        AndroidApi::responder(['ok' => false, 'error' => 'Falta id_maquina o numero_serie'], 422);
    }

    $idMaquina = (int) $maquina['id_maquina'];

    $stmtConfig = $pdo->prepare(
        "SELECT m.id_maquina, m.numero_serie, m.codigo_interno, m.nombre_maquina, m.nombre_ubicacion,
                m.direccion, m.latitud, m.longitud, m.id_sucursal_clinica, sc.st_Nombre AS sucursal_nombre,
                m.rol_maquina, m.id_maquina_padre, m.numero_carriles, m.tipo_maquina,
                m.ultima_conexion, m.tipo_conexion, m.version_app,
                m.id_estatus, em.nombre AS estatus_nombre
         FROM vendingm.maquinas m
         LEFT JOIN vendingm.estatus_maquina em ON em.id_estatus = m.id_estatus
         LEFT JOIN fernandoruiz.cat_SucursalClinica sc ON sc.id_SucursalClinica = m.id_sucursal_clinica
         WHERE m.id_maquina = :id"
    );
    $stmtConfig->execute(['id' => $idMaquina]);
    $config = $stmtConfig->fetch(PDO::FETCH_ASSOC);
    if (!$config) {
        AndroidApi::responder(['ok' => false, 'error' => 'Maquina no encontrada'], 404);
    }

    $stmtTelemetria = $pdo->prepare(
        "SELECT TOP 1 id_telemetria, app_version, battery_level, network_type,
                signal_strength, fecha_creacion
         FROM vendingm.api_telemetria_android
         WHERE id_maquina = :id
         ORDER BY fecha_creacion DESC"
    );
    $stmtTelemetria->execute(['id' => $idMaquina]);
    $ultimaTelemetria = $stmtTelemetria->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmtError = $pdo->prepare(
        "SELECT TOP 1 id_error, folio, numero_carril, codigo_barras, codigo_error,
                mensaje, fecha_creacion
         FROM vendingm.api_errores_surtido
         WHERE id_maquina = :id
         ORDER BY fecha_creacion DESC"
    );
    $stmtError->execute(['id' => $idMaquina]);
    $ultimoError = $stmtError->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmtToken = $pdo->prepare(
        "SELECT TOP 1 id_token, revocado, fecha_creacion, fecha_revocacion, ultimo_uso
         FROM vendingm.api_tokens_maquina
         WHERE id_maquina = :id
         ORDER BY fecha_creacion DESC"
    );
    $stmtToken->execute(['id' => $idMaquina]);
    $token = $stmtToken->fetch(PDO::FETCH_ASSOC) ?: null;

    $config['id_maquina'] = (int) $config['id_maquina'];
    $config['rol_maquina'] = (int) $config['rol_maquina'];
    $config['rol'] = $config['rol_maquina'];
    $config['rol_nombre'] = $config['rol_maquina'] === 2 ? 'extension' : 'principal';
    $config['id_maquina_padre'] = $config['id_maquina_padre'] !== null ? (int) $config['id_maquina_padre'] : null;
    $ultimoNumeroCarril = $config['numero_carriles'] !== null ? (int) $config['numero_carriles'] : 0;
    $config['ultimo_numero_carril'] = $ultimoNumeroCarril;
    $config['cantidad_carriles'] = CarrilesFisicos::cantidadHasta($ultimoNumeroCarril);
    $config['numero_carriles'] = $config['cantidad_carriles'];
    $config['nombre_maquina'] = trim((string) ($config['nombre_maquina'] ?? '')) ?: (string) $config['numero_serie'];
    $config['id_sucursal_clinica'] = $config['id_sucursal_clinica'] !== null ? (int) $config['id_sucursal_clinica'] : null;
    $config['sucursal'] = $config['id_sucursal_clinica'] !== null ? [
        'id_sucursal_clinica' => $config['id_sucursal_clinica'],
        'nombre' => $config['sucursal_nombre'] ?: $config['nombre_ubicacion'],
    ] : null;
    unset($config['sucursal_nombre']);
    $config['id_estatus'] = $config['id_estatus'] !== null ? (int) $config['id_estatus'] : null;

    $recetasPendientesOffline = filter_var($body['recetas_pendientes_offline'] ?? 0, FILTER_VALIDATE_INT);
    $recetasIniciadasPendientes = filter_var($body['recetas_iniciadas_pendientes_envio'] ?? 0, FILTER_VALIDATE_INT);
    $finalizacionesPendientes = filter_var($body['finalizaciones_pendientes_envio'] ?? 0, FILTER_VALIDATE_INT);
    $dispensacionesPendientes = filter_var($body['dispensaciones_pendientes_envio'] ?? 0, FILTER_VALIDATE_INT);
    $erroresPendientes = filter_var($body['errores_pendientes_envio'] ?? 0, FILTER_VALIDATE_INT);
    $telemetriaPendiente = filter_var($body['telemetria_pendiente_envio'] ?? 0, FILTER_VALIDATE_INT);
    AndroidApi::responder([
        'ok' => true,
        'data' => [
            'maquina' => $config,
            'conexion' => [
                'api_conectada' => true,
                'token_valido' => true,
                'token_maquina_configurado' => $token !== null,
                'token_revocado' => $token !== null ? (bool) $token['revocado'] : false,
                'ultima_sincronizacion' => $ultimaTelemetria['fecha_creacion'] ?? null,
                'ultima_conexion' => $config['ultima_conexion'] ?? null,
                'tipo_conexion' => $config['tipo_conexion'] ?? null,
                'version_app' => $config['version_app'] ?? null,
                'server_time' => date(DATE_ATOM),
            ],
            'operacion' => [
                'recetas_pendientes_offline' => max(0, (int) $recetasPendientesOffline),
                'recetas_iniciadas_pendientes_envio' => max(0, (int) $recetasIniciadasPendientes),
                'finalizaciones_pendientes_envio' => max(0, (int) $finalizacionesPendientes),
                'dispensaciones_pendientes_envio' => max(0, (int) $dispensacionesPendientes),
                'errores_pendientes_envio' => max(0, (int) $erroresPendientes),
                'telemetria_pendiente_envio' => max(0, (int) $telemetriaPendiente),
                'ultimo_error' => $ultimoError,
                'app_version' => $body['app_version'] ?? ($ultimaTelemetria['app_version'] ?? null),
                'ultima_telemetria' => $ultimaTelemetria,
            ],
            'panel_tecnico' => [
                'modo_acceso' => 'login_tecnico',
                'endpoint_login' => 'android_tecnico_login.php',
                'endpoint_evento' => 'android_tecnico_evento.php',
            ],
        ],
    ]);
} catch (\Throwable $e) {
    AndroidApi::responder(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], 500);
}
