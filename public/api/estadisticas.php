<?php
// public/api/estadisticas.php
// Estadísticas de salidas de vending (ventas + mermas: caducado/dañado/merma),
// 100% dentro de vendingm.movimientos_salida -- ya no depende de
// cat_SucursalClinica/tbl_ProductoSalida/farmacia. "Traspasos" (sucursal real
// -> máquina) queda fuera de alcance: eso lo sigue registrando farmacia por
// su cuenta (ver decisión del 2026-08-24 en la memoria del proyecto);
// vendingm solo trackea qué salió de cada máquina y por qué.
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Auth\Auth;
use App\Http\JsonResponse;
use App\Security\Security;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

function esFechaValida(?string $fecha): bool
{
    return $fecha !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1;
}

// Resuelve una "unidad" (maestra + sus esclavas) a la lista plana de
// id_maquina que la componen, sin importar si $idMaquina es la maestra o
// una de sus esclavas -- primero normaliza al id de la maestra (igual que
// App\Vending\* en el resto del sistema: rol_maquina=2 con id_maquina_padre
// siempre resuelve a ese padre) y luego junta todas las esclavas de esa
// maestra. Antes solo buscaba hacia abajo (esclavas de $idMaquina), asi que
// si se pasaba el id de una esclava, nunca se alcanzaba a la maestra donde
// realmente quedan registradas las recetas (vendingm.recetas.id_maquina).
function idsUnidadMaquina(PDO $pdo, int $idMaquina): array
{
    $stmtMaquina = $pdo->prepare('SELECT rol_maquina, id_maquina_padre FROM vendingm.maquinas WHERE id_maquina = :id');
    $stmtMaquina->execute(['id' => $idMaquina]);
    $maquina = $stmtMaquina->fetch(PDO::FETCH_ASSOC);

    $idMaestra = ($maquina && (int) $maquina['rol_maquina'] === 2 && $maquina['id_maquina_padre'])
        ? (int) $maquina['id_maquina_padre']
        : $idMaquina;

    $ids = [$idMaestra];
    $stmt = $pdo->prepare('SELECT id_maquina FROM vendingm.maquinas WHERE id_maquina_padre = :id');
    $stmt->execute(['id' => $idMaestra]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $idEsclava) {
        $ids[] = (int) $idEsclava;
    }
    return $ids;
}

/** @return array{0: string, 1: array<string, mixed>} [sqlFiltros, params] */
function construirFiltros(PDO $pdo): array
{
    $sql = '';
    $params = [];

    $fechaDesde = $_GET['fechaDesde'] ?? null;
    if (esFechaValida($fechaDesde)) {
        $sql .= ' AND ms.fecha_movimiento >= :fechaDesde';
        $params['fechaDesde'] = $fechaDesde . ' 00:00:00';
    }

    $fechaHasta = $_GET['fechaHasta'] ?? null;
    if (esFechaValida($fechaHasta)) {
        $sql .= ' AND ms.fecha_movimiento <= :fechaHasta';
        $params['fechaHasta'] = $fechaHasta . ' 23:59:59';
    }

    $idMaquina = $_GET['idMaquina'] ?? null;
    if (is_numeric($idMaquina)) {
        // El filtro de máquina es por unidad (maestra + sus esclavas), no solo por
        // id_maquina exacto -- una esclava es una extensión física de su maestra,
        // no una unidad reportable aparte. El selector solo ofrece maestras (ver
        // accion=maquinas), así que :idMaquina aquí siempre es una maestra.
        $idsUnidad = idsUnidadMaquina($pdo, (int) $idMaquina);
        $marcadores = [];
        foreach ($idsUnidad as $i => $id) {
            $marcadores[] = ":idMaquina{$i}";
            $params["idMaquina{$i}"] = $id;
        }
        $sql .= ' AND ms.id_maquina IN (' . implode(',', $marcadores) . ')';
    }

    $idTipoMovimiento = $_GET['idTipoMovimiento'] ?? null;
    if (is_numeric($idTipoMovimiento)) {
        $sql .= ' AND ms.id_tipo_movimiento = :idTipoMovimiento';
        $params['idTipoMovimiento'] = (int) $idTipoMovimiento;
    }

    return [$sql, $params];
}

