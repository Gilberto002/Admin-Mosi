<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;
use App\Telemedicina\MosiApi;

Auth::requireAdmin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') JsonResponse::error('Metodo no permitido', 405);

try {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) JsonResponse::error('JSON invalido', 400);
    $idMosi = filter_var($body['id_mosi'] ?? null, FILTER_VALIDATE_INT);
    if (!$idMosi) JsonResponse::error('id_mosi es obligatorio', 422);
    $horas = filter_var($body['horas'] ?? 24, FILTER_VALIDATE_INT);
    $data = MosiApi::crearCodigoActivacion(Connection::get(), (int) $idMosi, Auth::id() ?? 0, $horas === false ? 24 : (int) $horas);
    JsonResponse::ok($data, 201);
} catch (InvalidArgumentException|RuntimeException $e) {
    JsonResponse::error($e->getMessage(), 422);
} catch (Throwable $e) {
    JsonResponse::serverError($e, 'mosi_token_activacion');
}
