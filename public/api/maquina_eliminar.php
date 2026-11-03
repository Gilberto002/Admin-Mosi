<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido, use POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

$idMaquina = filter_var($body['id_maquina'] ?? null, FILTER_VALIDATE_INT);
if (!$idMaquina) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta id_maquina'], JSON_UNESCAPED_UNICODE);
    exit;
}

const ESTATUS_ELIMINADA = 3; // Fuera de servicio

try {
    $pdo = Connection::get();

    $stmt = $pdo->prepare(
        'SELECT id_maquina, numero_serie, rol_maquina, id_estatus
         FROM vendingm.maquinas
         WHERE id_maquina = :id'
    );
    $stmt->execute(['id' => $idMaquina]);
    $maquina = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$maquina) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Maquina no encontrada'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int) $maquina['id_estatus'] === ESTATUS_ELIMINADA) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'La maquina ya esta marcada como fuera de servicio'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Se envuelve en una transacción con WITH (UPDLOCK, HOLDLOCK): sin esto,
    // entre el SELECT de esclavas y el UPDATE una esclava podría activarse
    // justo a la mitad, dejando una huérfana activa fuera de la baja
    // (condición de carrera bajo altas/bajas concurrentes).
    $pdo->beginTransaction();

    $idsBaja = [$idMaquina];

    if ((int) $maquina['rol_maquina'] === 1) {
        $stmtEsc = $pdo->prepare(
            'SELECT id_maquina
             FROM vendingm.maquinas WITH (UPDLOCK, HOLDLOCK)
             WHERE id_maquina_padre = :id AND id_estatus <> :baja'
        );
        $stmtEsc->execute(['id' => $idMaquina, 'baja' => ESTATUS_ELIMINADA]);
        foreach ($stmtEsc->fetchAll(PDO::FETCH_COLUMN) as $idEsc) {
            $idsBaja[] = (int) $idEsc;
        }
    }

    $placeholders = implode(',', array_fill(0, count($idsBaja), '?'));
    $upd = $pdo->prepare(
        "UPDATE vendingm.maquinas
         SET id_estatus = ?, fecha_actualizacion = SYSDATETIME()
         WHERE id_maquina IN ({$placeholders})"
    );
    $upd->execute(array_merge([ESTATUS_ELIMINADA], $idsBaja));

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'data' => [
            'id_maquina' => $idMaquina,
            'numero_serie' => $maquina['numero_serie'],
            'id_estatus' => ESTATUS_ELIMINADA,
            'maquinas_afectadas' => count($idsBaja),
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], JSON_UNESCAPED_UNICODE);
}
