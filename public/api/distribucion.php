<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Auth\Auth;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$metodo = $_SERVER['REQUEST_METHOD'];
$accion = (string) ($_GET['accion'] ?? 'listar');

try {
    $pdo = Connection::get();
    asegurarTablaDistribucion($pdo);

    if ($metodo === 'GET') {
        if ($accion === 'listar') {
            responderViajes($pdo);
        } elseif ($accion === 'resumen') {
            responderResumen($pdo);
        } elseif ($accion === 'maquinas') {
            responderMaquinas($pdo);
        } elseif ($accion === 'ruta') {
            responderRuta($pdo);
        } else {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Accion no valida'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($metodo === 'POST') {
        Auth::requireAdmin();
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'JSON invalido'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($accion === 'guardar') {
            guardarViaje($pdo, $body);
        } elseif ($accion === 'estatus') {
            cambiarEstatus($pdo, $body);
        } elseif ($accion === 'calcular_ruta') {
            calcularRutaFormularioGoogle($body);
        } else {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Accion no valida'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido'], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], JSON_UNESCAPED_UNICODE);
}

function asegurarTablaDistribucion(PDO $pdo): void
{
    $pdo->exec("
        IF OBJECT_ID('vendingm.distribucion_viajes', 'U') IS NULL
        BEGIN
            CREATE TABLE vendingm.distribucion_viajes (
                id_viaje INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                id_maquina INT NOT NULL,
                origen_nombre NVARCHAR(180) NULL,
                origen_latitud DECIMAL(10,7) NULL,
                origen_longitud DECIMAL(10,7) NULL,
                destino_nombre NVARCHAR(180) NOT NULL,
                destino_direccion NVARCHAR(300) NULL,
                destino_latitud DECIMAL(10,7) NULL,
                destino_longitud DECIMAL(10,7) NULL,
                fecha_salida_programada DATETIME2 NULL,
                fecha_limite DATETIME2 NOT NULL,
                distancia_km DECIMAL(10,2) NULL,
                horas_google DECIMAL(8,2) NULL,
                casetas_monto DECIMAL(12,2) NULL,
                casetas_moneda NVARCHAR(8) NULL,
                gasolina_litros DECIMAL(10,2) NULL,
                rendimiento_km_l DECIMAL(8,2) NULL,
                gasolina_precio_litro DECIMAL(10,2) NULL,
                gasolina_costo DECIMAL(12,2) NULL,
                ruta_polyline NVARCHAR(MAX) NULL,
                ruta_calculada_en DATETIME2 NULL,
                velocidad_promedio_kmh DECIMAL(8,2) NOT NULL CONSTRAINT DF_dist_viajes_vel DEFAULT 65,
                estatus NVARCHAR(20) NOT NULL CONSTRAINT DF_dist_viajes_estatus DEFAULT 'programado',
                responsable NVARCHAR(120) NULL,
                notas NVARCHAR(500) NULL,
                fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_dist_viajes_creacion DEFAULT SYSDATETIME(),
                fecha_actualizacion DATETIME2 NULL
            );
            CREATE INDEX IX_dist_viajes_fecha ON vendingm.distribucion_viajes (estatus, fecha_limite);
            CREATE INDEX IX_dist_viajes_maquina ON vendingm.distribucion_viajes (id_maquina);
        END

        IF COL_LENGTH('vendingm.distribucion_viajes', 'horas_google') IS NULL
            ALTER TABLE vendingm.distribucion_viajes ADD horas_google DECIMAL(8,2) NULL;
        IF COL_LENGTH('vendingm.distribucion_viajes', 'casetas_monto') IS NULL
            ALTER TABLE vendingm.distribucion_viajes ADD casetas_monto DECIMAL(12,2) NULL;
        IF COL_LENGTH('vendingm.distribucion_viajes', 'casetas_moneda') IS NULL
            ALTER TABLE vendingm.distribucion_viajes ADD casetas_moneda NVARCHAR(8) NULL;
        IF COL_LENGTH('vendingm.distribucion_viajes', 'gasolina_litros') IS NULL
            ALTER TABLE vendingm.distribucion_viajes ADD gasolina_litros DECIMAL(10,2) NULL;
        IF COL_LENGTH('vendingm.distribucion_viajes', 'rendimiento_km_l') IS NULL
            ALTER TABLE vendingm.distribucion_viajes ADD rendimiento_km_l DECIMAL(8,2) NULL;
        IF COL_LENGTH('vendingm.distribucion_viajes', 'gasolina_precio_litro') IS NULL
            ALTER TABLE vendingm.distribucion_viajes ADD gasolina_precio_litro DECIMAL(10,2) NULL;
        IF COL_LENGTH('vendingm.distribucion_viajes', 'gasolina_costo') IS NULL
            ALTER TABLE vendingm.distribucion_viajes ADD gasolina_costo DECIMAL(12,2) NULL;
        IF COL_LENGTH('vendingm.distribucion_viajes', 'ruta_polyline') IS NULL
            ALTER TABLE vendingm.distribucion_viajes ADD ruta_polyline NVARCHAR(MAX) NULL;
        IF COL_LENGTH('vendingm.distribucion_viajes', 'ruta_calculada_en') IS NULL
            ALTER TABLE vendingm.distribucion_viajes ADD ruta_calculada_en DATETIME2 NULL;
    ");
}

function responderMaquinas(PDO $pdo): void
{
    $params = [];
    $scope = Auth::machineScope('m', $params);
    $stmt = $pdo->prepare(
        "SELECT TOP 1000 m.id_maquina, m.numero_serie, m.nombre_ubicacion, m.direccion, m.latitud, m.longitud
         FROM vendingm.maquinas m
         WHERE 1 = 1 {$scope}
         ORDER BY m.numero_serie"
    );
    $stmt->execute($params);
    echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
}

function baseSelectViajes(string $where): string
{
    return "
        SELECT dv.id_viaje, dv.id_maquina, m.numero_serie, m.nombre_ubicacion, m.direccion AS direccion_maquina,
               m.latitud AS maquina_latitud, m.longitud AS maquina_longitud,
               dv.origen_nombre, dv.origen_latitud, dv.origen_longitud,
               dv.destino_nombre, dv.destino_direccion, dv.destino_latitud, dv.destino_longitud,
               dv.fecha_salida_programada, dv.fecha_limite, dv.distancia_km, dv.horas_google,
               dv.casetas_monto, dv.casetas_moneda, dv.gasolina_litros, dv.rendimiento_km_l,
               dv.gasolina_precio_litro, dv.gasolina_costo, dv.ruta_polyline,
               dv.ruta_calculada_en, dv.velocidad_promedio_kmh,
               dv.estatus, dv.responsable, dv.notas, dv.fecha_creacion,
               DATEDIFF(minute, SYSDATETIME(), dv.fecha_limite) / 60.0 AS horas_restantes,
               COALESCE(dv.horas_google,
                   CASE WHEN dv.distancia_km IS NOT NULL AND dv.velocidad_promedio_kmh > 0
                        THEN dv.distancia_km / dv.velocidad_promedio_kmh ELSE NULL END) AS horas_estimadas
        FROM vendingm.distribucion_viajes dv
        INNER JOIN vendingm.maquinas m ON m.id_maquina = dv.id_maquina
        {$where}
    ";
}

function responderViajes(PDO $pdo): void
{
    $estado = (string) ($_GET['estado'] ?? 'activos');
    $q = trim((string) ($_GET['q'] ?? ''));
    $params = [];
    $where = ['1 = 1'];
    $auth = Auth::machineCondition('m', $params);
    if ($auth) {
        $where[] = $auth;
    }
    if ($estado === 'activos') {
        $where[] = "dv.estatus IN ('programado', 'en_ruta')";
    } elseif (in_array($estado, ['programado', 'en_ruta', 'completado', 'cancelado'], true)) {
        $where[] = 'dv.estatus = :estado';
        $params['estado'] = $estado;
    }
    if ($q !== '') {
        $where[] = '(m.numero_serie LIKE :q1 OR m.nombre_ubicacion LIKE :q2 OR dv.destino_nombre LIKE :q3 OR dv.responsable LIKE :q4)';
        $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = "%{$q}%";
    }

    $stmt = $pdo->prepare(baseSelectViajes('WHERE ' . implode(' AND ', $where)) . "
        ORDER BY
            CASE
                WHEN dv.estatus IN ('programado', 'en_ruta') AND dv.fecha_limite < SYSDATETIME() THEN 0
                WHEN dv.estatus IN ('programado', 'en_ruta') AND DATEDIFF(hour, SYSDATETIME(), dv.fecha_limite) <= 24 THEN 1
                WHEN dv.estatus IN ('programado', 'en_ruta') AND DATEDIFF(hour, SYSDATETIME(), dv.fecha_limite) <= 72 THEN 2
                ELSE 3
            END,
            dv.fecha_limite ASC"
    );
    $stmt->execute($params);
    echo json_encode(['ok' => true, 'data' => enriquecerViajes($stmt->fetchAll(PDO::FETCH_ASSOC))], JSON_UNESCAPED_UNICODE);
}

function responderResumen(PDO $pdo): void
{
    $params = [];
    $scope = Auth::machineScope('m', $params);
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_activos,
            SUM(CASE WHEN dv.fecha_limite < SYSDATETIME() THEN 1 ELSE 0 END) AS vencidos,
            SUM(CASE WHEN dv.fecha_limite >= SYSDATETIME() AND DATEDIFF(hour, SYSDATETIME(), dv.fecha_limite) <= 24 THEN 1 ELSE 0 END) AS rojos,
            SUM(CASE WHEN DATEDIFF(hour, SYSDATETIME(), dv.fecha_limite) > 24 AND DATEDIFF(hour, SYSDATETIME(), dv.fecha_limite) <= 72 THEN 1 ELSE 0 END) AS amarillos,
            SUM(CASE WHEN dv.distancia_km IS NULL THEN 1 ELSE 0 END) AS sin_distancia
         FROM vendingm.distribucion_viajes dv
         INNER JOIN vendingm.maquinas m ON m.id_maquina = dv.id_maquina
         WHERE dv.estatus IN ('programado', 'en_ruta') {$scope}"
    );
    $stmt->execute($params);
    echo json_encode(['ok' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC) ?: []], JSON_UNESCAPED_UNICODE);
}

