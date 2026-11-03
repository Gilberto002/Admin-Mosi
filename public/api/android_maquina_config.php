<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Api\AndroidApi;
use App\Branding\MaquinaBranding;
use App\Catalogo\ProductoCatalogo;
use App\Database\Connection;
use App\Maquinas\ActivacionMaquina;
use App\Maquinas\CarrilesFisicos;
use App\Maquinas\ConexionMaquina;
use App\Maquinas\SegmentoMaquina;
use App\Vending\TraspasoService;

AndroidApi::requirePost();
$body = AndroidApi::body();

try {
    $pdo = Connection::get();
    ProductoCatalogo::asegurarColumnas($pdo);
    ConexionMaquina::asegurarColumnas($pdo);
    AndroidApi::asegurarTablas($pdo);
    SegmentoMaquina::asegurarColumnas($pdo);

    $numeroSerie = trim((string) ($body['numero_serie'] ?? ''));
    $numeroSerieEsclava = trim((string) ($body['numero_serie_esclava'] ?? ''));

    if ($numeroSerie === '') {
        AndroidApi::responder(['ok' => false, 'error' => 'Falta numero_serie'], 422);
    }
    if ($numeroSerieEsclava !== '' && strcasecmp($numeroSerie, $numeroSerieEsclava) === 0) {
        AndroidApi::responder(['ok' => false, 'error' => 'numero_serie_esclava debe ser diferente a numero_serie'], 422);
    }

    $confirmarActivacion = filter_var($body['confirmar_activacion'] ?? false, FILTER_VALIDATE_BOOLEAN);
    // La activación de clientes se autoriza con una serie previamente registrada.
    // No requiere login, sesión TECH_ ni token de un técnico.
    ActivacionMaquina::asegurarColumnas($pdo);

    $sqlMaquina =
        "SELECT m.id_maquina, m.numero_serie, m.codigo_interno, m.nombre_ubicacion, m.direccion,
                m.latitud, m.longitud, m.rol_maquina, m.id_maquina_padre, m.numero_carriles,
                m.nombre_maquina, m.id_sucursal_clinica, m.segmento_operacion,
                m.activada, m.fecha_activacion, m.fecha_ultima_activacion,
                m.activada_por_app_version, m.permitir_reactivacion,
                m.id_tecnico_instalador, m.tecnico_instalador_nombre,
                sc.st_Nombre AS sucursal_nombre,
                m.tipo_maquina, m.id_estatus, em.nombre AS estatus_nombre
         FROM vendingm.maquinas m
         LEFT JOIN vendingm.estatus_maquina em ON em.id_estatus = m.id_estatus
         LEFT JOIN fernandoruiz.cat_SucursalClinica sc
           ON sc.id_SucursalClinica = m.id_sucursal_clinica AND sc.i_Activo = 1
         WHERE m.numero_serie = :serie";
    $stmt = $pdo->prepare($sqlMaquina);
    $stmt->execute(['serie' => $numeroSerie]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$config) {
        AndroidApi::responder(['ok' => false, 'error' => 'Máquina no encontrada para ese número de serie'], 404);
    }

    SegmentoMaquina::requerirSiAplica($config);

    if ($confirmarActivacion) {
        // La misma serie puede activarse nuevamente sin autorización manual.
        // generarTokenApiMaquina() revoca el token anterior antes de emitir el nuevo.
        $nombreMaquina = trim((string) ($body['nombre_maquina'] ?? ''));
        $appVersionActivacion = trim((string) ($body['app_version'] ?? $body['version_name_actual'] ?? $body['version_name'] ?? '')) ?: null;
        $idSucursal = filter_var($body['id_sucursal_clinica'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $direccion = trim((string) ($body['direccion'] ?? '')) ?: null;
        $latitud = is_numeric($body['latitud'] ?? null) ? (float) $body['latitud'] : null;
        $longitud = is_numeric($body['longitud'] ?? null) ? (float) $body['longitud'] : null;
        if ($nombreMaquina === '') {
            AndroidApi::responder([
                'ok' => false,
                'error' => 'Para activar se requiere nombre_maquina',
            ], 422);
        }
        if (($latitud !== null && ($latitud < -90 || $latitud > 90))
            || ($longitud !== null && ($longitud < -180 || $longitud > 180))) {
            AndroidApi::responder(['ok' => false, 'error' => 'Coordenadas no válidas'], 422);
        }
        $sucursal = $idSucursal ? ActivacionMaquina::obtenerSucursalActiva($pdo, $idSucursal) : null;
        if ($idSucursal && !$sucursal) {
            AndroidApi::responder(['ok' => false, 'error' => 'La sucursal no existe o está inactiva'], 422);
        }
        if ($idSucursal) {
            TraspasoService::asegurarSucursalVirtual($pdo, $idSucursal);
            $stmtSucursalOcupada = $pdo->prepare(
                "SELECT TOP 1 id_maquina, numero_serie, nombre_maquina
                 FROM vendingm.maquinas
                 WHERE id_sucursal_clinica = :id_sucursal
                   AND id_maquina <> :id_maquina
                   AND numero_serie <> :numero_serie
                   AND ISNULL(activada, 0) = 1
                   AND ISNULL(id_estatus, 1) <> 3
                 ORDER BY fecha_ultima_activacion DESC, fecha_activacion DESC, id_maquina DESC"
            );
            $stmtSucursalOcupada->execute([
                'id_sucursal' => $idSucursal,
                'id_maquina' => (int) $config['id_maquina'],
                'numero_serie' => $numeroSerie,
            ]);
            $maquinaEnSucursal = $stmtSucursalOcupada->fetch(PDO::FETCH_ASSOC);
            if ($maquinaEnSucursal) {
                AndroidApi::responder([
                    'ok' => false,
                    'error' => 'La sucursal ya tiene una Máquina dispensadora activada con otro numero de serie.',
                    'data' => [
                        'codigo' => 'SUCURSAL_YA_ACTIVADA',
                        'id_maquina_existente' => (int) $maquinaEnSucursal['id_maquina'],
                        'numero_serie_existente' => (string) $maquinaEnSucursal['numero_serie'],
                        'nombre_maquina_existente' => $maquinaEnSucursal['nombre_maquina'] ?: null,
                    ],
                ], 409);
            }
        }
        $pdo->beginTransaction();
        $stmtActualizar = $pdo->prepare(
            "UPDATE vendingm.maquinas
             SET nombre_maquina = :nombre_maquina,
                 id_sucursal_clinica = :id_sucursal,
                 nombre_ubicacion = :nombre_ubicacion,
                 direccion = :direccion,
                 latitud = :latitud,
                 longitud = :longitud,
                 rol_maquina = 1,
                 id_maquina_padre = NULL,
                 activada = 1,
                 fecha_activacion = COALESCE(fecha_activacion, SYSDATETIME()),
                 fecha_ultima_activacion = SYSDATETIME(),
                 activada_por_app_version = :app_version_activacion,
                 permitir_reactivacion = 0,
                 id_tecnico_instalador = :id_tecnico_instalador,
                 tecnico_instalador_nombre = :tecnico_instalador_nombre,
                 segmento_operacion = 'cliente',
                 fecha_actualizacion = SYSDATETIME()
             WHERE id_maquina = :id_maquina"
        );
        $stmtActualizar->execute([
            'nombre_maquina' => $nombreMaquina,
            'id_sucursal' => $idSucursal,
            'nombre_ubicacion' => $sucursal['nombre'] ?? ($config['nombre_ubicacion'] ?: null),
            'direccion' => $direccion,
            'latitud' => $latitud,
            'longitud' => $longitud,
            'app_version_activacion' => $appVersionActivacion,
            'id_tecnico_instalador' => null,
            'tecnico_instalador_nombre' => null,
            'id_maquina' => (int) $config['id_maquina'],
        ]);
        $tokenMaquina = generarTokenApiMaquina($pdo, (int) $config['id_maquina']);
        if ($numeroSerieEsclava !== '') {
            asegurarEsclavaActivacion(
                $pdo,
                (int) $config['id_maquina'],
                $numeroSerieEsclava,
                $nombreMaquina,
                $sucursal['nombre'] ?? ($config['nombre_ubicacion'] ?: null),
                $direccion,
                $latitud,
                $longitud,
                $idSucursal,
                $config['tipo_maquina'] ?: null,
                $config['id_estatus'] !== null ? (int) $config['id_estatus'] : 1,
                SegmentoMaquina::CLIENTE
            );
        }
        registrarConexionUnidadActivada($pdo, (int) $config['id_maquina']);
        $pdo->commit();
        $config['nombre_maquina'] = $nombreMaquina;
        $config['id_sucursal_clinica'] = $idSucursal;
        $config['sucursal_nombre'] = $sucursal['nombre'] ?? null;
        $config['nombre_ubicacion'] = $sucursal['nombre'] ?? ($config['nombre_ubicacion'] ?: null);
        $config['direccion'] = $direccion;
        $config['latitud'] = $latitud;
        $config['longitud'] = $longitud;
        $config['rol_maquina'] = 1;
        $config['id_maquina_padre'] = null;
        $config['credenciales_api'] = $tokenMaquina;
        $config['activada'] = true;
        $config['fecha_ultima_activacion'] = date(DATE_ATOM);
        $config['activada_por_app_version'] = $appVersionActivacion;
        $config['permitir_reactivacion'] = false;
        $config['id_tecnico_instalador'] = null;
        $config['tecnico_instalador_nombre'] = null;
    }

    $config['id_maquina'] = (int) $config['id_maquina'];
    $config['rol_maquina'] = (int) $config['rol_maquina'];
    $config['rol'] = $config['rol_maquina'];
    $config['id_maquina_padre'] = $config['id_maquina_padre'] !== null ? (int) $config['id_maquina_padre'] : null;
    $ultimoNumeroCarril = $config['numero_carriles'] !== null ? (int) $config['numero_carriles'] : 0;
    $config['ultimo_numero_carril'] = $ultimoNumeroCarril;
    $config['numeros_carril'] = CarrilesFisicos::numerosHasta($ultimoNumeroCarril);
    $config['cantidad_carriles'] = count($config['numeros_carril']);
    $config['numero_carriles'] = $config['cantidad_carriles'];
    $config['nombre_maquina'] = trim((string) ($config['nombre_maquina'] ?? '')) ?: (string) $config['numero_serie'];
    $config['id_sucursal_clinica'] = $config['id_sucursal_clinica'] !== null ? (int) $config['id_sucursal_clinica'] : null;
    $config['sucursal'] = $config['id_sucursal_clinica'] !== null ? [
        'id_sucursal_clinica' => $config['id_sucursal_clinica'],
        'nombre' => $config['sucursal_nombre'] ?: $config['nombre_ubicacion'],
    ] : null;
    unset($config['sucursal_nombre']);
    $config['id_estatus'] = $config['id_estatus'] !== null ? (int) $config['id_estatus'] : null;
    $config['segmento_operacion'] = SegmentoMaquina::CLIENTE;
    $idUnidad = ($config['rol_maquina'] === 2 && $config['id_maquina_padre'] !== null)
        ? (int) $config['id_maquina_padre']
        : (int) $config['id_maquina'];

    $stmtUnidad = $pdo->prepare(
        "SELECT id_maquina, numero_serie, nombre_maquina, nombre_ubicacion, rol_maquina, id_maquina_padre, numero_carriles,
                segmento_operacion
         FROM vendingm.maquinas
         WHERE id_maquina = :id_unidad OR id_maquina_padre = :id_unidad_padre
         ORDER BY CASE WHEN rol_maquina = 1 THEN 0 ELSE 1 END, numero_serie"
    );
    $stmtUnidad->execute(['id_unidad' => $idUnidad, 'id_unidad_padre' => $idUnidad]);
    $config['id_unidad'] = $idUnidad;
    $config['maquinas_unidad'] = array_map(static function (array $m): array {
        return [
            'id_maquina' => (int) $m['id_maquina'],
            'numero_serie' => (string) $m['numero_serie'],
            'nombre_maquina' => trim((string) ($m['nombre_maquina'] ?? '')) ?: (string) $m['numero_serie'],
            'nombre_ubicacion' => $m['nombre_ubicacion'] ?: null,
            'rol_maquina' => (int) $m['rol_maquina'],
            'id_maquina_padre' => $m['id_maquina_padre'] !== null ? (int) $m['id_maquina_padre'] : null,
            'segmento_operacion' => SegmentoMaquina::CLIENTE,
            'numero_carriles' => CarrilesFisicos::cantidadHasta($m['numero_carriles'] !== null ? (int) $m['numero_carriles'] : 0),
            'ultimo_numero_carril' => $m['numero_carriles'] !== null ? (int) $m['numero_carriles'] : 0,
        ];
    }, $stmtUnidad->fetchAll(PDO::FETCH_ASSOC));
    $config['id_maquina_esclava'] = null;
    $config['numero_serie_esclava'] = null;
    foreach ($config['maquinas_unidad'] as $maquinaUnidad) {
        if ((int) $maquinaUnidad['rol_maquina'] === 2) {
            $config['id_maquina_esclava'] = (int) $maquinaUnidad['id_maquina'];
            $config['numero_serie_esclava'] = (string) $maquinaUnidad['numero_serie'];
            break;
        }
    }

    $stmtCarriles = $pdo->prepare(
        "SELECT m.id_maquina, m.numero_serie AS numero_serie_maquina, m.rol_maquina,
                ca.numero_carril, ca.id_producto, ca.cantidad_actual, p.nombre AS producto_nombre,
                p.codigo_barras, p.presentacion, p.descripcion, p.precio, p.imagen_path,
                l.numero_lote, l.fecha_caducidad
         FROM vendingm.carriles ca
         INNER JOIN vendingm.maquinas m ON m.id_maquina = ca.id_maquina
         LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
         LEFT JOIN vendingm.productos p ON p.id_producto = COALESCE(ca.id_producto, l.id_producto)
         WHERE (m.id_maquina = :id_unidad OR m.id_maquina_padre = :id_unidad_padre)
           AND ca.numero_carril % 10 <> 0
         ORDER BY CASE WHEN m.rol_maquina = 1 THEN 0 ELSE 1 END, m.numero_serie, ca.numero_carril"
    );
    $stmtCarriles->execute(['id_unidad' => $idUnidad, 'id_unidad_padre' => $idUnidad]);
    $config['carriles'] = array_map(static function (array $carril): array {
        $carril['id_maquina'] = (int) $carril['id_maquina'];
        $carril['rol_maquina'] = (int) $carril['rol_maquina'];
        $carril['numero_carril'] = (int) $carril['numero_carril'];
        $carril['clave_carril'] = $carril['id_maquina'] . ':' . $carril['numero_carril'];
        $carril['id_producto'] = $carril['id_producto'] !== null ? (int) $carril['id_producto'] : null;
        $carril['cantidad_actual'] = $carril['cantidad_actual'] !== null ? (int) $carril['cantidad_actual'] : 0;
        $carril['precio'] = $carril['precio'] !== null ? (float) $carril['precio'] : null;
        $carril['descripcion'] = $carril['descripcion'] ?: ($carril['presentacion'] ?: null);
        $carril['imagen_url'] = ProductoCatalogo::urlPublica($carril['imagen_path'] ?? null);
        unset($carril['imagen_path']);
        return $carril;
    }, $stmtCarriles->fetchAll(PDO::FETCH_ASSOC));

    $config['parametros'] = [
        'config_version' => 3,
        'fecha_servidor' => date(DATE_ATOM),
        'usar_lista_carriles' => true,
        'identificador_activacion' => 'numero_serie',
        'requiere_login_tecnico_activacion' => false,
        'segmento_operacion' => $config['segmento_operacion'],
        'base_api_sugerida' => 'android_clientes/',
        'endpoints' => endpointsClientes(),
    ];
    $config['panel_tecnico'] = [
        'modo_acceso' => 'login_tecnico',
        'endpoint_login' => 'android_tecnico_login.php',
        'endpoint_evento' => 'android_tecnico_evento.php',
    ];
    $config['branding'] = MaquinaBranding::obtenerParaAndroid($pdo, (int) $config['id_maquina']);

    // Contrato explicito de activacion usado por APK 2.5.11+. Se conservan
    // tambien los campos directos en data para compatibilidad con APK anteriores.
    $config['maquina'] = [
        'id_maquina' => (int) $config['id_maquina'],
        'numero_serie' => (string) $config['numero_serie'],
        'numero_serie_esclava' => $config['numero_serie_esclava'] ?: '',
        'nombre_maquina' => (string) ($config['nombre_maquina'] ?? ''),
        'direccion' => (string) ($config['direccion'] ?? ''),
    ];

    AndroidApi::responder(['ok' => true, 'data' => $config]);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    AndroidApi::responder(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], 500);
}

function responderActivacionError(PDO $pdo, array $payload, int $status): void
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    AndroidApi::responder($payload, $status);
}

