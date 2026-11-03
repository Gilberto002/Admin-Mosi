<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Actualizaciones\AppActualizaciones;
use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::error('Metodo no permitido', 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

$tipo = trim((string) ($body['tipo_comando'] ?? ''));
$confirmacion = trim((string) ($body['confirmacion'] ?? ''));

if ($tipo !== AppActualizaciones::COMANDO_LIMPIAR_APKS_DISPOSITIVO) {
    JsonResponse::error('Comando no permitido', 422);
}

if ($confirmacion !== 'LIMPIAR_APKS_MAQUINAS') {
    JsonResponse::error('Confirmacion invalida', 422);
}

try {
    $pdo = Connection::get();
    AppActualizaciones::asegurarTablas($pdo);

    $usuario = Auth::user();
    $creadoPor = $usuario['email'] ?? $usuario['nombre'] ?? 'admin';

    $creados = AppActualizaciones::crearComandoTodasMaquinas(
        $pdo,
        AppActualizaciones::COMANDO_LIMPIAR_APKS_DISPOSITIVO,
        [
            'accion' => 'borrar_apks_descargados',
            'limpiar_estado_actualizacion' => true,
            'nota' => 'Orden generada desde panel MDM',
        ],
        (string) $creadoPor
    );

    JsonResponse::ok([
        'tipo_comando' => AppActualizaciones::COMANDO_LIMPIAR_APKS_DISPOSITIVO,
        'comandos_creados' => $creados,
    ]);
} catch (\Throwable $e) {
    JsonResponse::serverError($e, 'app_comandos_android_crear');
}