function responderRuta(PDO $pdo): void
{
    $params = [];
    $auth = Auth::machineCondition('m', $params);
    $where = ["dv.estatus IN ('programado', 'en_ruta')"];
    if ($auth) {
        $where[] = $auth;
    }

    $stmt = $pdo->prepare(baseSelectViajes('WHERE ' . implode(' AND ', $where)));
    $stmt->execute($params);
    $viajes = enriquecerViajes($stmt->fetchAll(PDO::FETCH_ASSOC));
    $resultadoGoogle = ordenarRutaConGoogle($viajes);
    if ($resultadoGoogle !== null) {
        echo json_encode(['ok' => true, 'data' => $resultadoGoogle['data'], 'meta' => $resultadoGoogle['meta']], JSON_UNESCAPED_UNICODE);
        return;
    }

    $orden = ordenarRuta($viajes);
    $ultimo = $orden ? $orden[count($orden) - 1] : [];
    $casetas = array_values(array_filter(array_map(static fn(array $v) => numeroOpcional($v['casetas_monto'] ?? null), $orden), static fn($v) => $v !== null));
    $gasolina = array_values(array_filter(array_map(static fn(array $v) => numeroOpcional($v['gasolina_litros'] ?? null), $orden), static fn($v) => $v !== null));
    echo json_encode([
        'ok' => true,
        'data' => $orden,
        'meta' => [
            'proveedor' => 'local',
            'total_km' => numeroOpcional($ultimo['ruta_total_km'] ?? null),
            'total_horas' => numeroOpcional($ultimo['ruta_total_horas'] ?? null),
            'casetas_detectadas' => count($casetas) > 0,
            'casetas_monto' => $casetas ? round(array_sum($casetas), 2) : null,
            'casetas_moneda' => $casetas ? 'MXN' : null,
            'gasolina_litros' => $gasolina ? round(array_sum($gasolina), 2) : null,
            'gasolina_costo_mxn' => null,
            'ruta_polyline' => '',
            'mensaje' => 'Estimacion local usada temporalmente; vuelve a intentar para obtener la ruta vial de Google.',
        ],
    ], JSON_UNESCAPED_UNICODE);
}

