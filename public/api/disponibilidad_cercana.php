<?php
// public/api/disponibilidad_cercana.php
// Paso 2 del flujo manual de "Resurtir" (ver public/resurtir.php): para una
// máquina de vendingm, 1) lista los medicamentos con stock por debajo de un
// umbral (% de capacidad del carril) y 2) para uno de ellos, busca
// sucursales reales de farmacia -- o CEDIS -- cercanas (mismo estado y,
// idealmente, mismo municipio) que SÍ tengan existencia disponible. No
// genera ningún movimiento ni pedido -- es solo una ayuda operativa para
// que el admin sepa a dónde ir/llamar a surtir a mano. El flujo automático
// de "Solicitudes de pedido" -> "Pedido a CEDIS" (vendingm.solicitudes_pedido,
// pedido_cedis.php) sigue siendo el camino formal, sin cambios.
//
// OJO -- hallazgo no obvio del esquema real (confirmado 2026-09-01 contra
// varios reportes de system/Cedis/*, ej. PDFReporteTraspaso.php): en
// cat_SucursalClinica los nombres de columna de ubicación están invertidos
// respecto a lo que en realidad referencian:
//   - id_Ciudad   -> hace join contra cat_Estados.id_Estado    (es el ESTADO)
//   - id_estado   -> hace join contra cat_Municipios.id_Municipio (es el MUNICIPIO)
// Se respeta ese mismo patrón aquí para no introducir un bug nuevo.
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;
use App\Vending\SolicitudPedidoGenerador;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

// Sucursal única de resguardo de caducados en toda la empresa (confirmado
// con el usuario 2026-09-01) -- nunca es una fuente real de existencia
// disponible, aunque view_CEDStockSucursalCaducidad la reporte con stock.
const ID_SUCURSAL_CADUCOS = 9;

// Caso especial confirmado por el usuario 2026-09-01: la sucursal 87
// (Corporativo Xochimilco) no registra su existencia en
// view_CEDStockSucursalCaducidad (la vista que usa el resto de sucursales
// tipo 6) -- su stock real vive en view_CEDStockCedisCaducidad, una vista
// SIN columna id_Sucursal (es consulta directa -- ese CEDIS es implícito,
// no distingue sucursal). El resto de sucursales tipo 6 (Xalostoc, Zirahuen,
// Mexfigas, RPBI, Coyoacan, etc.) siguen usando la vista normal.
const ID_SUCURSAL_CEDIS_XOCHIMILCO = 87;

try {
    $pdo = Connection::get();
    $accion = (string) ($_GET['accion'] ?? '');

    if ($accion === 'bajo_stock') {
        responderBajoStock($pdo);
    } elseif ($accion === 'cercanas') {
        responderCercanas($pdo);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Accion no valida'], JSON_UNESCAPED_UNICODE);
    }
} catch (\Throwable $e) {
    JsonResponse::errorFromException($e);
}

