<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Api\AndroidApi;
use App\Auth\Auth;
use App\Database\Connection;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Connection::get();
    AndroidApi::asegurarTablas($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query(
            "SELECT t.id_token, t.id_maquina, m.numero_serie, m.codigo_interno, m.nombre_ubicacion,
                    t.revocado, t.fecha_creacion, t.fecha_revocacion, t.ultimo_uso
             FROM vendingm.api_tokens_maquina t
             INNER JOIN vendingm.maquinas m ON m.id_maquina = t.id_maquina
             ORDER BY t.fecha_creacion DESC"
        );
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Metodo no permitido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'JSON invalido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $accion = trim((string) ($body['accion'] ?? 'crear'));
    $idMaquina = filter_var($body['id_maquina'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $numeroSerie = trim((string) ($body['numero_serie'] ?? ''));
    if (!$idMaquina && $numeroSerie === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Falta numero_serie'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $maquina = AndroidApi::buscarMaquina($pdo, $idMaquina, $numeroSerie);
    if (!$maquina) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Maquina no encontrada'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $idMaquina = (int) $maquina['id_maquina'];

    if ($accion === 'revocar') {
        $upd = $pdo->prepare(
            "UPDATE vendingm.api_tokens_maquina
             SET revocado = 1, fecha_revocacion = SYSDATETIME()
             WHERE id_maquina = :id AND revocado = 0"
        );
        $upd->execute(['id' => $idMaquina]);
        echo json_encode(['ok' => true, 'data' => ['id_maquina' => $idMaquina, 'revocados' => $upd->rowCount()]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($accion !== 'crear') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Accion invalida'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();
    $pdo->prepare(
        "UPDATE vendingm.api_tokens_maquina
         SET revocado = 1, fecha_revocacion = SYSDATETIME()
         WHERE id_maquina = :id AND revocado = 0"
    )->execute(['id' => $idMaquina]);

    $token = 'MLVENDING_' . bin2hex(random_bytes(24));
    $hash = password_hash($token, PASSWORD_DEFAULT);

    $ins = $pdo->prepare(
        "INSERT INTO vendingm.api_tokens_maquina (id_maquina, token_hash)
         OUTPUT INSERTED.id_token
         VALUES (:id_maquina, :token_hash)"
    );
    $ins->execute(['id_maquina' => $idMaquina, 'token_hash' => $hash]);
    $idToken = (int) $ins->fetchColumn();
    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'data' => [
            'id_token' => $idToken,
            'id_maquina' => $idMaquina,
            'numero_serie' => $maquina['numero_serie'],
            'token' => $token,
            'nota' => 'Este token se muestra una sola vez. Guardalo en local.properties de la app/dispositivo.',
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], JSON_UNESCAPED_UNICODE);
}
