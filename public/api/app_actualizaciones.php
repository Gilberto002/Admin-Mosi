<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Actualizaciones\AppActualizaciones;
use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;

Auth::requireAdmin();

try {
    $pdo = Connection::get();
    AppActualizaciones::asegurarTablas($pdo);

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        JsonResponse::error('Metodo no permitido', 405);
    }

    $canalServidor = AppActualizaciones::canalServidor($_GET['canal_app'] ?? null);
    $stmt = $pdo->prepare(
        "SELECT TOP 100 id_actualizacion, canal_app, version_code, version_name, apk_url, apk_archivo, sha256,
                obligatoria, activa, fecha_publicacion, notas, fecha
         FROM vendingm.app_actualizaciones
         WHERE canal_app = :canal_app
         ORDER BY version_code DESC, fecha DESC"
    );
    $stmt->execute(['canal_app' => $canalServidor]);
    $actualizaciones = array_map(
        static function (array $row): array {
            $apkUrlOriginal = (string) ($row['apk_url'] ?? '');
            $row = AppActualizaciones::normalizarActualizacion($row);
            $archivo = trim((string) ($row['apk_archivo'] ?? ''));
            if ($archivo === '') {
                $path = parse_url($apkUrlOriginal, PHP_URL_PATH) ?: '';
                $archivo = basename($path);
            }
            $ruta = dirname(__DIR__) . '/uploads/apks/' . basename($archivo);
            $row['apk_archivo_existe'] = $archivo !== '' && is_file($ruta);
            $row['apk_archivo_tamano'] = $row['apk_archivo_existe'] ? filesize($ruta) : null;
            return $row;
        },
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );

    $stmtResumen = $pdo->prepare(
        "SELECT COALESCE(canal_app, 'cliente') AS canal_app, tipo_evento, COUNT(*) AS total
         FROM vendingm.app_actualizaciones_eventos
         WHERE fecha >= DATEADD(day, -30, SYSDATETIME())
           AND COALESCE(canal_app, 'cliente') = :canal_app
         GROUP BY COALESCE(canal_app, 'cliente'), tipo_evento"
    );
    $stmtResumen->execute(['canal_app' => $canalServidor]);
    $resumen = [];
    foreach ($stmtResumen->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $resumen[(string) $row['canal_app'] . ':' . (string) $row['tipo_evento']] = (int) $row['total'];
    }

    JsonResponse::ok([
        'actualizaciones' => $actualizaciones,
        'resumen_30_dias' => $resumen,
    ]);
} catch (\Throwable $e) {
    JsonResponse::serverError($e, 'app_actualizaciones');
}