function guardarViaje(PDO $pdo, array $body): void
{
    $id = filter_var($body['id_viaje'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $idMaquina = filter_var($body['id_maquina'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $destinoNombre = trim((string) ($body['destino_nombre'] ?? ''));
    $destinoDireccion = trim((string) ($body['destino_direccion'] ?? '')) ?: null;
    $fechaLimite = normalizarFecha($body['fecha_limite'] ?? null);
    $fechaSalida = normalizarFecha($body['fecha_salida_programada'] ?? null);
    $velocidad = numeroOpcional($body['velocidad_promedio_kmh'] ?? null) ?: 65.0;
    $distancia = numeroOpcional($body['distancia_km'] ?? null);
    $horasGoogle = numeroOpcional($body['horas_google'] ?? null);
    $casetasMonto = numeroOpcional($body['casetas_monto'] ?? null);
    $casetasMoneda = strtoupper(substr(trim((string) ($body['casetas_moneda'] ?? 'MXN')), 0, 8)) ?: null;
    $gasolinaLitros = numeroOpcional($body['gasolina_litros'] ?? null);
    $rendimientoKmL = numeroOpcional($body['rendimiento_km_l'] ?? null);
    $gasolinaPrecioLitro = numeroOpcional($body['gasolina_precio_litro'] ?? null);
    $gasolinaCosto = numeroOpcional($body['gasolina_costo'] ?? null);
    $rutaPolyline = trim((string) ($body['ruta_polyline'] ?? '')) ?: null;
    $origenNombre = trim((string) ($body['origen_nombre'] ?? '')) ?: null;
    $origenLat = numeroOpcional($body['origen_latitud'] ?? null);
    $origenLng = numeroOpcional($body['origen_longitud'] ?? null);
    $destinoLat = numeroOpcional($body['destino_latitud'] ?? null);
    $destinoLng = numeroOpcional($body['destino_longitud'] ?? null);
    $responsable = trim((string) ($body['responsable'] ?? '')) ?: null;
    $notas = trim((string) ($body['notas'] ?? '')) ?: null;
    $estatus = (string) ($body['estatus'] ?? 'programado');

    if (!$idMaquina || $destinoNombre === '' || !$fechaLimite || $velocidad <= 0 || !in_array($estatus, ['programado', 'en_ruta', 'completado', 'cancelado'], true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Maquina, destino, fecha limite y velocidad valida son obligatorios'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (!Auth::canAccessMachine($pdo, $idMaquina)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No tienes acceso a esta maquina'], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($distancia === null && $origenLat !== null && $origenLng !== null && $destinoLat !== null && $destinoLng !== null) {
        $distancia = round(haversineKm($origenLat, $origenLng, $destinoLat, $destinoLng) * 1.18, 2);
    }
    if ($estatus === 'completado' && ($destinoDireccion === null || $destinoLat === null || $destinoLng === null)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Calcula una ruta valida antes de completar el viaje'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $pdo->beginTransaction();
    try {
    if ($id) {
        $stmt = $pdo->prepare(
            "UPDATE vendingm.distribucion_viajes
             SET id_maquina = :id_maquina, origen_nombre = :origen_nombre, origen_latitud = :origen_latitud,
                 origen_longitud = :origen_longitud, destino_nombre = :destino_nombre,
                 destino_direccion = :destino_direccion, destino_latitud = :destino_latitud,
                 destino_longitud = :destino_longitud, fecha_salida_programada = :fecha_salida,
                 fecha_limite = :fecha_limite, distancia_km = :distancia_km,
                 horas_google = :horas_google, casetas_monto = :casetas_monto,
                 casetas_moneda = :casetas_moneda, gasolina_litros = :gasolina_litros,
                 rendimiento_km_l = :rendimiento_km_l, gasolina_precio_litro = :gasolina_precio_litro,
                 gasolina_costo = :gasolina_costo,
                 ruta_polyline = :ruta_polyline,
                 ruta_calculada_en = CASE WHEN :ruta_polyline_fecha IS NOT NULL THEN SYSDATETIME() ELSE ruta_calculada_en END,
                 velocidad_promedio_kmh = :velocidad, estatus = :estatus,
                 responsable = :responsable, notas = :notas, fecha_actualizacion = SYSDATETIME()
             WHERE id_viaje = :id"
        );
        $accion = 'actualizado';
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO vendingm.distribucion_viajes
                (id_maquina, origen_nombre, origen_latitud, origen_longitud, destino_nombre, destino_direccion,
                 destino_latitud, destino_longitud, fecha_salida_programada, fecha_limite, distancia_km,
                 horas_google, casetas_monto, casetas_moneda, gasolina_litros, rendimiento_km_l,
                 gasolina_precio_litro, gasolina_costo, ruta_polyline, ruta_calculada_en,
                 velocidad_promedio_kmh, estatus, responsable, notas)
             OUTPUT INSERTED.id_viaje
             VALUES (:id_maquina, :origen_nombre, :origen_latitud, :origen_longitud, :destino_nombre, :destino_direccion,
                 :destino_latitud, :destino_longitud, :fecha_salida, :fecha_limite, :distancia_km,
                 :horas_google, :casetas_monto, :casetas_moneda, :gasolina_litros,
                 :rendimiento_km_l, :gasolina_precio_litro, :gasolina_costo, :ruta_polyline,
                 CASE WHEN :ruta_polyline_fecha IS NOT NULL THEN SYSDATETIME() ELSE NULL END,
                 :velocidad, :estatus, :responsable, :notas)"
        );
        $accion = 'creado';
    }

    $stmt->bindValue('id_maquina', $idMaquina, PDO::PARAM_INT);
    $stmt->bindValue('origen_nombre', $origenNombre);
    $stmt->bindValue('origen_latitud', $origenLat);
    $stmt->bindValue('origen_longitud', $origenLng);
    $stmt->bindValue('destino_nombre', $destinoNombre);
    $stmt->bindValue('destino_direccion', $destinoDireccion);
    $stmt->bindValue('destino_latitud', $destinoLat);
    $stmt->bindValue('destino_longitud', $destinoLng);
    $stmt->bindValue('fecha_salida', $fechaSalida);
    $stmt->bindValue('fecha_limite', $fechaLimite);
    $stmt->bindValue('distancia_km', $distancia);
    $stmt->bindValue('horas_google', $horasGoogle);
    $stmt->bindValue('casetas_monto', $casetasMonto);
    $stmt->bindValue('casetas_moneda', $casetasMoneda);
    $stmt->bindValue('gasolina_litros', $gasolinaLitros);
    $stmt->bindValue('rendimiento_km_l', $rendimientoKmL);
    $stmt->bindValue('gasolina_precio_litro', $gasolinaPrecioLitro);
    $stmt->bindValue('gasolina_costo', $gasolinaCosto);
    $stmt->bindValue('ruta_polyline', $rutaPolyline);
    $stmt->bindValue('ruta_polyline_fecha', $rutaPolyline);
    $stmt->bindValue('velocidad', $velocidad);
    $stmt->bindValue('estatus', $estatus);
    $stmt->bindValue('responsable', $responsable);
    $stmt->bindValue('notas', $notas);
    if ($id) {
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
    }
    $stmt->execute();
    if (!$id) {
        $id = (int) $stmt->fetchColumn();
    }
    if ($estatus === 'completado') {
        actualizarUbicacionMaquinaDesdeViaje($pdo, $id);
    }
    $pdo->commit();
    } catch (\RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        return;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    echo json_encode(['ok' => true, 'data' => ['id_viaje' => $id, 'accion' => $accion]], JSON_UNESCAPED_UNICODE);
}

function cambiarEstatus(PDO $pdo, array $body): void
{
    $id = filter_var($body['id_viaje'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $estatus = (string) ($body['estatus'] ?? '');
    if (!$id || !in_array($estatus, ['programado', 'en_ruta', 'completado', 'cancelado'], true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Estatus no valido'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "UPDATE dv
             SET estatus = :estatus, fecha_actualizacion = SYSDATETIME()
             FROM vendingm.distribucion_viajes dv
             INNER JOIN vendingm.maquinas m ON m.id_maquina = dv.id_maquina
             WHERE dv.id_viaje = :id"
        );
        $stmt->execute(['estatus' => $estatus, 'id' => $id]);
        if ($estatus === 'completado') {
            actualizarUbicacionMaquinaDesdeViaje($pdo, $id);
        }
        $pdo->commit();
    } catch (\RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        return;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    echo json_encode(['ok' => true, 'data' => ['accion' => 'actualizado']], JSON_UNESCAPED_UNICODE);
}

function actualizarUbicacionMaquinaDesdeViaje(PDO $pdo, int $idViaje): void
{
    $stmt = $pdo->prepare(
        "SELECT id_maquina, destino_nombre, destino_direccion, destino_latitud, destino_longitud
         FROM vendingm.distribucion_viajes
         WHERE id_viaje = :id"
    );
    $stmt->execute(['id' => $idViaje]);
    $viaje = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$viaje || trim((string) ($viaje['destino_direccion'] ?? '')) === ''
        || $viaje['destino_latitud'] === null || $viaje['destino_longitud'] === null) {
        throw new RuntimeException('El viaje necesita una direccion de destino calculada antes de completarse');
    }

    $stmt = $pdo->prepare(
        "UPDATE vendingm.maquinas
         SET nombre_ubicacion = :nombre, direccion = :direccion,
             latitud = :latitud, longitud = :longitud, fecha_actualizacion = SYSDATETIME()
         WHERE id_maquina = :id_maquina"
    );
    $stmt->execute([
        'nombre' => trim((string) $viaje['destino_nombre']),
        'direccion' => trim((string) $viaje['destino_direccion']),
        'latitud' => (float) $viaje['destino_latitud'],
        'longitud' => (float) $viaje['destino_longitud'],
        'id_maquina' => (int) $viaje['id_maquina'],
    ]);
}

function calcularRutaFormularioGoogle(array $body): void
{
    $apiKey = trim((string) ($_ENV['GOOGLE_MAPS_API_KEY'] ?? getenv('GOOGLE_MAPS_API_KEY') ?: ''));
    if ($apiKey === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Falta GOOGLE_MAPS_API_KEY para calcular rutas'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $origenLat = numeroOpcional($body['origen_latitud'] ?? null);
    $origenLng = numeroOpcional($body['origen_longitud'] ?? null);
    $origenDireccion = trim((string) ($body['origen_direccion'] ?? $body['origen_nombre'] ?? ''));
    if ($origenLat !== null && $origenLng !== null) {
        $origen = [
            'waypoint' => latLngWaypoint($origenLat, $origenLng),
            'meta' => ['lat' => $origenLat, 'lng' => $origenLng],
        ];
    } elseif ($origenDireccion !== '') {
        $origen = [
            'waypoint' => ['address' => $origenDireccion],
            'meta' => ['direccion' => $origenDireccion],
        ];
    } else {
        $origen = origenRutaGoogle([]);
    }
    if ($origen === null) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Falta configurar el origen de distribucion'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $destinoLat = numeroOpcional($body['destino_latitud'] ?? null);
    $destinoLng = numeroOpcional($body['destino_longitud'] ?? null);
    $destinoDireccion = trim((string) ($body['destino_direccion'] ?? $body['destino_nombre'] ?? ''));
    if ($destinoLat !== null && $destinoLng !== null) {
        $destino = latLngWaypoint($destinoLat, $destinoLng);
    } elseif ($destinoDireccion !== '') {
        $destino = ['address' => $destinoDireccion];
    } else {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Captura una direccion de destino para calcular'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $bodyGoogle = [
        'origin' => $origen['waypoint'],
        'destination' => $destino,
        'travelMode' => 'DRIVE',
        'routingPreference' => 'TRAFFIC_AWARE',
        'units' => 'METRIC',
        'languageCode' => 'es-MX',
        'regionCode' => 'MX',
        'extraComputations' => ['TOLLS', 'FUEL_CONSUMPTION'],
        'routeModifiers' => [
            'vehicleInfo' => [
                'emissionType' => 'GASOLINE',
            ],
        ],
    ];

    $fieldMask = 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline,routes.travelAdvisory.tollInfo,routes.travelAdvisory.fuelConsumptionMicroliters';
    $errorGoogle = null;
    $respuesta = googleRoutesRequest($apiKey, $bodyGoogle, $fieldMask, $errorGoogle);
    if (!$respuesta || empty($respuesta['routes'])) {
        unset($bodyGoogle['extraComputations'], $bodyGoogle['routeModifiers']);
        $fieldMask = 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline';
        $respuesta = googleRoutesRequest($apiKey, $bodyGoogle, $fieldMask, $errorGoogle);
    }
    if (!$respuesta || empty($respuesta['routes'])) {
        unset($bodyGoogle['routingPreference']);
        $respuesta = googleRoutesRequest($apiKey, $bodyGoogle, $fieldMask, $errorGoogle);
    }
    $route = $respuesta['routes'][0] ?? null;
    if (!$route) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $errorGoogle ?: 'Google Routes no devolvio una ruta manejable para ese origen y destino'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $casetas = extraerMontoGoogle($route['travelAdvisory']['tollInfo']['estimatedPrice'] ?? []);
    $combustibleMicrolitros = isset($route['travelAdvisory']['fuelConsumptionMicroliters'])
        ? (float) $route['travelAdvisory']['fuelConsumptionMicroliters']
        : null;
    $combustibleLitros = $combustibleMicrolitros !== null ? round($combustibleMicrolitros / 1000000, 2) : null;

    echo json_encode([
        'ok' => true,
        'data' => [
            'distancia_km' => round(((int) ($route['distanceMeters'] ?? 0)) / 1000, 2),
            'horas' => round(duracionGoogleSegundos((string) ($route['duration'] ?? '0s')) / 3600, 2),
            'casetas_detectadas' => isset($route['travelAdvisory']['tollInfo']),
            'casetas_monto' => $casetas['monto'],
            'casetas_moneda' => $casetas['moneda'],
            'gasolina_litros' => $combustibleLitros,
            'ruta_polyline' => (string) ($route['polyline']['encodedPolyline'] ?? ''),
            'datos_aproximados' => true,
        ],
    ], JSON_UNESCAPED_UNICODE);
}

function enriquecerViajes(array $filas): array
{
    return array_map(static function (array $f): array {
        $horasRestantes = isset($f['horas_restantes']) ? (float) $f['horas_restantes'] : null;
        $horasEstimadas = isset($f['horas_estimadas']) ? (float) $f['horas_estimadas'] : null;
        $margen = $horasRestantes !== null && $horasEstimadas !== null ? $horasRestantes - $horasEstimadas : null;

        $estadoCalculado = 'ok';
        if (in_array($f['estatus'], ['completado', 'cancelado'], true)) {
            $estadoCalculado = (string) $f['estatus'];
        } elseif ($horasRestantes !== null && $horasRestantes < 0) {
            $estadoCalculado = 'vencido';
        } elseif ($margen !== null && $margen < 0) {
            $estadoCalculado = 'rojo';
        } elseif ($horasRestantes !== null && $horasRestantes <= 24) {
            $estadoCalculado = 'rojo';
        } elseif ($horasRestantes !== null && $horasRestantes <= 72) {
            $estadoCalculado = 'amarillo';
        }

        $f['horas_restantes'] = $horasRestantes;
        $f['horas_estimadas'] = $horasEstimadas;
        $f['margen_horas'] = $margen;
        $f['estado_calculado'] = $estadoCalculado;
        return $f;
    }, $filas);
}

function ordenarRuta(array $viajes): array
{
    usort($viajes, static function (array $a, array $b): int {
        $rank = ['vencido' => 0, 'rojo' => 1, 'amarillo' => 2, 'ok' => 3];
        $ra = $rank[$a['estado_calculado']] ?? 4;
        $rb = $rank[$b['estado_calculado']] ?? 4;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        return strcmp((string) $a['fecha_limite'], (string) $b['fecha_limite']);
    });

    $orden = [];
    $origen = origenRuta($viajes);
    $actualLat = $origen['lat'] ?? null;
    $actualLng = $origen['lng'] ?? null;
    $pendientes = array_values($viajes);
    $totalKm = 0.0;
    $totalHoras = 0.0;

    while ($pendientes) {
        $idx = 0;
        if ($actualLat !== null && $actualLng !== null) {
            $mejor = PHP_FLOAT_MAX;
            foreach ($pendientes as $i => $v) {
                if ($v['destino_latitud'] === null || $v['destino_longitud'] === null) {
                    continue;
                }
                $km = haversineKm($actualLat, $actualLng, (float) $v['destino_latitud'], (float) $v['destino_longitud']) * 1.18;
                $penalizacion = in_array($v['estado_calculado'], ['vencido', 'rojo'], true) ? -1000 : 0;
                if ($km + $penalizacion < $mejor) {
                    $mejor = $km + $penalizacion;
                    $idx = $i;
                }
            }
        }
        $viaje = array_splice($pendientes, $idx, 1)[0];
        $tramo = null;
        if ($actualLat !== null && $actualLng !== null && $viaje['destino_latitud'] !== null && $viaje['destino_longitud'] !== null) {
            $tramo = round(haversineKm($actualLat, $actualLng, (float) $viaje['destino_latitud'], (float) $viaje['destino_longitud']) * 1.18, 2);
            $totalKm += $tramo;
        } elseif ($viaje['distancia_km'] !== null) {
            $tramo = (float) $viaje['distancia_km'];
            $totalKm += $tramo;
        }
        $tramoHoras = numeroOpcional($viaje['horas_google'] ?? null);
        if ($tramoHoras === null && $tramo !== null) {
            $velocidad = numeroOpcional($viaje['velocidad_promedio_kmh'] ?? null) ?: 65.0;
            $tramoHoras = $velocidad > 0 ? round($tramo / $velocidad, 2) : null;
        }
        if ($tramoHoras !== null) {
            $totalHoras += $tramoHoras;
        }
        $viaje['tramo_km'] = $tramo;
        $viaje['tramo_horas'] = $tramoHoras;
        $viaje['ruta_total_km'] = round($totalKm, 2);
        $viaje['ruta_total_horas'] = round($totalHoras, 2);
        $orden[] = $viaje;
        if ($viaje['destino_latitud'] !== null && $viaje['destino_longitud'] !== null) {
            $actualLat = (float) $viaje['destino_latitud'];
            $actualLng = (float) $viaje['destino_longitud'];
        }
    }

    return $orden;
}

function ordenarRutaConGoogle(array $viajes): ?array
{
    $apiKey = trim((string) ($_ENV['GOOGLE_MAPS_API_KEY'] ?? getenv('GOOGLE_MAPS_API_KEY') ?: ''));
    if ($apiKey === '') {
        return null;
    }

    $pendientes = array_values(array_filter($viajes, static fn(array $v): bool =>
        waypointViajeGoogle($v) !== null
    ));
    if (count($pendientes) < 1 || count($pendientes) > 25) {
        return null;
    }

    $origenGoogle = origenRutaGoogle($pendientes);
    if ($origenGoogle === null) {
        return null;
    }

    usort($pendientes, static function (array $a, array $b): int {
        $rank = ['vencido' => 0, 'rojo' => 1, 'amarillo' => 2, 'ok' => 3];
        $ra = $rank[$a['estado_calculado']] ?? 4;
        $rb = $rank[$b['estado_calculado']] ?? 4;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        return strcmp((string) $a['fecha_limite'], (string) $b['fecha_limite']);
    });

    $destinoFinal = array_pop($pendientes);
    if (!$destinoFinal) {
        return null;
    }

    $body = [
        'origin' => $origenGoogle['waypoint'],
        'destination' => waypointViajeGoogle($destinoFinal),
        'travelMode' => 'DRIVE',
        'routingPreference' => 'TRAFFIC_AWARE',
        'units' => 'METRIC',
        'languageCode' => 'es-MX',
        'regionCode' => 'MX',
        'extraComputations' => ['TOLLS', 'FUEL_CONSUMPTION'],
        'routeModifiers' => [
            'vehicleInfo' => [
                'emissionType' => 'GASOLINE',
            ],
        ],
    ];
    if ($pendientes) {
        $body['intermediates'] = array_map(static fn(array $v): array => waypointViajeGoogle($v), $pendientes);
        $body['optimizeWaypointOrder'] = true;
    }

    $fieldMask = 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline,routes.travelAdvisory.tollInfo,routes.travelAdvisory.fuelConsumptionMicroliters,routes.legs.distanceMeters,routes.legs.duration,routes.legs.endLocation,routes.optimizedIntermediateWaypointIndex';
    $errorGoogle = null;
    $respuesta = googleRoutesRequest($apiKey, $body, $fieldMask, $errorGoogle);
    if (!$respuesta) {
        unset($body['extraComputations'], $body['routeModifiers']);
        $body['extraComputations'] = ['TOLLS'];
        $fieldMask = 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline,routes.travelAdvisory.tollInfo,routes.legs.distanceMeters,routes.legs.duration,routes.legs.endLocation,routes.optimizedIntermediateWaypointIndex';
        $respuesta = googleRoutesRequest($apiKey, $body, $fieldMask, $errorGoogle);
    }
    if (!$respuesta) {
        unset($body['extraComputations'], $body['routingPreference']);
        $fieldMask = 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline,routes.legs.distanceMeters,routes.legs.duration,routes.legs.endLocation,routes.optimizedIntermediateWaypointIndex';
        $respuesta = googleRoutesRequest($apiKey, $body, $fieldMask, $errorGoogle);
    }
    if (!$respuesta || empty($respuesta['routes'][0])) {
        return null;
    }

    $route = $respuesta['routes'][0];
    $indices = $pendientes
        ? ($route['optimizedIntermediateWaypointIndex'] ?? range(0, count($pendientes) - 1))
        : [];
    $ordenados = [];
    foreach ($indices as $idx) {
        if (isset($pendientes[(int) $idx])) {
            $ordenados[] = $pendientes[(int) $idx];
        }
    }
    $ordenados[] = $destinoFinal;

    $legs = $route['legs'] ?? [];
    $totalKm = 0.0;
    $totalSegundos = 0;
    $casetas = extraerMontoGoogle($route['travelAdvisory']['tollInfo']['estimatedPrice'] ?? []);
    $combustibleMicrolitros = isset($route['travelAdvisory']['fuelConsumptionMicroliters'])
        ? (float) $route['travelAdvisory']['fuelConsumptionMicroliters']
        : null;
    $combustibleLitros = $combustibleMicrolitros !== null ? round($combustibleMicrolitros / 1000000, 2) : null;
    foreach ($ordenados as $i => &$viaje) {
        $leg = $legs[$i] ?? null;
        $tramoKm = $leg && isset($leg['distanceMeters']) ? round(((int) $leg['distanceMeters']) / 1000, 2) : null;
        $tramoSegundos = $leg && isset($leg['duration']) ? duracionGoogleSegundos((string) $leg['duration']) : null;
        if ($tramoKm !== null) {
            $totalKm += $tramoKm;
        }
        if ($tramoSegundos !== null) {
            $totalSegundos += $tramoSegundos;
        }
        $viaje['tramo_km'] = $tramoKm;
        $viaje['tramo_horas'] = $tramoSegundos !== null ? round($tramoSegundos / 3600, 2) : null;
        $viaje['ruta_total_km'] = round($totalKm, 2);
        $viaje['ruta_total_horas'] = round($totalSegundos / 3600, 2);
        $finTramo = $leg['endLocation']['latLng'] ?? null;
        if (is_array($finTramo)) {
            $viaje['destino_latitud'] = numeroOpcional($finTramo['latitude'] ?? null);
            $viaje['destino_longitud'] = numeroOpcional($finTramo['longitude'] ?? null);
        }
    }
    unset($viaje);

    $distanciaTotalKm = round(((int) ($route['distanceMeters'] ?? round($totalKm * 1000))) / 1000, 2);
    $rendimientoKmL = null;
    $precioLitro = null;
    foreach ($ordenados as $viaje) {
        $rendimientoKmL ??= numeroOpcional($viaje['rendimiento_km_l'] ?? null);
        $precioLitro ??= numeroOpcional($viaje['gasolina_precio_litro'] ?? null);
    }
    $rendimientoKmL ??= numeroOpcional($_ENV['DISTRIBUCION_RENDIMIENTO_KM_L'] ?? getenv('DISTRIBUCION_RENDIMIENTO_KM_L') ?: null) ?: 10.0;
    $precioLitro ??= numeroOpcional($_ENV['DISTRIBUCION_GASOLINA_PRECIO_LITRO_MXN'] ?? getenv('DISTRIBUCION_GASOLINA_PRECIO_LITRO_MXN') ?: null);
    if ($combustibleLitros === null && $rendimientoKmL > 0 && $distanciaTotalKm > 0) {
        $combustibleLitros = round($distanciaTotalKm / $rendimientoKmL, 2);
    }

    return [
        'data' => $ordenados,
        'meta' => [
            'proveedor' => 'google_routes',
            'origen' => $origenGoogle['meta'],
            'total_km' => $distanciaTotalKm,
            'total_horas' => round(duracionGoogleSegundos((string) ($route['duration'] ?? "{$totalSegundos}s")) / 3600, 2),
            'casetas_detectadas' => isset($route['travelAdvisory']['tollInfo']),
            'casetas_monto' => $casetas['monto'],
            'casetas_moneda' => $casetas['moneda'],
            'gasolina_litros' => $combustibleLitros,
            'gasolina_costo_mxn' => $combustibleLitros !== null && $precioLitro !== null ? round($combustibleLitros * $precioLitro, 2) : null,
            'gasolina_rendimiento_km_l' => $rendimientoKmL,
            'ruta_polyline' => (string) ($route['polyline']['encodedPolyline'] ?? ''),
        ],
    ];
}

function origenRutaGoogle(array $viajes): ?array
{
    $latEnv = numeroOpcional($_ENV['DISTRIBUCION_ORIGEN_LAT'] ?? getenv('DISTRIBUCION_ORIGEN_LAT') ?: null);
    $lngEnv = numeroOpcional($_ENV['DISTRIBUCION_ORIGEN_LNG'] ?? getenv('DISTRIBUCION_ORIGEN_LNG') ?: null);
    if ($latEnv !== null && $lngEnv !== null) {
        return [
            'waypoint' => latLngWaypoint($latEnv, $lngEnv),
            'meta' => [
                'lat' => $latEnv,
                'lng' => $lngEnv,
                'nombre' => (string) ($_ENV['DISTRIBUCION_ORIGEN_NOMBRE'] ?? getenv('DISTRIBUCION_ORIGEN_NOMBRE') ?: 'Origen configurado'),
            ],
        ];
    }

    $direccion = trim((string) ($_ENV['DISTRIBUCION_ORIGEN_DIRECCION'] ?? getenv('DISTRIBUCION_ORIGEN_DIRECCION') ?: ''));
    if ($direccion === '') {
        $latLng = origenRuta($viajes);
        return $latLng !== null ? [
            'waypoint' => latLngWaypoint($latLng['lat'], $latLng['lng']),
            'meta' => $latLng,
        ] : null;
    }

    return [
        'waypoint' => ['address' => $direccion],
        'meta' => [
            'direccion' => $direccion,
            'nombre' => (string) ($_ENV['DISTRIBUCION_ORIGEN_NOMBRE'] ?? getenv('DISTRIBUCION_ORIGEN_NOMBRE') ?: 'Origen configurado'),
        ],
    ];
}

function origenRuta(array $viajes): ?array
{
    $latEnv = numeroOpcional($_ENV['DISTRIBUCION_ORIGEN_LAT'] ?? getenv('DISTRIBUCION_ORIGEN_LAT') ?: null);
    $lngEnv = numeroOpcional($_ENV['DISTRIBUCION_ORIGEN_LNG'] ?? getenv('DISTRIBUCION_ORIGEN_LNG') ?: null);
    if ($latEnv !== null && $lngEnv !== null) {
        return [
            'lat' => $latEnv,
            'lng' => $lngEnv,
            'nombre' => (string) ($_ENV['DISTRIBUCION_ORIGEN_NOMBRE'] ?? getenv('DISTRIBUCION_ORIGEN_NOMBRE') ?: 'Origen configurado'),
        ];
    }

    foreach ($viajes as $v) {
        if ($v['origen_latitud'] !== null && $v['origen_longitud'] !== null) {
            return [
                'lat' => (float) $v['origen_latitud'],
                'lng' => (float) $v['origen_longitud'],
                'nombre' => (string) ($v['origen_nombre'] ?: 'Origen del viaje'),
            ];
        }
    }

    return null;
}

function latLngWaypoint(float $lat, float $lng): array
{
    return [
        'location' => [
            'latLng' => [
                'latitude' => $lat,
                'longitude' => $lng,
            ],
        ],
    ];
}

function waypointViajeGoogle(array $viaje): ?array
{
    $lat = numeroOpcional($viaje['destino_latitud'] ?? null);
    $lng = numeroOpcional($viaje['destino_longitud'] ?? null);
    if ($lat !== null && $lng !== null) {
        return latLngWaypoint($lat, $lng);
    }

    $direccion = trim((string) ($viaje['destino_direccion'] ?? ''));
    if ($direccion === '') {
        $direccion = trim((string) ($viaje['destino_nombre'] ?? ''));
    }
    return $direccion !== '' ? ['address' => $direccion] : null;
}

function googleRoutesRequest(string $apiKey, array $body, string $fieldMask, ?string &$error): ?array
{
    $url = 'https://routes.googleapis.com/directions/v2:computeRoutes';
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => [
                'Content-Type: application/json',
                'X-Goog-Api-Key: ' . $apiKey,
                'X-Goog-FieldMask: ' . $fieldMask,
            ],
            'content' => $payload,
            'timeout' => 12,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if (($raw === false || $raw === '') && function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Goog-Api-Key: ' . $apiKey,
                'X-Goog-FieldMask: ' . $fieldMask,
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $rawCurl = curl_exec($curl);
        if (is_string($rawCurl) && $rawCurl !== '') {
            $raw = $rawCurl;
        } elseif (curl_error($curl) !== '') {
            $error = 'No se pudo conectar con Google Routes: ' . curl_error($curl);
        }
        curl_close($curl);
    }
    if ($raw === false || $raw === '') {
        $error = $error ?: 'No hubo respuesta de Google Routes.';
        return null;
    }
    $json = json_decode($raw, true);
    if (is_array($json) && !empty($json['error'])) {
        $mensaje = (string) ($json['error']['message'] ?? 'Google Routes rechazo la solicitud.');
        $codigo = (string) ($json['error']['status'] ?? '');
        $error = trim($codigo . ($codigo ? ': ' : '') . $mensaje);
    }
    return is_array($json) && empty($json['error']) ? $json : null;
}

function extraerMontoGoogle(array $precios): array
{
    if (!$precios) {
        return ['monto' => null, 'moneda' => null];
    }
    $total = 0.0;
    $moneda = null;
    foreach ($precios as $precio) {
        if (!is_array($precio)) {
            continue;
        }
        $moneda = $moneda ?: ($precio['currencyCode'] ?? null);
        $units = isset($precio['units']) ? (float) $precio['units'] : 0.0;
        $nanos = isset($precio['nanos']) ? ((float) $precio['nanos']) / 1000000000 : 0.0;
        $total += $units + $nanos;
    }
    return ['monto' => $total > 0 ? round($total, 2) : null, 'moneda' => $moneda];
}

function duracionGoogleSegundos(string $valor): int
{
    return (int) rtrim($valor, 's');
}

function normalizarFecha(mixed $valor): ?string
{
    if ($valor === null || $valor === '') {
        return null;
    }
    try {
        return (new DateTime((string) $valor))->format('Y-m-d H:i:s');
    } catch (\Throwable) {
        return null;
    }
}

function numeroOpcional(mixed $valor): ?float
{
    if ($valor === null || $valor === '') {
        return null;
    }
    return is_numeric($valor) ? (float) $valor : null;
}

function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}