try {
    $pdo = Connection::get();
    $accion = (string) ($_GET['accion'] ?? '');

    if ($accion === 'maquinas') {
        // Solo maestras (rol_maquina = 1): las esclavas son extensiones fisicas de
        // su maestra, no unidades reportables aparte -- ver construirFiltros().
        $stmt = $pdo->query(
            'SELECT id_maquina, numero_serie, nombre_ubicacion
             FROM vendingm.maquinas
             WHERE rol_maquina = 1
             ORDER BY numero_serie'
        );
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($accion === 'tipos_movimiento') {
        $stmt = $pdo->query('SELECT id_tipo_movimiento, nombre FROM vendingm.tipos_movimiento WHERE es_salida = 1 ORDER BY id_tipo_movimiento');
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($accion === 'kardex') {
        [$filtrosSql, $params] = construirFiltros($pdo);
        $stmt = $pdo->prepare(
            "SELECT TOP 500
                ms.id_movimiento, ms.fecha_movimiento, ms.cantidad, ms.usuario, ms.observaciones,
                ms.id_tipo_movimiento, tm.nombre AS tipo_nombre,
                p.nombre AS producto_nombre, p.codigo_barras, p.clave,
                m.numero_serie, m.nombre_ubicacion, ca.numero_carril
             FROM vendingm.movimientos_salida ms
             INNER JOIN vendingm.maquinas m ON m.id_maquina = ms.id_maquina
             INNER JOIN vendingm.carriles ca ON ca.id_carril = ms.id_carril
             LEFT JOIN vendingm.productos p ON p.id_producto = ms.id_producto
             LEFT JOIN vendingm.tipos_movimiento tm ON tm.id_tipo_movimiento = ms.id_tipo_movimiento
             WHERE 1 = 1{$filtrosSql}
             ORDER BY ms.fecha_movimiento DESC"
        );
        $stmt->execute($params);

        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($accion === 'exportar') {
        [$filtrosSql, $params] = construirFiltros($pdo);
        $stmt = $pdo->prepare(
            "SELECT
                ms.fecha_movimiento,
                tm.nombre AS tipo_movimiento,
                p.codigo_barras,
                p.clave,
                p.nombre AS producto,
                ms.cantidad,
                m.numero_serie,
                m.nombre_ubicacion,
                ca.numero_carril,
                ms.usuario,
                ms.observaciones
             FROM vendingm.movimientos_salida ms
             INNER JOIN vendingm.maquinas m ON m.id_maquina = ms.id_maquina
             INNER JOIN vendingm.carriles ca ON ca.id_carril = ms.id_carril
             LEFT JOIN vendingm.productos p ON p.id_producto = ms.id_producto
             LEFT JOIN vendingm.tipos_movimiento tm ON tm.id_tipo_movimiento = ms.id_tipo_movimiento
             WHERE 1 = 1{$filtrosSql}
             ORDER BY ms.fecha_movimiento DESC"
        );
        $stmt->execute($params);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="estadisticas-maquina-dispensadora-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['fecha', 'tipo', 'codigo_barras', 'clave', 'producto', 'cantidad', 'maquina', 'ubicacion', 'carril', 'usuario', 'observaciones']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            fputcsv($out, $fila);
        }
        exit;
    }

    if ($accion === 'recetas_surtidas') {
        // Recetas realmente entregadas (fecha_entregada solo se marca cuando
        // total_entregado >= total_solicitado, ver android_receta_finalizar.php)
        // -- no incluye parciales. El filtro de maquina cubre tanto la maquina
        // que recibio la receta (r.id_maquina) como cualquier maquina que haya
        // dispensado al menos un medicamento de ella (dr.id_maquina, que puede
        // diferir por item cuando la receta abarca varias unidades).
        $sql = '';
        $params = [];

        $fechaDesde = $_GET['fechaDesde'] ?? null;
        if (esFechaValida($fechaDesde)) {
            $sql .= ' AND r.fecha_entregada >= :fechaDesde';
            $params['fechaDesde'] = $fechaDesde . ' 00:00:00';
        }

        $fechaHasta = $_GET['fechaHasta'] ?? null;
        if (esFechaValida($fechaHasta)) {
            $sql .= ' AND r.fecha_entregada <= :fechaHasta';
            $params['fechaHasta'] = $fechaHasta . ' 23:59:59';
        }

        $idMaquina = $_GET['idMaquina'] ?? null;
        if (is_numeric($idMaquina)) {
            // Igual que en construirFiltros(): filtra por unidad (maestra + sus
            // esclavas), no solo por id_maquina exacto.
            $idsUnidad = idsUnidadMaquina($pdo, (int) $idMaquina);
            $marcadoresR = [];
            $marcadoresDr = [];
            foreach ($idsUnidad as $i => $id) {
                $marcadoresR[] = ":idMaqR{$i}";
                $params["idMaqR{$i}"] = $id;
                $marcadoresDr[] = ":idMaqDr{$i}";
                $params["idMaqDr{$i}"] = $id;
            }
            $sql .= ' AND (
                r.id_maquina IN (' . implode(',', $marcadoresR) . ')
                OR EXISTS (
                    SELECT 1 FROM vendingm.detalle_receta dx
                    WHERE dx.id_receta = r.id_receta
                      AND dx.id_maquina IN (' . implode(',', $marcadoresDr) . ')
                )
            )';
        }

        $stmt = $pdo->prepare(
            "SELECT TOP 500
                r.id_receta, r.folio, r.nombre_paciente, r.fecha_entregada,
                r.id_maquina, m.numero_serie, m.nombre_ubicacion,
                (SELECT COUNT(*) FROM vendingm.detalle_receta dc WHERE dc.id_receta = r.id_receta) AS num_medicamentos
             FROM vendingm.recetas r
             INNER JOIN vendingm.maquinas m ON m.id_maquina = r.id_maquina
             WHERE r.fecha_entregada IS NOT NULL{$sql}
             ORDER BY r.fecha_entregada DESC"
        );
        $stmt->execute($params);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($accion === 'resumen') {
        [$filtrosSql, $params] = construirFiltros($pdo);

        // Venta (motivo 1) vs merma (caducado/dañado/merma: 2/3/5) -- mismas
        // constantes que App\Dispensacion\SalidaRegistrador.
        $stmtTotales = $pdo->prepare(
            "SELECT
                SUM(CASE WHEN ms.id_tipo_movimiento = 1 THEN 1 ELSE 0 END) AS ventasMovimientos,
                SUM(CASE WHEN ms.id_tipo_movimiento = 1 THEN ms.cantidad ELSE 0 END) AS ventasPiezas,
                SUM(CASE WHEN ms.id_tipo_movimiento IN (2,3,5) THEN 1 ELSE 0 END) AS mermaMovimientos,
                SUM(CASE WHEN ms.id_tipo_movimiento IN (2,3,5) THEN ms.cantidad ELSE 0 END) AS mermaPiezas
             FROM vendingm.movimientos_salida ms
             WHERE 1 = 1{$filtrosSql}"
        );
        $stmtTotales->execute($params);
        $t = $stmtTotales->fetch(PDO::FETCH_ASSOC) ?: [];
        $totales = [
            'ventasMovimientos' => (int) ($t['ventasMovimientos'] ?? 0),
            'ventasPiezas' => (float) ($t['ventasPiezas'] ?? 0),
            'mermaMovimientos' => (int) ($t['mermaMovimientos'] ?? 0),
            'mermaPiezas' => (float) ($t['mermaPiezas'] ?? 0),
        ];

        $stmtPorDia = $pdo->prepare(
            "SELECT CONVERT(VARCHAR, ms.fecha_movimiento, 23) AS dia,
                    SUM(CASE WHEN ms.id_tipo_movimiento = 1 THEN ms.cantidad ELSE 0 END) AS ventas,
                    SUM(CASE WHEN ms.id_tipo_movimiento IN (2,3,5) THEN ms.cantidad ELSE 0 END) AS merma
             FROM vendingm.movimientos_salida ms
             WHERE 1 = 1{$filtrosSql}
             GROUP BY CONVERT(VARCHAR, ms.fecha_movimiento, 23)
             ORDER BY dia ASC"
        );
        $stmtPorDia->execute($params);
        $porDia = array_map(static function (array $row): array {
            return ['dia' => $row['dia'], 'ventas' => (float) $row['ventas'], 'merma' => (float) $row['merma']];
        }, $stmtPorDia->fetchAll(PDO::FETCH_ASSOC));

        $stmtTop = $pdo->prepare(
            "SELECT TOP 8 p.nombre, SUM(ms.cantidad) AS piezas
             FROM vendingm.movimientos_salida ms
             INNER JOIN vendingm.productos p ON p.id_producto = ms.id_producto
             WHERE ms.id_tipo_movimiento = 1{$filtrosSql}
             GROUP BY p.nombre
             ORDER BY piezas DESC"
        );
        $stmtTop->execute($params);
        $topProductos = array_map(static function (array $row): array {
            return ['nombre' => $row['nombre'], 'piezas' => (float) $row['piezas']];
        }, $stmtTop->fetchAll(PDO::FETCH_ASSOC));

        echo json_encode([
            'ok' => true,
            'data' => [
                'totales' => $totales,
                'porDia' => $porDia,
                'topProductos' => $topProductos,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Acción no válida'], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    Security::logException($e, 'estadisticas');
    JsonResponse::error('No se pudieron calcular las estadísticas.', 500);
}
