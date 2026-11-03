<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;
use App\Maquinas\PanelTecnicoAcceso;

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::error('Metodo no permitido', 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

$idMaquina = filter_var($body['id_maquina'] ?? null, FILTER_VALIDATE_INT);
if (!$idMaquina) {
    JsonResponse::error('Falta id_maquina', 422);
}

try {
    $pdo = Connection::get();
    $stmt = $pdo->prepare('SELECT id_maquina, numero_serie FROM vendingm.maquinas WHERE id_maquina = :id');
    $stmt->execute(['id' => $idMaquina]);
    $maquina = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$maquina) {
        JsonResponse::error('Maquina no encontrada', 404);
    }

    $credencial = PanelTecnicoAcceso::regenerarCredencial($pdo, (int) $idMaquina);
    JsonResponse::ok([
        'id_maquina' => (int) $idMaquina,
        'numero_serie' => (string) $maquina['numero_serie'],
        'password' => $credencial['password'],
        'nota' => 'Esta contrasena se muestra una sola vez. Android recibira el hash en android_maquina_config.php.',
    ]);
} catch (\Throwable $e) {
    JsonResponse::serverError($e, 'panel_tecnico_password');
}