function asegurarEsclavaActivacion(
    PDO $pdo,
    int $idMaquinaPrincipal,
    string $numeroSerieEsclava,
    string $nombreMaquina,
    ?string $nombreUbicacion,
    ?string $direccion,
    ?float $latitud,
    ?float $longitud,
    ?int $idSucursal,
    ?string $tipoMaquina,
    int $idEstatus,
    string $segmentoOperacion
): int {
    $numeroCarrilesEsclava = 58;
    $stmt = $pdo->prepare(
        'SELECT id_maquina, rol_maquina, id_maquina_padre
         FROM vendingm.maquinas WITH (UPDLOCK, HOLDLOCK)
         WHERE numero_serie = :serie'
    );
    $stmt->execute(['serie' => $numeroSerieEsclava]);
    $existente = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existente) {
        $idExistente = (int) $existente['id_maquina'];
        $rolExistente = (int) $existente['rol_maquina'];
        $idPadreExistente = $existente['id_maquina_padre'] !== null ? (int) $existente['id_maquina_padre'] : null;

        if ($idExistente === $idMaquinaPrincipal || $rolExistente === 1) {
            responderActivacionError($pdo, [
                'ok' => false,
                'error' => 'numero_serie_esclava ya pertenece a una maquina principal',
            ], 409);
        }
        if ($idPadreExistente !== null && $idPadreExistente !== $idMaquinaPrincipal) {
            responderActivacionError($pdo, [
                'ok' => false,
                'error' => 'numero_serie_esclava ya esta vinculada a otra maquina principal',
            ], 409);
        }

        $upd = $pdo->prepare(
            'UPDATE vendingm.maquinas
             SET nombre_maquina = :nombre,
                 nombre_ubicacion = :ubicacion,
                 direccion = :direccion,
                 latitud = :latitud,
                 longitud = :longitud,
                 numero_carriles = :carriles,
                 id_estatus = :estatus,
                 id_maquina_padre = :padre,
                 rol_maquina = 2,
                 tipo_maquina = :tipo,
                 id_sucursal_clinica = :sucursal,
                 segmento_operacion = :segmento_operacion,
                 fecha_actualizacion = SYSDATETIME()
             WHERE id_maquina = :id'
        );
        $upd->execute([
            'nombre' => $nombreMaquina,
            'ubicacion' => $nombreUbicacion,
            'direccion' => $direccion,
            'latitud' => $latitud,
            'longitud' => $longitud,
            'carriles' => $numeroCarrilesEsclava,
            'estatus' => $idEstatus,
            'padre' => $idMaquinaPrincipal,
            'tipo' => $tipoMaquina,
            'sucursal' => $idSucursal,
            'segmento_operacion' => SegmentoMaquina::normalizar($segmentoOperacion),
            'id' => $idExistente,
        ]);
        CarrilesFisicos::crearFaltantes($pdo, $idExistente, $numeroCarrilesEsclava);
        return $idExistente;
    }

    $codigoInterno = generarCodigoInternoEsclava($pdo, $numeroSerieEsclava);
    $ins = $pdo->prepare(
        'INSERT INTO vendingm.maquinas
         (numero_serie, nombre_maquina, codigo_interno, nombre_ubicacion, direccion, latitud, longitud,
          numero_carriles, id_estatus, id_maquina_padre, rol_maquina, tipo_maquina, id_sucursal_clinica, segmento_operacion)
         OUTPUT INSERTED.id_maquina
         VALUES (:serie, :nombre, :codigo, :ubicacion, :direccion, :latitud, :longitud,
                 :carriles, :estatus, :padre, 2, :tipo, :sucursal, :segmento_operacion)'
    );
    $ins->execute([
        'serie' => $numeroSerieEsclava,
        'nombre' => $nombreMaquina,
        'codigo' => $codigoInterno,
        'ubicacion' => $nombreUbicacion,
        'direccion' => $direccion,
        'latitud' => $latitud,
        'longitud' => $longitud,
        'carriles' => $numeroCarrilesEsclava,
        'estatus' => $idEstatus,
        'padre' => $idMaquinaPrincipal,
        'tipo' => $tipoMaquina,
        'sucursal' => $idSucursal,
        'segmento_operacion' => SegmentoMaquina::normalizar($segmentoOperacion),
    ]);
    $idEsclava = (int) $ins->fetchColumn();
    CarrilesFisicos::crearFaltantes($pdo, $idEsclava, $numeroCarrilesEsclava);
    return $idEsclava;
}