function responderBajoStock(PDO $pdo): void
{
    $idMaquina = filter_input(INPUT_GET, 'id_maquina', FILTER_VALIDATE_INT);
    if (!$idMaquina) {
        throw new \InvalidArgumentException('Falta id_maquina');
    }
    if (!Auth::canAccessMachine($pdo, $idMaquina)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No tienes acceso a esta máquina'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Mismo criterio de "% de capacidad del carril" que ya usa
    // api/alertas_operacion.php -- capacidad_carril es un estimado (GET,
    // default 10) porque vendingm.carriles.capacidad_maxima no está
    // poblada hoy por ningún flujo.
    $capacidadCarril = max(1, filter_input(INPUT_GET, 'capacidad_carril', FILTER_VALIDATE_INT) ?: 10);
    $umbralPorcentaje = max(1, min(100, filter_input(INPUT_GET, 'umbral_porcentaje', FILTER_VALIDATE_INT) ?: 50));

    SolicitudPedidoGenerador::asegurarTablas($pdo);

    $stmt = $pdo->prepare(
        "SELECT
            ca.numero_carril,
            ca.cantidad_actual,
            -- Faltante estimado contra la capacidad de referencia (mismo
            -- :capacidad usado para el % -- no es la capacidad real del
            -- carril, que no está poblada hoy, ver capacidad_maxima).
            (:capacidad3 - ca.cantidad_actual) AS cantidad_faltante,
            l.numero_lote, l.fecha_caducidad,
            p.id_producto,
            p.nombre,
            p.sustancia_activa,
            p.codigo_barras,
            p.clave,
            CAST((ca.cantidad_actual * 100.0 / :capacidad) AS DECIMAL(6,2)) AS porcentaje_capacidad,
            sol.id_solicitud, sol.fecha_creacion AS solicitud_fecha
         FROM vendingm.carriles ca
         INNER JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
         INNER JOIN vendingm.productos p ON p.id_producto = l.id_producto
         OUTER APPLY (
             -- Solicitud ya enviada a CEDIS (con pedido real ligado) que
             -- sigue pendiente de recepcion para este carril -- se ofrece
             -- ligarla al confirmar el traspaso, igual que en
             -- detalle_maquina.php.
             SELECT TOP 1 sp.id_solicitud, sp.fecha_creacion
             FROM vendingm.solicitudes_pedido sp
             WHERE sp.id_carril = ca.id_carril
               AND sp.id_estatus = :estatus_enviado
               AND sp.id_pedido_cedis1 IS NOT NULL
             ORDER BY sp.fecha_creacion DESC
         ) sol
         WHERE ca.id_maquina = :id_maquina
           AND ca.numero_carril % 10 <> 0
           AND ca.cantidad_actual > 0
           AND (ca.cantidad_actual * 100.0 / :capacidad2) <= :umbral
         ORDER BY porcentaje_capacidad ASC, p.nombre ASC"
    );
    $stmt->execute([
        'capacidad' => $capacidadCarril,
        'capacidad2' => $capacidadCarril,
        'capacidad3' => $capacidadCarril,
        'id_maquina' => $idMaquina,
        'umbral' => $umbralPorcentaje,
        'estatus_enviado' => SolicitudPedidoGenerador::ESTATUS_ENVIADO,
    ]);

    $filas = array_map(static function (array $fila): array {
        $fila['id_solicitud'] = $fila['id_solicitud'] !== null ? (int) $fila['id_solicitud'] : null;
        $fila['cantidad_faltante'] = max(0, (int) $fila['cantidad_faltante']);
        return $fila;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
        'ok' => true,
        'data' => $filas,
        'meta' => ['capacidad_carril' => $capacidadCarril, 'umbral_porcentaje' => $umbralPorcentaje],
    ], JSON_UNESCAPED_UNICODE);
}

function responderCercanas(PDO $pdo): void
{
    $idMaquina = filter_input(INPUT_GET, 'id_maquina', FILTER_VALIDATE_INT);
    $codigoBarras = trim((string) ($_GET['codigo_barras'] ?? ''));
    if (!$idMaquina || $codigoBarras === '') {
        throw new \InvalidArgumentException('Falta id_maquina o codigo_barras');
    }
    if (!Auth::canAccessMachine($pdo, $idMaquina)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No tienes acceso a esta máquina'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Preferimos el estado/municipio REAL de la sucursal ya vinculada a la
    // máquina (vendingm.maquinas.id_sucursal_clinica -- toda máquina vive
    // anclada a una, ver decisión de arquitectura 2026-08-24): comparar
    // id_Ciudad/id_estado numérico contra el catálogo es exacto, a
    // diferencia del texto libre estado_geo/municipio de Activación v3
    // (puede faltar o venir escrito distinto al catálogo). Si la máquina no
    // tiene sucursal vinculada, o esa sucursal no tiene id_Ciudad/id_estado
    // capturados, caemos al texto libre como respaldo. Confirmado con el
    // usuario 2026-09-01.
    $stmtM = $pdo->prepare(
        'SELECT m.estado_geo, m.municipio, suc.id_Ciudad AS id_estado_suc, suc.id_estado AS id_municipio_suc
         FROM vendingm.maquinas m
         LEFT JOIN cat_SucursalClinica suc ON suc.id_SucursalClinica = m.id_sucursal_clinica
         WHERE m.id_maquina = :id'
    );
    $stmtM->execute(['id' => $idMaquina]);
    $maquina = $stmtM->fetch(PDO::FETCH_ASSOC);
    if (!$maquina) {
        throw new \RuntimeException('Máquina no encontrada');
    }
    $idEstadoSuc = $maquina['id_estado_suc'] !== null ? (int) $maquina['id_estado_suc'] : null;
    $idMunicipioSuc = $maquina['id_municipio_suc'] !== null ? (int) $maquina['id_municipio_suc'] : null;
    $estadoGeo = trim((string) ($maquina['estado_geo'] ?? ''));
    $municipio = trim((string) ($maquina['municipio'] ?? ''));

    if ($idEstadoSuc === null && $estadoGeo === '') {
        echo json_encode([
            'ok' => true,
            'data' => [],
            'meta' => ['aviso' => 'No se pudo determinar el estado de esta máquina (ni por su sucursal vinculada ni por texto capturado) -- vincula una sucursal o edita Estado/Municipio en Máquinas.'],
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $stmtProd = $pdo->prepare('SELECT id_ProductoAlmacen FROM cat_ProductosAlmacenMaster WHERE id_UPC = :upc AND i_Activo = 1');
    $stmtProd->execute(['upc' => $codigoBarras]);
    $idProductoAlmacen = $stmtProd->fetchColumn();
    if (!$idProductoAlmacen) {
        echo json_encode([
            'ok' => true,
            'data' => [],
            'meta' => ['aviso' => 'Este producto no tiene equivalente en el catálogo real de farmacia (cat_ProductosAlmacenMaster) -- no se puede buscar existencia.'],
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // Tipos de operación elegibles, corregidos dos veces el mismo día por el
    // usuario contra cat_TipoSucursalOperacion -- versión final: únicamente
    // 6 = Administrativo cuenta como "almacén" para este listado. Excluidos:
    // 1 = Venta, 2 = Cedis Cerebro, 3 = Cedis Subrogado, 4 = Cedis Satélite.
    //
    // id_SucursalOrigenVending IS NULL: excluye las sucursales VIRTUALES
    // ("VENDING - ...") que TraspasoService::asegurarSucursalVirtual() crea
    // automáticamente al vincular una máquina -- mismo filtro que ya usa
    // api/sucursales_clinica.php. Sin esto, una máquina podía "encontrarse"
    // a sí misma (o a otra) como si fuera un almacén real con existencia
    // (bug encontrado en vivo 2026-09-01: aparecía "VENDING - Vending
    // Coyoacan" como resultado).
    //
    // view_CEDStockSucursalCaducidad SÍ incluye existencia sentada en la
    // sucursal de resguardo de caducados ("CADUCOS", id_SucursalClinica=9,
    // única en toda la empresa -- confirmado por el usuario 2026-09-01):
    // probando con ADOPREN, era el ÚNICO resultado que aparecía, porque las
    // sucursales reales no tenían existencia vendible de ese producto. Se
    // excluye por ID fijo, mismo patrón que ID_OPERADOR_VENDING=9999 en
    // pedido_cedis.php/vencimientos.php.
    // Condición de estado: exacta por ID si la máquina tiene sucursal
    // vinculada con id_Ciudad capturado; si no, texto libre como respaldo.
    if ($idEstadoSuc !== null) {
        $condicionEstado = 'suc.id_Ciudad = :id_estado_suc';
    } else {
        $condicionEstado = "(est.st_Estado LIKE '%' + :estado_geo1 + '%' OR :estado_geo2 LIKE '%' + est.st_Estado + '%')";
    }
    // "Mismo municipio" para desempate de orden: exacto por ID si se pudo
    // resolver, si no, texto libre.
    if ($idMunicipioSuc !== null) {
        $condicionMunicipio = 'CASE WHEN suc.id_estado = :id_municipio_suc THEN 1 ELSE 0 END';
    } else {
        $condicionMunicipio = "CASE WHEN :municipio1 <> '' AND mun.st_Municipio LIKE '%' + :municipio2 + '%' THEN 1 ELSE 0 END";
    }

    $stmt = $pdo->prepare(
        "SELECT
            suc.id_SucursalClinica,
            suc.st_Nombre,
            suc.st_Domicilio,
            suc.st_Telefonos,
            suc.id_TipoSucursalOperacion,
            'Administrativo' AS tipo_nombre,
            1 AS tipo_prioridad,
            est.st_Estado AS estado_nombre,
            mun.st_Municipio AS municipio_nombre,
            {$condicionMunicipio} AS mismo_municipio,
            disp.disponibles
         FROM cat_SucursalClinica suc
         LEFT JOIN cat_Estados est ON suc.id_Ciudad = est.id_Estado
         LEFT JOIN cat_Municipios mun ON suc.id_estado = mun.id_Municipio
         INNER JOIN (
            SELECT id_Sucursal, SUM(Stock) AS disponibles
            FROM view_CEDStockSucursalCaducidad
            WHERE id_ProductoAlmacen = :id_producto_almacen1
              AND id_Sucursal <> " . ID_SUCURSAL_CEDIS_XOCHIMILCO . "
            GROUP BY id_Sucursal
            UNION ALL
            SELECT " . ID_SUCURSAL_CEDIS_XOCHIMILCO . " AS id_Sucursal, SUM(Stock) AS disponibles
            FROM view_CEDStockCedisCaducidad
            WHERE id_ProductoAlmacen = :id_producto_almacen2
         ) disp ON disp.id_Sucursal = suc.id_SucursalClinica
         WHERE suc.i_Activo = 1
           AND suc.id_TipoSucursalOperacion = 6
           AND suc.id_SucursalOrigenVending IS NULL
           AND suc.id_SucursalClinica <> " . ID_SUCURSAL_CADUCOS . "
           AND disp.disponibles > 0
           AND {$condicionEstado}
         ORDER BY tipo_prioridad ASC, mismo_municipio DESC, disp.disponibles DESC"
    );

    $params = [
        'id_producto_almacen1' => $idProductoAlmacen,
        'id_producto_almacen2' => $idProductoAlmacen,
    ];
    if ($idEstadoSuc !== null) {
        $params['id_estado_suc'] = $idEstadoSuc;
    } else {
        $params['estado_geo1'] = $estadoGeo;
        $params['estado_geo2'] = $estadoGeo;
    }
    if ($idMunicipioSuc !== null) {
        $params['id_municipio_suc'] = $idMunicipioSuc;
    } else {
        $params['municipio1'] = $municipio;
        $params['municipio2'] = $municipio;
    }
    $stmt->execute($params);

    echo json_encode([
        'ok' => true,
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'meta' => [
            'estado_geo' => $estadoGeo,
            'municipio' => $municipio,
            'resuelto_por' => $idEstadoSuc !== null ? 'sucursal_vinculada' : 'texto_activacion_v3',
        ],
    ], JSON_UNESCAPED_UNICODE);
}
