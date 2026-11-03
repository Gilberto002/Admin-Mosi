<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Auth\Auth;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$metodo = $_SERVER['REQUEST_METHOD'];
$accion = (string) ($_GET['accion'] ?? 'resumen');

try {
    $pdo = Connection::get();
    asegurarTablasRefacciones($pdo);

    if ($metodo === 'GET') {
        if ($accion === 'resumen') {
            responderResumen($pdo);
        } elseif ($accion === 'refacciones') {
            responderRefacciones($pdo);
        } elseif ($accion === 'movimientos') {
            responderMovimientos($pdo);
        } elseif ($accion === 'maquinas') {
            responderMaquinas($pdo);
        } else {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Acción no válida'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($metodo === 'POST') {
        Auth::requireAdmin();
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'JSON inválido'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($accion === 'refaccion') {
            guardarRefaccion($pdo, $body);
        } elseif ($accion === 'movimiento') {
            guardarMovimiento($pdo, $body);
        } else {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Acción no válida'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], JSON_UNESCAPED_UNICODE);
}

function asegurarTablasRefacciones(PDO $pdo): void
{
    $pdo->exec("
        IF OBJECT_ID('vendingm.refacciones', 'U') IS NULL
        BEGIN
            CREATE TABLE vendingm.refacciones (
                id_refaccion INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                nombre NVARCHAR(150) NOT NULL,
                categoria NVARCHAR(80) NULL,
                modelo_compatible NVARCHAR(120) NULL,
                ubicacion_almacen NVARCHAR(120) NULL,
                proveedor NVARCHAR(120) NULL,
                costo_unitario DECIMAL(12,2) NULL,
                stock_actual INT NOT NULL CONSTRAINT DF_refacciones_stock DEFAULT 0,
                stock_minimo INT NOT NULL CONSTRAINT DF_refacciones_minimo DEFAULT 0,
                activa BIT NOT NULL CONSTRAINT DF_refacciones_activa DEFAULT 1,
                fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_refacciones_creacion DEFAULT SYSDATETIME(),
                fecha_actualizacion DATETIME2 NULL
            );
        END
    ");

    $pdo->exec("
        IF OBJECT_ID('vendingm.movimientos_refaccion', 'U') IS NULL
        BEGIN
            CREATE TABLE vendingm.movimientos_refaccion (
                id_movimiento INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                id_refaccion INT NOT NULL,
                tipo NVARCHAR(20) NOT NULL,
                cantidad INT NOT NULL,
                id_maquina INT NULL,
                tecnico NVARCHAR(120) NULL,
                motivo NVARCHAR(250) NULL,
                fecha_movimiento DATETIME2 NOT NULL CONSTRAINT DF_mov_ref_fecha DEFAULT SYSDATETIME()
            );
        END
    ");
}

function responderResumen(PDO $pdo): void
{
    $params = [];
    $scope = Auth::machineScope('m', $params);

    $stmtMaq = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_maquinas,
            SUM(CASE WHEN LOWER(ISNULL(em.nombre, '')) LIKE '%act%' THEN 1 ELSE 0 END) AS activas,
            SUM(CASE WHEN LOWER(ISNULL(em.nombre, '')) LIKE '%mant%' THEN 1 ELSE 0 END) AS en_mantenimiento,
            SUM(CASE WHEN LOWER(ISNULL(em.nombre, '')) LIKE '%fuera%' OR LOWER(ISNULL(em.nombre, '')) LIKE '%baja%' THEN 1 ELSE 0 END) AS paradas,
            SUM(CASE WHEN m.nombre_ubicacion IS NULL OR LTRIM(RTRIM(m.nombre_ubicacion)) = '' THEN 1 ELSE 0 END) AS sin_ocupar
         FROM vendingm.maquinas m
         LEFT JOIN vendingm.estatus_maquina em ON em.id_estatus = m.id_estatus
         WHERE 1 = 1 {$scope}"
    );
    $stmtMaq->execute($params);
    $maquinas = $stmtMaq->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmtMant = $pdo->prepare(
        "SELECT
            COUNT(*) AS mantenimientos_abiertos,
            SUM(CASE WHEN mm.fecha_programada IS NOT NULL AND mm.fecha_programada < CAST(SYSDATETIME() AS DATE) AND mm.fecha_realizado IS NULL THEN 1 ELSE 0 END) AS vencidos
         FROM vendingm.mantenimientos_maquina mm
         INNER JOIN vendingm.maquinas m ON m.id_maquina = mm.id_maquina
         WHERE mm.fecha_realizado IS NULL {$scope}"
    );
    $stmtMant->execute($params);
    $mantenimientos = $stmtMant->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmtRef = $pdo->query(
        "SELECT
            COUNT(*) AS total_refacciones,
            SUM(CASE WHEN stock_actual <= stock_minimo THEN 1 ELSE 0 END) AS refacciones_bajas,
            SUM(stock_actual) AS piezas_en_almacen
         FROM vendingm.refacciones
         WHERE activa = 1"
    );
    $refacciones = $stmtRef->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'ok' => true,
        'data' => [
            'maquinas' => $maquinas,
            'mantenimientos' => $mantenimientos,
            'refacciones' => $refacciones,
        ],
    ], JSON_UNESCAPED_UNICODE);
}

function responderRefacciones(PDO $pdo): void
{
    $q = trim((string) ($_GET['q'] ?? ''));
    $soloBajas = ($_GET['bajas'] ?? '') === '1';
    $params = [];
    $where = ['activa = 1'];

    if ($q !== '') {
        $where[] = '(nombre LIKE :q1 OR categoria LIKE :q2 OR modelo_compatible LIKE :q3 OR ubicacion_almacen LIKE :q4)';
        $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = "%{$q}%";
    }
    if ($soloBajas) {
        $where[] = 'stock_actual <= stock_minimo';
    }

    $stmt = $pdo->prepare(
        'SELECT id_refaccion, nombre, categoria, modelo_compatible, ubicacion_almacen, proveedor,
                costo_unitario, stock_actual, stock_minimo
         FROM vendingm.refacciones
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY CASE WHEN stock_actual <= stock_minimo THEN 0 ELSE 1 END, nombre'
    );
    $stmt->execute($params);
    echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
}

function responderMovimientos(PDO $pdo): void
{
    $stmt = $pdo->query(
        "SELECT TOP 40 mr.id_movimiento, mr.tipo, mr.cantidad, mr.tecnico, mr.motivo, mr.fecha_movimiento,
                r.nombre AS refaccion, m.numero_serie
         FROM vendingm.movimientos_refaccion mr
         INNER JOIN vendingm.refacciones r ON r.id_refaccion = mr.id_refaccion
         LEFT JOIN vendingm.maquinas m ON m.id_maquina = mr.id_maquina
         ORDER BY mr.fecha_movimiento DESC, mr.id_movimiento DESC"
    );
    echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
}

function responderMaquinas(PDO $pdo): void
{
    $params = [];
    $scope = Auth::machineScope('m', $params);
    $stmt = $pdo->prepare(
        "SELECT TOP 500 m.id_maquina, m.numero_serie, m.nombre_ubicacion
         FROM vendingm.maquinas m
         WHERE 1 = 1 {$scope}
         ORDER BY m.numero_serie"
    );
    $stmt->execute($params);
    echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
}

function guardarRefaccion(PDO $pdo, array $body): void
{
    $id = filter_var($body['id_refaccion'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $nombre = trim((string) ($body['nombre'] ?? ''));
    $categoria = trim((string) ($body['categoria'] ?? '')) ?: null;
    $modelo = trim((string) ($body['modelo_compatible'] ?? '')) ?: null;
    $ubicacion = trim((string) ($body['ubicacion_almacen'] ?? '')) ?: null;
    $proveedor = trim((string) ($body['proveedor'] ?? '')) ?: null;
    $costo = isset($body['costo_unitario']) && $body['costo_unitario'] !== '' ? (float) $body['costo_unitario'] : null;
    $stock = filter_var($body['stock_actual'] ?? 0, FILTER_VALIDATE_INT);
    $minimo = filter_var($body['stock_minimo'] ?? 0, FILTER_VALIDATE_INT);

    if ($nombre === '' || $stock === false || $minimo === false || $stock < 0 || $minimo < 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Nombre, stock y mínimo son obligatorios'], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($id) {
        $stmt = $pdo->prepare(
            "UPDATE vendingm.refacciones
             SET nombre = :nombre, categoria = :categoria, modelo_compatible = :modelo,
                 ubicacion_almacen = :ubicacion, proveedor = :proveedor, costo_unitario = :costo,
                 stock_actual = :stock, stock_minimo = :minimo, fecha_actualizacion = SYSDATETIME()
             WHERE id_refaccion = :id"
        );
        $stmt->execute(compact('nombre', 'categoria', 'modelo', 'ubicacion', 'proveedor', 'costo', 'stock', 'minimo', 'id'));
        $accion = 'actualizada';
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO vendingm.refacciones
                (nombre, categoria, modelo_compatible, ubicacion_almacen, proveedor, costo_unitario, stock_actual, stock_minimo)
             OUTPUT INSERTED.id_refaccion
             VALUES (:nombre, :categoria, :modelo, :ubicacion, :proveedor, :costo, :stock, :minimo)"
        );
        $stmt->execute(compact('nombre', 'categoria', 'modelo', 'ubicacion', 'proveedor', 'costo', 'stock', 'minimo'));
        $id = (int) $stmt->fetchColumn();
        $accion = 'creada';
    }

    echo json_encode(['ok' => true, 'data' => ['id_refaccion' => $id, 'accion' => $accion]], JSON_UNESCAPED_UNICODE);
}

function guardarMovimiento(PDO $pdo, array $body): void
{
    $idRefaccion = filter_var($body['id_refaccion'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $tipo = strtolower(trim((string) ($body['tipo'] ?? '')));
    $cantidad = filter_var($body['cantidad'] ?? null, FILTER_VALIDATE_INT);
    $idMaquina = filter_var($body['id_maquina'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $tecnico = trim((string) ($body['tecnico'] ?? '')) ?: null;
    $motivo = trim((string) ($body['motivo'] ?? '')) ?: null;

    if (!$idRefaccion || !in_array($tipo, ['entrada', 'salida', 'ajuste'], true) || $cantidad === false || $cantidad <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Refacción, tipo y cantidad son obligatorios'], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($idMaquina && !Auth::canAccessMachine($pdo, $idMaquina)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No tienes acceso a esta máquina'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $delta = $tipo === 'salida' ? -$cantidad : $cantidad;

    $pdo->beginTransaction();
    $stmtStock = $pdo->prepare('SELECT stock_actual FROM vendingm.refacciones WHERE id_refaccion = :id AND activa = 1');
    $stmtStock->execute(['id' => $idRefaccion]);
    $stockActual = $stmtStock->fetchColumn();
    if ($stockActual === false || (int) $stockActual + $delta < 0) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Stock insuficiente o refacción no encontrada'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $stmtMov = $pdo->prepare(
        "INSERT INTO vendingm.movimientos_refaccion (id_refaccion, tipo, cantidad, id_maquina, tecnico, motivo)
         VALUES (:id_refaccion, :tipo, :cantidad, :id_maquina, :tecnico, :motivo)"
    );
    $stmtMov->execute([
        'id_refaccion' => $idRefaccion,
        'tipo' => $tipo,
        'cantidad' => $cantidad,
        'id_maquina' => $idMaquina,
        'tecnico' => $tecnico,
        'motivo' => $motivo,
    ]);

    $stmtUpd = $pdo->prepare(
        'UPDATE vendingm.refacciones
         SET stock_actual = stock_actual + :delta, fecha_actualizacion = SYSDATETIME()
         WHERE id_refaccion = :id'
    );
    $stmtUpd->bindValue('delta', $delta, PDO::PARAM_INT);
    $stmtUpd->bindValue('id', $idRefaccion, PDO::PARAM_INT);
    $stmtUpd->execute();
    $pdo->commit();

    echo json_encode(['ok' => true, 'data' => ['accion' => 'registrado']], JSON_UNESCAPED_UNICODE);
}