function generarCodigoInternoEsclava(PDO $pdo, string $numeroSerieEsclava): string
{
    $base = strtoupper((string) preg_replace('/[^A-Z0-9]+/i', '', $numeroSerieEsclava));
    $base = substr($base !== '' ? $base : 'ESCLAVA', 0, 24);
    $codigo = 'ESC-' . $base;
    $sufijo = 1;
    $stmt = $pdo->prepare('SELECT 1 FROM vendingm.maquinas WHERE codigo_interno = :codigo');

    while (true) {
        $stmt->execute(['codigo' => $codigo]);
        if (!$stmt->fetchColumn()) {
            return $codigo;
        }
        $sufijo++;
        $codigo = 'ESC-' . substr($base, 0, 20) . '-' . $sufijo;
    }
}

function registrarConexionUnidadActivada(PDO $pdo, int $idMaquinaPrincipal): void
{
    $stmt = $pdo->prepare(
        "UPDATE vendingm.maquinas
         SET ultima_conexion = SYSDATETIME(),
             fecha_actualizacion = SYSDATETIME()
         WHERE id_maquina = :id
            OR id_maquina_padre = :id_padre"
    );
    $stmt->execute([
        'id' => $idMaquinaPrincipal,
        'id_padre' => $idMaquinaPrincipal,
    ]);
}

