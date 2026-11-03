<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Auth\Auth;
use App\Branding\MaquinaBranding;
use App\Maquinas\CarrilesFisicos;
use App\Maquinas\ActivacionMaquina;
use App\Maquinas\SegmentoMaquina;
use App\Http\JsonResponse;
use App\Vending\TraspasoService;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido, use POST']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    // También aceptamos form-data clásico, por si el front usa FormData en vez de JSON
    $body = $_POST;
}

// --- 1. Validación de entrada ---
$idMaquina       = filter_var($body['id_maquina'] ?? null, FILTER_VALIDATE_INT) ?: null; // null = crear
$numeroSerie     = trim((string) ($body['numero_serie'] ?? ''));
$nombreMaquina   = trim((string) ($body['nombre_maquina'] ?? '')) ?: null;
$codigoInterno   = trim((string) ($body['codigo_interno'] ?? ''));
$nombreUbicacion = trim((string) ($body['nombre_ubicacion'] ?? '')) ?: null;
$direccion       = trim((string) ($body['direccion'] ?? '')) ?: null;
$latitud         = isset($body['latitud']) && $body['latitud'] !== '' ? (float) $body['latitud'] : null;
$longitud        = isset($body['longitud']) && $body['longitud'] !== '' ? (float) $body['longitud'] : null;
$rolMaquina      = filter_var($body['rol_maquina'] ?? 1, FILTER_VALIDATE_INT);
$idMaquinaPadre  = filter_var($body['id_maquina_padre'] ?? null, FILTER_VALIDATE_INT) ?: null;
$numeroCarriles  = filter_var($body['numero_carriles'] ?? null, FILTER_VALIDATE_INT);
$fechaInstalacion = trim((string) ($body['fecha_instalacion'] ?? '')) ?: null;
$tipoMaquina     = trim((string) ($body['tipo_maquina'] ?? '')) ?: null;
$idEstatus       = filter_var($body['id_estatus'] ?? 1, FILTER_VALIDATE_INT) ?: 1;
$idSucursalClinica = filter_var($body['id_sucursal_clinica'] ?? null, FILTER_VALIDATE_INT) ?: null;
$estadoGeo       = trim((string) ($body['estado_geo'] ?? '')) ?: null;
$municipio       = trim((string) ($body['municipio'] ?? '')) ?: null;
$segmentoOperacion = SegmentoMaquina::CLIENTE;

$errores = [];

if ($numeroSerie === '') {
    $errores[] = 'numero_serie es obligatorio';
}
if ($codigoInterno === '') {
    $errores[] = 'codigo_interno es obligatorio';
}
if (!in_array($rolMaquina, [1, 2], true)) {
    $errores[] = 'rol_maquina debe ser 1 (maestra) o 2 (esclava)';
}
if ($rolMaquina === 2 && !$idMaquinaPadre) {
    $errores[] = 'Una esclava requiere id_maquina_padre';
}
if ($rolMaquina === 1 && $idMaquinaPadre) {
    $errores[] = 'Una maestra no debe tener id_maquina_padre';
}

// Si no mandaron numero_carriles, aplicamos el default de negocio: 69 maestra / 58 esclava
if ($numeroCarriles === null || $numeroCarriles === false) {
    $numeroCarriles = $rolMaquina === 1 ? 69 : 58;
}
if ($numeroCarriles < 0 || $numeroCarriles > 500) {
    $errores[] = 'numero_carriles fuera de rango razonable';
}
if ($idEstatus < 1) {
    $errores[] = 'id_estatus no es válido';
}

if ($fechaInstalacion !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInstalacion)) {
    $errores[] = 'fecha_instalacion debe tener formato Y-m-d';
}

if ($errores) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => implode('; ', $errores)]);
    exit;
}

