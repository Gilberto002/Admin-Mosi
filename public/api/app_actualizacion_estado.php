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

$id = filter_var($body['id_actualizacion'] ?? null, FILTER_VALIDATE_INT);
if (!$id) {
    JsonResponse::error('Falta id_actualizacion', 422);
}

$activa = isset($body['activa']) && in_array((string) $body['activa'], ['1', 'true', 'on'], true);
$obligatoria = isset($body['obligatoria']) && in_array((string) $body['obligatoria'], ['1', 'true', 'on'], true);
$fechaPublicacion = normalizarFechaPublicacionEstado($body['fecha_publicacion'] ?? null);

try {
    $pdo = Connection::get();
    AppActualizaciones::asegurarTablas($pdo);
    $canalServidor = AppActualizaciones::canalServidor($body['canal_app'] ?? null);
    $stmt = $pdo->prepare(
        "UPDATE vendingm.app_actualizaciones
         SET activa = :activa,
             obligatoria = :obligatoria,
             fecha_publicacion = :fecha_publicacion,
             fecha_actualizacion = SYSDATETIME()
         WHERE id_actualizacion = :id
           AND canal_app = :canal_app"
    );
    $stmt->execute([
        'activa' => $activa ? 1 : 0,
        'obligatoria' => $obligatoria ? 1 : 0,
        'fecha_publicacion' => $fechaPublicacion,
        'id' => $id,
        'canal_app' => $canalServidor,
    ]);

    if ($stmt->rowCount() === 0) {
        JsonResponse::error('Actualizacion no encontrada en este servidor', 404);
    }

    JsonResponse::ok(['id_actualizacion' => $id, 'activa' => $activa, 'obligatoria' => $obligatoria, 'fecha_publicacion' => $fechaPublicacion]);
} catch (\Throwable $e) {
    JsonResponse::serverError($e, 'app_actualizacion_estado');
}

function normalizarFechaPublicacionEstado(mixed $valor): ?string
{
    $texto = trim((string) ($valor ?? ''));
    if ($texto === '') {
        return null;
    }
    $texto = str_replace('T', ' ', $texto);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $texto)) {
        return $texto . ':00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $texto)) {
        return $texto;
    }
    JsonResponse::error('fecha_publicacion debe tener formato YYYY-MM-DD HH:mm', 422);
}
