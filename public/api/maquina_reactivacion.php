<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Api\AndroidApi;
use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;
use App\Maquinas\ActivacionMaquina;

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::error('Metodo no permitido', 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

$idMaquina = filter_var($body['id_maquina'] ?? null, FILTER_VALIDATE_INT);
$permitir = isset($body['permitir_reactivacion'])
    ? in_array((string) $body['permitir_reactivacion'], ['1', 'true', 'on'], true)
    : true;

if (!$idMaquina) {
    JsonResponse::error('Falta id_maquina', 422);
}

try {
    $pdo = Connection::get();
    ActivacionMaquina::asegurarColumnas($pdo);
    AndroidApi::asegurarTablas($pdo);

    $stmt = $pdo->prepare('SELECT id_maquina, numero_serie FROM vendingm.maquinas WHERE id_maquina = :id');
    $stmt->execute(['id' => $idMaquina]);
    $maquina = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$maquina) {
        JsonResponse::error('Maquina no encontrada', 404);
    }

    $pdo->beginTransaction();
    $pdo->prepare(
        "UPDATE vendingm.maquinas
         SET permitir_reactivacion = :permitir,
             fecha_actualizacion = SYSDATETIME()
         WHERE id_maquina = :id"
    )->execute([
        'permitir' => $permitir ? 1 : 0,
        'id' => $idMaquina,
    ]);

    if ($permitir) {
        $pdo->prepare(
            "UPDATE vendingm.api_tokens_maquina
             SET revocado = 1, fecha_revocacion = SYSDATETIME()
             WHERE id_maquina = :id AND revocado = 0"
        )->execute(['id' => $idMaquina]);
    }
    $pdo->commit();

    JsonResponse::ok([
        'id_maquina' => (int) $idMaquina,
        'numero_serie' => (string) $maquina['numero_serie'],
        'permitir_reactivacion' => $permitir,
    ]);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    JsonResponse::serverError($e, 'maquina_reactivacion');
}