try {
    $pdo = Connection::get();
    ActivacionMaquina::asegurarColumnas($pdo);
    SegmentoMaquina::asegurarColumnas($pdo);

    // --- 2. Validaciones contra la base ---
    // numero_serie y codigo_interno deben ser únicos (ajusta si tu tabla ya tiene UNIQUE constraint,
    // esta verificación explícita da un mensaje más claro que un error 500 de SQL Server).
    $stmtDup = $pdo->prepare(
        'SELECT id_maquina FROM vendingm.maquinas
         WHERE (numero_serie = :serie OR codigo_interno = :codigo)
           AND id_maquina <> :id'
    );
    $stmtDup->execute([
        'serie'  => $numeroSerie,
        'codigo' => $codigoInterno,
        'id'     => $idMaquina ?? 0,
    ]);
    if ($stmtDup->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'numero_serie o codigo_interno ya existen en otra máquina']);
        exit;
    }

    $maquinaPadre = null;
    if ($idMaquinaPadre) {
        $stmtPadre = $pdo->prepare(
            'SELECT id_maquina, rol_maquina, nombre_ubicacion, direccion, latitud, longitud, id_sucursal_clinica, estado_geo, municipio, segmento_operacion FROM vendingm.maquinas WHERE id_maquina = :id'
        );
        $stmtPadre->execute(['id' => $idMaquinaPadre]);
        $maquinaPadre = $stmtPadre->fetch(PDO::FETCH_ASSOC);
        if (!$maquinaPadre) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'id_maquina_padre no existe']);
            exit;
        }
        if ((int) $maquinaPadre['rol_maquina'] !== 1) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'id_maquina_padre debe ser una máquina maestra']);
            exit;
        }
    }

    if ($rolMaquina === 2 && $maquinaPadre) {
        $nombreUbicacion = $maquinaPadre['nombre_ubicacion'] ?: null;
        $direccion = $maquinaPadre['direccion'] ?: null;
        $latitud = $maquinaPadre['latitud'] !== null ? (float) $maquinaPadre['latitud'] : null;
        $longitud = $maquinaPadre['longitud'] !== null ? (float) $maquinaPadre['longitud'] : null;
        $idSucursalClinica = $maquinaPadre['id_sucursal_clinica'] !== null ? (int) $maquinaPadre['id_sucursal_clinica'] : null;
        $estadoGeo = $maquinaPadre['estado_geo'] ?: null;
        $municipio = $maquinaPadre['municipio'] ?: null;
        $segmentoOperacion = SegmentoMaquina::normalizar($maquinaPadre['segmento_operacion'] ?? SegmentoMaquina::CLIENTE);
    }

    if ($idSucursalClinica) {
        $stmtSuc = $pdo->prepare('SELECT 1 FROM fernandoruiz.cat_SucursalClinica WHERE id_SucursalClinica = :id');
        $stmtSuc->execute(['id' => $idSucursalClinica]);
        if (!$stmtSuc->fetchColumn()) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'id_sucursal_clinica no existe']);
            exit;
        }

        // Autoheal: si esta sucursal todavía no tiene su "sucursal virtual"
        // de vending (id_SucursalOrigenVending), se crea aquí mismo -- así
        // el admin nunca tiene que tocar SQL a mano para que el traspaso
        // real (carga de Excel / Resurtir) funcione con esta sucursal.
        // Antes, migracion_traspaso_vending.sql solo la sembraba para
        // sucursales que ya tenían máquina Intecco ligada.
        TraspasoService::asegurarSucursalVirtual($pdo, $idSucursalClinica);
    }

    $pdo->beginTransaction();

    if ($idMaquina === null) {
        // ---------------- CREAR ----------------
        $ins = $pdo->prepare(
            'INSERT INTO vendingm.maquinas
             (numero_serie, nombre_maquina, codigo_interno, nombre_ubicacion, direccion, latitud, longitud,
              numero_carriles, id_estatus, id_maquina_padre, rol_maquina, fecha_instalacion, tipo_maquina,
              id_sucursal_clinica, estado_geo, municipio, segmento_operacion)
             OUTPUT INSERTED.id_maquina
             VALUES (:serie, :nombre, :codigo, :ubic, :dir, :lat, :lng, :carriles, :estatus, :padre, :rol, :finstal, :tipo,
                     :sucursal, :estado_geo, :municipio, :segmento_operacion)'
        );
        $ins->execute([
            'serie'    => $numeroSerie,
            'nombre'   => $nombreMaquina,
            'codigo'   => $codigoInterno,
            'ubic'     => $nombreUbicacion,
            'dir'      => $direccion,
            'lat'      => $latitud,
            'lng'      => $longitud,
            'carriles' => $numeroCarriles,
            'estatus'  => $idEstatus,
            'padre'    => $idMaquinaPadre,
            'rol'      => $rolMaquina,
            'finstal'  => $fechaInstalacion,
            'tipo'     => $tipoMaquina,
            'sucursal' => $idSucursalClinica,
            'estado_geo' => $estadoGeo,
            'municipio'  => $municipio,
            'segmento_operacion' => $segmentoOperacion,
        ]);
        $idMaquina = (int) $ins->fetchColumn();

        CarrilesFisicos::crearFaltantes($pdo, $idMaquina, $numeroCarriles);

        $accion = 'creada';
    } else {
        // ---------------- ACTUALIZAR ----------------
        $stmtActual = $pdo->prepare(
            'SELECT numero_carriles FROM vendingm.maquinas WHERE id_maquina = :id'
        );
        $stmtActual->execute(['id' => $idMaquina]);
        $carrilesActuales = $stmtActual->fetchColumn();

        if ($carrilesActuales === false) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Máquina no encontrada']);
            exit;
        }
        $carrilesActuales = (int) $carrilesActuales;

        $upd = $pdo->prepare(
            'UPDATE vendingm.maquinas SET
                numero_serie = :serie, nombre_maquina = :nombre, codigo_interno = :codigo, nombre_ubicacion = :ubic,
                direccion = :dir, latitud = :lat, longitud = :lng, numero_carriles = :carriles,
                id_estatus = :estatus, id_maquina_padre = :padre, rol_maquina = :rol, fecha_instalacion = :finstal,
                tipo_maquina = :tipo, id_sucursal_clinica = :sucursal,
                estado_geo = :estado_geo, municipio = :municipio,
                segmento_operacion = :segmento_operacion,
                fecha_actualizacion = SYSDATETIME()
             WHERE id_maquina = :id'
        );
        $upd->execute([
            'serie'    => $numeroSerie,
            'nombre'   => $nombreMaquina,
            'codigo'   => $codigoInterno,
            'ubic'     => $nombreUbicacion,
            'dir'      => $direccion,
            'lat'      => $latitud,
            'lng'      => $longitud,
            'carriles' => $numeroCarriles,
            'estatus'  => $idEstatus,
            'padre'    => $idMaquinaPadre,
            'rol'      => $rolMaquina,
            'finstal'  => $fechaInstalacion,
            'tipo'     => $tipoMaquina,
            'sucursal' => $idSucursalClinica,
            'estado_geo' => $estadoGeo,
            'municipio'  => $municipio,
            'segmento_operacion' => $segmentoOperacion,
            'id'       => $idMaquina,
        ]);

        // Si subieron el último número físico, completamos los faltantes.
        // Si lo bajaron, NO borramos carriles existentes automáticamente
        // (podrían tener stock/lotes asociados) — solo avisamos.
        $avisoReduccion = null;
        if ($numeroCarriles > $carrilesActuales) {
            CarrilesFisicos::crearFaltantes($pdo, $idMaquina, $numeroCarriles);
        } elseif ($numeroCarriles < $carrilesActuales) {
            $avisoReduccion = "El número de carriles se redujo de {$carrilesActuales} a {$numeroCarriles}. "
                . "Los carriles " . ($numeroCarriles + 1) . "-{$carrilesActuales} NO se eliminaron automáticamente "
                . "(pueden tener stock). Elimínalos manualmente si corresponde.";
        }

        if ($rolMaquina === 1) {
            $pdo->prepare(
                "UPDATE vendingm.maquinas
                 SET segmento_operacion = :segmento_operacion,
                     fecha_actualizacion = SYSDATETIME()
                 WHERE id_maquina_padre = :id_maquina"
            )->execute([
                'segmento_operacion' => $segmentoOperacion,
                'id_maquina' => $idMaquina,
            ]);
        }

        $accion = 'actualizada';
    }

    CarrilesFisicos::eliminarInvalidosVacios($pdo, $idMaquina);
    $invalidosConDatos = CarrilesFisicos::contarInvalidosConDatos($pdo, $idMaquina);

    // Branding por máquina (logo/video/colores), integrado del fork de
    // Gilberto el 2026-08-24 -- ver src/Branding/MaquinaBranding.php. Solo
    // hace algo si el request trae campos/archivos de branding; si no, es
    // no-op (misma transacción que el resto del guardado).
    if ($rolMaquina === 1) {
        MaquinaBranding::guardarDesdeRequest($pdo, $idMaquina, $body, $_FILES);
    }

    $pdo->commit();

    $respuesta = ['ok' => true, 'data' => ['id_maquina' => $idMaquina, 'accion' => $accion]];
    if (!empty($avisoReduccion)) {
        $respuesta['aviso'] = $avisoReduccion;
    }
    if ($invalidosConDatos > 0) {
        $respuesta['aviso_carriles'] = "Hay {$invalidosConDatos} carril(es) múltiplo de 10 con datos. No se eliminaron para evitar pérdida de stock.";
    }
    echo json_encode($respuesta);
} catch (\PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Violación de la restricción UNIQUE (numero_serie/codigo_interno) a
    // nivel de base de datos: es la garantía definitiva contra la condición
    // de carrera del SELECT de duplicados de arriba, bajo altas concurrentes.
    if ($e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'numero_serie o codigo_interno ya existen en otra máquina']);
        exit;
    }
    JsonResponse::serverError($e, 'maquina_guardar');
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    JsonResponse::serverError($e, 'maquina_guardar');
}
