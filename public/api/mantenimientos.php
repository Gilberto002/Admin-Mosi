<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Auth\Auth;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$metodo = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = Connection::get();

    if ($metodo === 'GET') {
        $idMaquina = filter_input(INPUT_GET, 'id_maquina', FILTER_VALIDATE_INT);
        if (!$idMaquina) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Falta id_maquina']);
            exit;
        }
        if (!Auth::canAccessMachine($pdo, $idMaquina)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'No tienes acceso a esta maquina']);
            exit;
        }

        $stmt = $pdo->prepare(
            "SELECT mm.id_mantenimiento, mm.id_maquina, mm.tipo_mantenimiento, tm.nombre AS tipo_nombre,
                    mm.descripcion, mm.fecha_programada, mm.fecha_realizado, mm.tecnico, mm.id_estatus,
                    mm.fecha_creacion, em.nombre AS estatus_nombre
             FROM vendingm.mantenimientos_maquina mm
             LEFT JOIN vendingm.estatus_maquina em ON em.id_estatus = mm.id_estatus
             LEFT JOIN vendingm.tipos_mantenimiento tm ON tm.id_tipo_mantenimiento = mm.tipo_mantenimiento
             WHERE mm.id_maquina = :id
             ORDER BY COALESCE(mm.fecha_realizado, CAST(mm.fecha_programada AS DATETIME2)) DESC, mm.id_mantenimiento DESC"
        );
        $stmt->execute(['id' => $idMaquina]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($metodo === 'POST') {
        // Crear/editar un mantenimiento (y de paso actualizar
        // vendingm.maquinas.fecha_ultimo_mantenimiento) es una accion de
        // mantenimiento real -- el rol "cliente" (cliente_dashboard.php)
        // solo puede consultar, nunca escribir aqui. Mismo patron que
        // mantenimiento_operacion.php para su propio branch de escritura.
        Auth::requireAdmin();

        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
            exit;
        }

        $idMantenimiento = isset($body['id_mantenimiento']) ? (int) $body['id_mantenimiento'] : null;
        $idMaquina       = (int) ($body['id_maquina'] ?? 0);
        // tipo_mantenimiento ahora es un tinyint (catálogo), no texto libre.
        $tipoMantenimiento = filter_var($body['tipo_mantenimiento'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $descripcion     = trim((string) ($body['descripcion'] ?? ''));
        $fechaProgramada = $body['fecha_programada'] ?: null;
        $fechaRealizado  = $body['fecha_realizado'] ?: null;
        $tecnico         = trim((string) ($body['tecnico'] ?? ''));
        $idEstatus       = filter_var($body['id_estatus'] ?? null, FILTER_VALIDATE_INT) ?: null;

        if (!$idMaquina || !$tipoMantenimiento || !$idEstatus) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Faltan campos obligatorios (máquina, tipo, estatus)']);
            exit;
        }

        if (!Auth::canAccessMachine($pdo, $idMaquina)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'No tienes acceso a esta maquina']);
            exit;
        }

        $pdo->beginTransaction();

        if ($idMantenimiento) {
            $stmt = $pdo->prepare(
                "UPDATE vendingm.mantenimientos_maquina
                 SET tipo_mantenimiento = :tipo, descripcion = :descripcion,
                     fecha_programada = :fecha_programada, fecha_realizado = :fecha_realizado,
                     tecnico = :tecnico, id_estatus = :id_estatus
                 WHERE id_mantenimiento = :id"
            );
            $stmt->bindValue('tipo', $tipoMantenimiento, PDO::PARAM_INT);
            $stmt->bindValue('descripcion', $descripcion);
            $stmt->bindValue('fecha_programada', $fechaProgramada);
            $stmt->bindValue('fecha_realizado', $fechaRealizado);
            $stmt->bindValue('tecnico', $tecnico);
            $stmt->bindValue('id_estatus', $idEstatus, PDO::PARAM_INT);
            $stmt->bindValue('id', $idMantenimiento, PDO::PARAM_INT);
            $stmt->execute();
            $accion = 'actualizado';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO vendingm.mantenimientos_maquina
                    (id_maquina, tipo_mantenimiento, descripcion, fecha_programada, fecha_realizado, tecnico, id_estatus, fecha_creacion)
                 OUTPUT INSERTED.id_mantenimiento
                 VALUES (:id_maquina, :tipo, :descripcion, :fecha_programada, :fecha_realizado, :tecnico, :id_estatus, SYSDATETIME())"
            );
            $stmt->bindValue('id_maquina', $idMaquina, PDO::PARAM_INT);
            $stmt->bindValue('tipo', $tipoMantenimiento, PDO::PARAM_INT);
            $stmt->bindValue('descripcion', $descripcion);
            $stmt->bindValue('fecha_programada', $fechaProgramada);
            $stmt->bindValue('fecha_realizado', $fechaRealizado);
            $stmt->bindValue('tecnico', $tecnico);
            $stmt->bindValue('id_estatus', $idEstatus, PDO::PARAM_INT);
            $stmt->execute();
            $idMantenimiento = (int) $stmt->fetchColumn();
            $accion = 'creado';
        }

        // Si quedó marcado como realizado, actualizamos la fecha de último
        // mantenimiento de la máquina para que el dashboard/donut la reflejen.
        if ($fechaRealizado) {
            $stmtUpd = $pdo->prepare(
                "UPDATE vendingm.maquinas
                 SET fecha_ultimo_mantenimiento = :fecha, fecha_actualizacion = SYSDATETIME()
                 WHERE id_maquina = :id
                   AND (fecha_ultimo_mantenimiento IS NULL OR fecha_ultimo_mantenimiento < :fecha2)"
            );
            $stmtUpd->execute([
                'fecha' => $fechaRealizado,
                'id' => $idMaquina,
                'fecha2' => $fechaRealizado,
            ]);
        }

        $pdo->commit();

        echo json_encode(['ok' => true, 'data' => ['id_mantenimiento' => $idMantenimiento, 'accion' => $accion]]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')]);
}