function endpointsClientes(): array
{
    $base = 'android_clientes/';

    $endpoints = [
        'config' => $base . 'config.php',
        'estado_sistema' => $base . 'estado_sistema.php',
        'telemetria' => $base . 'telemetria.php',
        'actualizacion_consultar' => $base . 'actualizacion_consultar.php',
        'actualizacion_evento' => $base . 'actualizacion_evento.php',
        'comando_resultado' => $base . 'comando_resultado.php',
        'dispensacion' => $base . 'dispensacion.php',
        'error_surtido' => $base . 'error_surtido.php',
    ];

    $endpoints['receta_consultar'] = $base . 'receta_consultar.php';
    $endpoints['receta_iniciar'] = $base . 'receta_iniciar.php';
    $endpoints['receta_finalizar'] = $base . 'receta_finalizar.php';
    $endpoints['carrito_catalogo'] = $base . 'carrito_catalogo.php';
    $endpoints['carrito_crear_receta'] = $base . 'carrito_crear_receta.php';

    return $endpoints;
}

function generarTokenApiMaquina(PDO $pdo, int $idMaquina): array
{
    $pdo->prepare(
        "UPDATE vendingm.api_tokens_maquina
         SET revocado = 1, fecha_revocacion = SYSDATETIME()
         WHERE id_maquina = :id AND revocado = 0"
    )->execute(['id' => $idMaquina]);

    $token = 'MLVENDING_' . bin2hex(random_bytes(24));
    $hash = password_hash($token, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        "INSERT INTO vendingm.api_tokens_maquina (id_maquina, token_hash)
         OUTPUT INSERTED.id_token
         VALUES (:id_maquina, :token_hash)"
    );
    $stmt->execute(['id_maquina' => $idMaquina, 'token_hash' => $hash]);

    return [
        'id_token' => (int) $stmt->fetchColumn(),
        'token' => $token,
        'token_tipo' => 'maquina',
        'mostrar_una_sola_vez' => true,
    ];
}
