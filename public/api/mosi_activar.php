<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Http\JsonResponse;
use App\Telemedicina\MosiApi;

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') JsonResponse::error('Metodo no permitido', 405);

try {
    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > 32768) JsonResponse::error('Solicitud demasiado grande', 413);
    $body = json_decode($raw, true);
    if (!is_array($body)) JsonResponse::error('JSON invalido', 400);
    $codigo = trim((string) ($body['codigo_activacion'] ?? ''));
    $serie = trim((string) ($body['numero_serie'] ?? ''));
    if ($codigo === '' || $serie === '') JsonResponse::error('codigo_activacion y numero_serie son obligatorios', 422);
    $data = MosiApi::activar(Connection::get(), $codigo, $serie, $body);
    $data['telemetria_url'] = 'api/mosi_telemetria.php';
    $data['actualizaciones_url'] = 'api/mosi_actualizacion_consultar.php';
    $data['intervalo_telemetria_segundos'] = 300;
    $data['intervalo_sincronizacion'] = 300;
    $data['rutas'] = [
        'telemetria' => '/api/mosi_telemetria.php',
        'telemedicina_evento' => '/api/mosi_telemedicina_evento.php',
        'actualizacion_consultar' => '/api/mosi_actualizacion_consultar.php',
        'actualizacion_evento' => '/api/mosi_actualizacion_evento.php',
    ];
    JsonResponse::ok($data, 201);
} catch (RuntimeException $e) {
    JsonResponse::error($e->getMessage(), 401);
} catch (Throwable $e) {
    JsonResponse::serverError($e, 'mosi_activar');
}
