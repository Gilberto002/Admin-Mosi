<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Http\JsonResponse;
use App\Maquinas\TecnicoAndroid;
use App\Telemedicina\MosiApi;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') JsonResponse::error('Metodo no permitido', 405);
$raw = (string) file_get_contents('php://input');
if (strlen($raw) > 32768) JsonResponse::error('Solicitud demasiado grande', 413);
$body = json_decode($raw, true);
if (!is_array($body)) JsonResponse::error('JSON invalido', 400);

try {
    $pdo = Connection::get();
    $instalador = TecnicoAndroid::autenticar($pdo);
    if (!$instalador || empty(TecnicoAndroid::permisosPorRol((string) $instalador['rol'])['puede_activar_maquina'])) {
        JsonResponse::error('Sesion sin permiso para instalar MOSI', 403);
    }
    $serie = trim((string) ($body['numero_serie'] ?? ''));
    $idSucursal = (int) ($body['id_sucursal_clinica'] ?? 0);
    $config = is_array($body['configuracion'] ?? null) ? $body['configuracion'] : [];
    if ($serie === '' || $idSucursal <= 0) JsonResponse::error('Numero de serie y sucursal son obligatorios', 422);

    $data = MosiApi::activarPorInstalador($pdo, $serie, $idSucursal, (int) $instalador['id_usuario'], $body, $config);
    TecnicoAndroid::registrarEvento(
        $pdo, (int) $instalador['id_usuario'], null, $serie,
        'instalacion_mosi', 'ok', 'MOSI vinculada y configurada',
        trim((string) ($body['app_version'] ?? '')) ?: null,
        ['id_mosi' => $data['id_mosi'], 'id_sucursal_clinica' => $idSucursal]
    );
    $data['intervalo_sincronizacion'] = 300;
    $data['rutas'] = [
        'telemetria' => '/api/mosi_telemetria.php',
        'telemedicina_evento' => '/api/mosi_telemedicina_evento.php',
        'actualizacion_consultar' => '/api/mosi_actualizacion_consultar.php',
        'actualizacion_evento' => '/api/mosi_actualizacion_evento.php',
    ];
    JsonResponse::ok($data, 201);
} catch (RuntimeException $e) {
    JsonResponse::error($e->getMessage(), 422);
} catch (Throwable $e) {
    JsonResponse::serverError($e, 'mosi_instalacion_completar');
}
