<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Dispensacion\SalidaRegistrador;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$metodo = $_SERVER['REQUEST_METHOD'];
$accion = (string) ($_GET['accion'] ?? 'ultimo');

try {
    $pdo = Connection::get();
    SalidaRegistrador::asegurarTablas($pdo);
    asegurarTablasPlaneacion($pdo);

    if ($metodo === 'GET') {
        if ($accion === 'ultimo') {
            responderUltimoPlan($pdo);
        } elseif ($accion === 'detalle') {
            responderDetalle($pdo);
        } elseif ($accion === 'resumen') {
            responderResumen($pdo);
        } elseif ($accion === 'rotacion') {
            responderRotacion($pdo);
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
        if ($accion === 'generar') {
            generarPlan($pdo, $body);
        } elseif ($accion === 'estatus') {
            cambiarEstatus($pdo, $body);
        } else {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Accion no valida'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido'], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], JSON_UNESCAPED_UNICODE);
}

function asegurarTablasPlaneacion(PDO $pdo): void
{
    $pdo->exec("
        IF OBJECT_ID('vendingm.planeacion_corridas', 'U') IS NULL
        BEGIN
            CREATE TABLE vendingm.planeacion_corridas (
                id_plan INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                fecha_plan DATE NOT NULL,
                horizonte_dias INT NOT NULL,
                stock_seguridad_dias INT NOT NULL,
                dias_historial INT NOT NULL,
                estado NVARCHAR(20) NOT NULL CONSTRAINT DF_plan_estado DEFAULT 'generado',
                generado_por NVARCHAR(150) NULL,
                fecha_generacion DATETIME2 NOT NULL CONSTRAINT DF_plan_generacion DEFAULT SYSDATETIME(),
                notas NVARCHAR(300) NULL
            );
        END
    ");

    $pdo->exec("
        IF OBJECT_ID('vendingm.planeacion_reabastecimiento', 'U') IS NULL
        BEGIN
            CREATE TABLE vendingm.planeacion_reabastecimiento (
                id_recomendacion INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                id_plan INT NOT NULL,
                id_maquina INT NOT NULL,
                id_producto INT NOT NULL,
                stock_actual INT NOT NULL CONSTRAINT DF_plan_stock DEFAULT 0,
                consumo_7d INT NOT NULL CONSTRAINT DF_plan_consumo7 DEFAULT 0,
                consumo_30d INT NOT NULL CONSTRAINT DF_plan_consumo30 DEFAULT 0,
                demanda_diaria DECIMAL(12,4) NOT NULL CONSTRAINT DF_plan_demanda DEFAULT 0,
                dias_cobertura DECIMAL(12,2) NULL,
                stock_objetivo INT NOT NULL CONSTRAINT DF_plan_objetivo DEFAULT 0,
                cantidad_sugerida INT NOT NULL CONSTRAINT DF_plan_sugerida DEFAULT 0,
                prioridad NVARCHAR(20) NOT NULL,
                motivo NVARCHAR(200) NULL,
                estatus NVARCHAR(20) NOT NULL CONSTRAINT DF_plan_det_estatus DEFAULT 'pendiente',
                fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_plan_det_creacion DEFAULT SYSDATETIME()
            );
            CREATE INDEX IX_plan_reab_plan_prioridad ON vendingm.planeacion_reabastecimiento (id_plan, prioridad, cantidad_sugerida DESC);
            CREATE INDEX IX_plan_reab_maquina ON vendingm.planeacion_reabastecimiento (id_maquina, id_producto);
        END
    ");
}

function generarPlan(PDO $pdo, array $body): void
{
    $fechaPlan = normalizarFechaPlan($body['fecha_plan'] ?? null);
    $horizonte = max(1, min(45, filter_var($body['horizonte_dias'] ?? 7, FILTER_VALIDATE_INT) ?: 7));
    $seguridad = max(1, min(30, filter_var($body['stock_seguridad_dias'] ?? 3, FILTER_VALIDATE_INT) ?: 3));
    $historial = max(7, min(90, filter_var($body['dias_historial'] ?? 30, FILTER_VALIDATE_INT) ?: 30));
    $notas = trim((string) ($body['notas'] ?? '')) ?: null;
    $usuario = Auth::user();
    $generadoPor = $usuario ? trim(($usuario['nombre'] ?? '') . ' ' . ($usuario['apellido'] ?? '')) : null;

    $pdo->beginTransaction();
    $stmtPlan = $pdo->prepare(
        "INSERT INTO vendingm.planeacion_corridas
            (fecha_plan, horizonte_dias, stock_seguridad_dias, dias_historial, generado_por, notas)
         OUTPUT INSERTED.id_plan
         VALUES (:fecha_plan, :horizonte, :seguridad, :historial, :generado_por, :notas)"
    );
    $stmtPlan->execute([
        'fecha_plan' => $fechaPlan,
        'horizonte' => $horizonte,
        'seguridad' => $seguridad,
        'historial' => $historial,
        'generado_por' => $generadoPor,
        'notas' => $notas,
    ]);
    $idPlan = (int) $stmtPlan->fetchColumn();

    // El plan se materializa para consultar 15k maquinas sin recalcular demanda en cada pantalla.
    $stmtDetalle = $pdo->prepare("
        WITH consumo AS (
            SELECT
                mv.id_maquina,
                mv.id_producto,
                SUM(CASE WHEN mv.fecha_movimiento >= DATEADD(day, -7, SYSDATETIME()) THEN mv.cantidad ELSE 0 END) AS consumo_7d,
                SUM(CASE WHEN mv.fecha_movimiento >= DATEADD(day, -1 * :historial, SYSDATETIME()) THEN mv.cantidad ELSE 0 END) AS consumo_hist
            FROM vendingm.movimientos_salida mv
            GROUP BY mv.id_maquina, mv.id_producto
        ),
        stock AS (
            SELECT
                ca.id_maquina,
                l.id_producto,
                SUM(ISNULL(ca.cantidad_actual, 0)) AS stock_actual
            FROM vendingm.carriles ca
            INNER JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
            WHERE l.id_producto IS NOT NULL
              AND ca.numero_carril % 10 <> 0
            GROUP BY ca.id_maquina, l.id_producto
        ),
        universo AS (
            SELECT
                COALESCE(s.id_maquina, c.id_maquina) AS id_maquina,
                COALESCE(s.id_producto, c.id_producto) AS id_producto,
                ISNULL(s.stock_actual, 0) AS stock_actual,
                ISNULL(c.consumo_7d, 0) AS consumo_7d,
                ISNULL(c.consumo_hist, 0) AS consumo_hist
            FROM stock s
            FULL OUTER JOIN consumo c
              ON c.id_maquina = s.id_maquina AND c.id_producto = s.id_producto
        ),
        calc AS (
            SELECT
                u.id_maquina,
                u.id_producto,
                u.stock_actual,
                u.consumo_7d,
                u.consumo_hist,
                CAST(
                    CASE
                        WHEN (u.consumo_7d / 7.0) > (u.consumo_hist / CAST(:historial2 AS FLOAT))
                            THEN (u.consumo_7d / 7.0)
                        ELSE (u.consumo_hist / CAST(:historial3 AS FLOAT))
                    END
                AS DECIMAL(12,4)) AS demanda_diaria
            FROM universo u
            WHERE u.id_maquina IS NOT NULL AND u.id_producto IS NOT NULL
        ),
        sugerido AS (
            SELECT
                c.*,
                CASE WHEN c.demanda_diaria > 0 THEN CAST(c.stock_actual / c.demanda_diaria AS DECIMAL(12,2)) ELSE NULL END AS dias_cobertura,
                CAST(CEILING(c.demanda_diaria * (:horizonte + :seguridad)) AS INT) AS stock_objetivo
            FROM calc c
        )
        INSERT INTO vendingm.planeacion_reabastecimiento
            (id_plan, id_maquina, id_producto, stock_actual, consumo_7d, consumo_30d, demanda_diaria,
             dias_cobertura, stock_objetivo, cantidad_sugerida, prioridad, motivo)
        SELECT
            :id_plan,
            s.id_maquina,
            s.id_producto,
            s.stock_actual,
            s.consumo_7d,
            s.consumo_hist,
            s.demanda_diaria,
            s.dias_cobertura,
            s.stock_objetivo,
            CASE WHEN s.stock_objetivo - s.stock_actual > 0 THEN s.stock_objetivo - s.stock_actual ELSE 0 END AS cantidad_sugerida,
            CASE
                WHEN s.stock_actual <= 0 AND s.demanda_diaria > 0 THEN 'rojo'
                WHEN s.dias_cobertura IS NOT NULL AND s.dias_cobertura <= :seguridad2 THEN 'rojo'
                WHEN s.dias_cobertura IS NOT NULL AND s.dias_cobertura <= (:seguridad3 + 3) THEN 'amarillo'
                WHEN s.demanda_diaria = 0 THEN 'sin_historial'
                ELSE 'verde'
            END AS prioridad,
            CASE
                WHEN s.stock_actual <= 0 AND s.demanda_diaria > 0 THEN 'Sin stock con demanda historica'
                WHEN s.dias_cobertura IS NOT NULL AND s.dias_cobertura <= :seguridad4 THEN 'Cobertura menor o igual al stock de seguridad'
                WHEN s.dias_cobertura IS NOT NULL AND s.dias_cobertura <= (:seguridad5 + 3) THEN 'Cobertura proxima a seguridad'
                WHEN s.demanda_diaria = 0 THEN 'Sin consumo suficiente para pronostico'
                ELSE 'Stock suficiente'
            END AS motivo
        FROM sugerido s
        INNER JOIN vendingm.maquinas m ON m.id_maquina = s.id_maquina
        WHERE
            s.demanda_diaria > 0
            AND (
                s.stock_actual <= 0
                OR s.dias_cobertura <= (:seguridad6 + 3)
                OR s.stock_objetivo > s.stock_actual
            )
    ");
    $stmtDetalle->execute([
        'historial' => $historial,
        'historial2' => $historial,
        'historial3' => $historial,
        'horizonte' => $horizonte,
        'seguridad' => $seguridad,
        'id_plan' => $idPlan,
        'seguridad2' => $seguridad,
        'seguridad3' => $seguridad,
        'seguridad4' => $seguridad,
        'seguridad5' => $seguridad,
        'seguridad6' => $seguridad,
    ]);

    $total = (int) $pdo->query("SELECT COUNT(*) FROM vendingm.planeacion_reabastecimiento WHERE id_plan = {$idPlan}")->fetchColumn();
    $pdo->commit();

    echo json_encode(['ok' => true, 'data' => ['id_plan' => $idPlan, 'total_recomendaciones' => $total]], JSON_UNESCAPED_UNICODE);
}

function responderUltimoPlan(PDO $pdo): void
{
    $stmt = $pdo->query(
        "SELECT TOP 1 id_plan, fecha_plan, horizonte_dias, stock_seguridad_dias, dias_historial,
                estado, generado_por, fecha_generacion, notas
         FROM vendingm.planeacion_corridas
         ORDER BY id_plan DESC"
    );
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        echo json_encode(['ok' => true, 'data' => null], JSON_UNESCAPED_UNICODE);
        return;
    }
    echo json_encode(['ok' => true, 'data' => $plan], JSON_UNESCAPED_UNICODE);
}

function responderResumen(PDO $pdo): void
{
    $idPlan = filter_input(INPUT_GET, 'id_plan', FILTER_VALIDATE_INT) ?: ultimoIdPlan($pdo);
    if (!$idPlan) {
        echo json_encode(['ok' => true, 'data' => null], JSON_UNESCAPED_UNICODE);
        return;
    }
    $params = ['id_plan' => $idPlan];
    $scope = Auth::machineScope('m', $params);
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN pr.prioridad = 'rojo' THEN 1 ELSE 0 END) AS rojos,
            SUM(CASE WHEN pr.prioridad = 'amarillo' THEN 1 ELSE 0 END) AS amarillos,
            SUM(CASE WHEN pr.prioridad = 'verde' THEN 1 ELSE 0 END) AS verdes,
            SUM(pr.cantidad_sugerida) AS piezas_sugeridas,
            COUNT(DISTINCT pr.id_maquina) AS maquinas,
            COUNT(DISTINCT pr.id_producto) AS productos,
            SUM(CASE WHEN pr.estatus = 'pendiente' THEN 1 ELSE 0 END) AS pendientes,
            SUM(CASE WHEN pr.estatus = 'programado' THEN 1 ELSE 0 END) AS programados,
            SUM(CASE WHEN pr.estatus = 'surtido' THEN 1 ELSE 0 END) AS surtidos,
            SUM(CASE WHEN pr.estatus = 'omitido' THEN 1 ELSE 0 END) AS omitidos
         FROM vendingm.planeacion_reabastecimiento pr
         INNER JOIN vendingm.maquinas m ON m.id_maquina = pr.id_maquina
         WHERE pr.id_plan = :id_plan {$scope}"
    );
    $stmt->execute($params);
    echo json_encode(['ok' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC) ?: []], JSON_UNESCAPED_UNICODE);
}

