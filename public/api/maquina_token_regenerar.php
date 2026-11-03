<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Api\AndroidApi;
use App\Database\Connection;
use App\Auth\Auth;
use App\Http\JsonResponse;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido, use POST']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

$idMaquina = filter_var($body['id_maquina'] ?? null, FILTER_VALIDATE_INT);
if (!$idMaquina) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta id_maquina']);
    exit;
}

try {
    $pdo = Connection::get();
    AndroidApi::asegurarTablas($pdo);

    $stmt = $pdo->prepare('SELECT id_maquina FROM vendingm.maquinas WHERE id_maquina = :id');
    $stmt->execute(['id' => $idMaquina]);
    if (!$stmt->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Máquina no encontrada']);
        exit;
    }

    // Token en texto plano: solo se devuelve en esta respuesta, nunca se
    // vuelve a poder recuperar (solo se guarda su hash). El admin debe
    // copiarlo ahora y configurarlo en el dispositivo Android de esa máquina.
    //
    // Migrado a vendingm.api_tokens_maquina (App\Api\AndroidApi, integrado
    // del fork de Gilberto el 2026-08-24) en vez de
    // vendingm.maquinas.token_android_hash -- password_hash() en vez de un
    // sha256 plano, y con historial: el token anterior se revoca (no se
    // borra) en vez de sobreescribirse.
    $token = bin2hex(random_bytes(32));
    $hash = password_hash($token, PASSWORD_DEFAULT);

    $pdo->beginTransaction();
    try {
        $revocar = $pdo->prepare(
            'UPDATE vendingm.api_tokens_maquina
             SET revocado = 1, fecha_revocacion = SYSDATETIME()
             WHERE id_maquina = :id AND revocado = 0'
        );
        $revocar->execute(['id' => $idMaquina]);

        $ins = $pdo->prepare(
            'INSERT INTO vendingm.api_tokens_maquina (id_maquina, token_hash)
             VALUES (:id, :hash)'
        );
        $ins->execute(['id' => $idMaquina, 'hash' => $hash]);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    echo json_encode(['ok' => true, 'data' => ['id_maquina' => $idMaquina, 'token' => $token]]);
} catch (\Throwable $e) {
    JsonResponse::serverError($e, 'maquina_token_regenerar');
}