function responderDetalle(PDO $pdo): void
{
    $idPlan = filter_input(INPUT_GET, 'id_plan', FILTER_VALIDATE_INT) ?: ultimoIdPlan($pdo);
    $q = trim((string) ($_GET['q'] ?? ''));
    $prioridad = (string) ($_GET['prioridad'] ?? '');
    $estatus = (string) ($_GET['estatus'] ?? '');
    $page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
    $pageSize = min(200, max(1, filter_input(INPUT_GET, 'page_size', FILTER_VALIDATE_INT) ?: 50));
    $offset = ($page - 1) * $pageSize;

    if (!$idPlan) {
        echo json_encode(['ok' => true, 'data' => [], 'meta' => ['total' => 0, 'page' => $page, 'page_size' => $pageSize]], JSON_UNESCAPED_UNICODE);
        return;
    }

    $where = ['pr.id_plan = :id_plan'];
    $params = ['id_plan' => $idPlan];
    $auth = Auth::machineCondition('m', $params);
    if ($auth) {
        $where[] = $auth;
    }
    if (in_array($prioridad, ['rojo', 'amarillo', 'verde', 'sin_historial'], true)) {
        $where[] = 'pr.prioridad = :prioridad';
        $params['prioridad'] = $prioridad;
    }
    if (in_array($estatus, ['pendiente', 'programado', 'surtido', 'omitido'], true)) {
        $where[] = 'pr.estatus = :estatus';
        $params['estatus'] = $estatus;
    }
    if ($q !== '') {
        $where[] = '(m.numero_serie LIKE :q1 OR m.nombre_ubicacion LIKE :q2 OR p.nombre LIKE :q3 OR p.codigo_barras LIKE :q4)';
        $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = "%{$q}%";
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $stmtTotal = $pdo->prepare(
        "SELECT COUNT(*)
         FROM vendingm.planeacion_reabastecimiento pr
         INNER JOIN vendingm.maquinas m ON m.id_maquina = pr.id_maquina
         INNER JOIN vendingm.productos p ON p.id_producto = pr.id_producto
         {$whereSql}"
    );
    $stmtTotal->execute($params);
    $total = (int) $stmtTotal->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT
            pr.id_recomendacion, pr.id_plan, pr.id_maquina, pr.id_producto, pr.stock_actual,
            pr.consumo_7d, pr.consumo_30d, pr.demanda_diaria, pr.dias_cobertura,
            pr.stock_objetivo, pr.cantidad_sugerida, pr.prioridad, pr.motivo, pr.estatus,
            m.numero_serie, m.nombre_ubicacion, m.direccion,
            p.nombre AS producto, p.codigo_barras
         FROM vendingm.planeacion_reabastecimiento pr
         INNER JOIN vendingm.maquinas m ON m.id_maquina = pr.id_maquina
         INNER JOIN vendingm.productos p ON p.id_producto = pr.id_producto
         {$whereSql}
         ORDER BY
            CASE pr.prioridad WHEN 'rojo' THEN 0 WHEN 'amarillo' THEN 1 WHEN 'verde' THEN 2 ELSE 3 END,
            pr.cantidad_sugerida DESC,
            m.numero_serie ASC
         OFFSET :offset ROWS FETCH NEXT :page_size ROWS ONLY"
    );
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue('page_size', $pageSize, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode([
        'ok' => true,
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'meta' => ['total' => $total, 'page' => $page, 'page_size' => $pageSize, 'id_plan' => $idPlan],
    ], JSON_UNESCAPED_UNICODE);
}

function responderRotacion(PDO $pdo): void
{
    $dias = max(7, min(180, filter_input(INPUT_GET, 'dias', FILTER_VALIDATE_INT) ?: 30));
    $q = trim((string) ($_GET['q'] ?? ''));
    $estadoGeo = trim((string) ($_GET['estado_geo'] ?? ''));
    $pageSize = min(50, max(1, filter_input(INPUT_GET, 'page_size', FILTER_VALIDATE_INT) ?: 12));

    $where = ['m.id_estatus <> 3'];
    $params = ['dias' => $dias, 'dias2' => $dias, 'dias3' => $dias];
    $auth = Auth::machineCondition('m', $params);
    if ($auth) {
        $where[] = $auth;
    }
    if ($q !== '') {
        $where[] = '(m.numero_serie LIKE :q1 OR m.nombre_ubicacion LIKE :q2 OR m.direccion LIKE :q3)';
        $params['q1'] = $params['q2'] = $params['q3'] = "%{$q}%";
    }
    if ($estadoGeo !== '') {
        $where[] = '(m.direccion LIKE :estado_geo OR m.nombre_ubicacion LIKE :estado_geo2)';
        $params['estado_geo'] = $params['estado_geo2'] = "%{$estadoGeo}%";
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT TOP (:limit)
            m.id_maquina,
            m.numero_serie,
            m.nombre_ubicacion,
            m.direccion,
            alto.producto_alta,
            alto.codigo_alta,
            ISNULL(alto.unidades_alta, 0) AS unidades_alta,
            bajo.producto_baja,
            bajo.codigo_baja,
            ISNULL(bajo.unidades_baja, 0) AS unidades_baja,
            ISNULL(bajo.stock_actual, 0) AS stock_baja,
            recomendado.producto_recomendado,
            recomendado.codigo_recomendado,
            ISNULL(recomendado.unidades_recomendado, 0) AS unidades_recomendado
         FROM vendingm.maquinas m
         OUTER APPLY (
            SELECT TOP 1 p.nombre AS producto_alta, p.codigo_barras AS codigo_alta, SUM(ms.cantidad) AS unidades_alta
            FROM vendingm.movimientos_salida ms
            INNER JOIN vendingm.productos p ON p.id_producto = ms.id_producto
            WHERE ms.id_maquina = m.id_maquina
              AND ms.id_tipo_movimiento = 1
              AND ms.fecha_movimiento >= DATEADD(day, -1 * :dias, SYSDATETIME())
            GROUP BY p.nombre, p.codigo_barras
            ORDER BY SUM(ms.cantidad) DESC, p.nombre ASC
         ) alto
         OUTER APPLY (
            SELECT TOP 1 p.nombre AS producto_baja, p.codigo_barras AS codigo_baja,
                   ISNULL(v.unidades, 0) AS unidades_baja,
                   SUM(ISNULL(ca.cantidad_actual, 0)) AS stock_actual
            FROM vendingm.carriles ca
            LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
            INNER JOIN vendingm.productos p ON p.id_producto = COALESCE(ca.id_producto, l.id_producto)
            OUTER APPLY (
                SELECT SUM(ms.cantidad) AS unidades
                FROM vendingm.movimientos_salida ms
                WHERE ms.id_maquina = ca.id_maquina
                  AND ms.id_producto = p.id_producto
                  AND ms.id_tipo_movimiento = 1
                  AND ms.fecha_movimiento >= DATEADD(day, -1 * :dias2, SYSDATETIME())
            ) v
            WHERE ca.id_maquina = m.id_maquina
              AND ca.numero_carril % 10 <> 0
              AND ISNULL(ca.cantidad_actual, 0) > 0
            GROUP BY p.nombre, p.codigo_barras, ISNULL(v.unidades, 0)
            ORDER BY ISNULL(v.unidades, 0) ASC, SUM(ISNULL(ca.cantidad_actual, 0)) DESC, p.nombre ASC
         ) bajo
         OUTER APPLY (
            SELECT TOP 1 p.nombre AS producto_recomendado, p.codigo_barras AS codigo_recomendado, SUM(ms.cantidad) AS unidades_recomendado
            FROM vendingm.movimientos_salida ms
            INNER JOIN vendingm.productos p ON p.id_producto = ms.id_producto
            WHERE ms.id_tipo_movimiento = 1
              AND ms.fecha_movimiento >= DATEADD(day, -1 * :dias3, SYSDATETIME())
              AND NOT EXISTS (
                SELECT 1
                FROM vendingm.carriles ca2
                LEFT JOIN vendingm.lotes l2 ON l2.id_lote = ca2.id_lote_actual
                WHERE ca2.id_maquina = m.id_maquina
                  AND COALESCE(ca2.id_producto, l2.id_producto) = p.id_producto
              )
            GROUP BY p.nombre, p.codigo_barras
            ORDER BY SUM(ms.cantidad) DESC, p.nombre ASC
         ) recomendado
         {$whereSql}
           AND (alto.producto_alta IS NOT NULL OR bajo.producto_baja IS NOT NULL)
         ORDER BY ISNULL(alto.unidades_alta, 0) DESC, ISNULL(bajo.stock_actual, 0) DESC, m.numero_serie ASC"
    );
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue('limit', $pageSize, PDO::PARAM_INT);
    $stmt->execute();
    $filas = array_map(static function (array $f): array {
        $alta = (int) ($f['unidades_alta'] ?? 0);
        $baja = (int) ($f['unidades_baja'] ?? 0);
        $rec = (int) ($f['unidades_recomendado'] ?? 0);
        $f['impacto_estimado'] = max(0, $rec - $baja);
        $f['recomendacion'] = ($f['producto_baja'] && $f['producto_recomendado'] && $rec > $baja)
            ? "Cambiar {$f['producto_baja']} por {$f['producto_recomendado']}: +{$f['impacto_estimado']} piezas estimadas en el periodo."
            : 'Sin cambio sugerido con los datos actuales.';
        return $f;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
        'ok' => true,
        'data' => $filas,
        'meta' => ['dias' => $dias, 'page_size' => $pageSize, 'estado_geo' => $estadoGeo],
    ], JSON_UNESCAPED_UNICODE);
}

function cambiarEstatus(PDO $pdo, array $body): void
{
    $id = filter_var($body['id_recomendacion'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $estatus = (string) ($body['estatus'] ?? '');
    if (!$id || !in_array($estatus, ['pendiente', 'programado', 'surtido', 'omitido'], true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Estatus no valido'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $stmt = $pdo->prepare('UPDATE vendingm.planeacion_reabastecimiento SET estatus = :estatus WHERE id_recomendacion = :id');
    $stmt->execute(['estatus' => $estatus, 'id' => $id]);
    echo json_encode(['ok' => true, 'data' => ['accion' => 'actualizado']], JSON_UNESCAPED_UNICODE);
}

function ultimoIdPlan(PDO $pdo): ?int
{
    $id = $pdo->query('SELECT TOP 1 id_plan FROM vendingm.planeacion_corridas ORDER BY id_plan DESC')->fetchColumn();
    return $id === false ? null : (int) $id;
}

function normalizarFechaPlan(mixed $valor): string
{
    if ($valor === null || $valor === '') {
        return (new DateTime())->format('Y-m-d');
    }
    return (new DateTime((string) $valor))->format('Y-m-d');
}
