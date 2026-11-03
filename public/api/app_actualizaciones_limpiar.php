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

if (($body['confirmacion'] ?? '') !== 'LIMPIAR_APKS') {
    JsonResponse::error('Confirmacion invalida', 422);
}

try {
    $pdo = Connection::get();
    AppActualizaciones::asegurarTablas($pdo);
    $canalServidor = AppActualizaciones::canalServidor($body['canal_app'] ?? null);

    $stmtArchivos = $pdo->prepare(
        'SELECT apk_archivo, apk_url FROM vendingm.app_actualizaciones WHERE canal_app = :canal_app'
    );
    $stmtArchivos->execute(['canal_app' => $canalServidor]);
    $archivosCanal = $stmtArchivos->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();
    $stmtEventos = $pdo->prepare("SELECT COUNT(*) FROM vendingm.app_actualizaciones_eventos WHERE COALESCE(canal_app, 'cliente') = :canal_app");
    $stmtEventos->execute(['canal_app' => $canalServidor]);
    $eventos = (int) $stmtEventos->fetchColumn();
    $stmtActualizaciones = $pdo->prepare('SELECT COUNT(*) FROM vendingm.app_actualizaciones WHERE canal_app = :canal_app');
    $stmtActualizaciones->execute(['canal_app' => $canalServidor]);
    $actualizaciones = (int) $stmtActualizaciones->fetchColumn();
    $stmtBorrarEventos = $pdo->prepare("DELETE FROM vendingm.app_actualizaciones_eventos WHERE COALESCE(canal_app, 'cliente') = :canal_app");
    $stmtBorrarEventos->execute(['canal_app' => $canalServidor]);
    $stmtBorrarActualizaciones = $pdo->prepare('DELETE FROM vendingm.app_actualizaciones WHERE canal_app = :canal_app');
    $stmtBorrarActualizaciones->execute(['canal_app' => $canalServidor]);
    $pdo->commit();

    $directorio = dirname(__DIR__) . '/uploads/apks';
    $archivosBorrados = 0;
    if (is_dir($directorio)) {
        foreach ($archivosCanal as $fila) {
            $nombre = trim((string) ($fila['apk_archivo'] ?? ''));
            if ($nombre === '') {
                $nombre = basename((string) (parse_url((string) ($fila['apk_url'] ?? ''), PHP_URL_PATH) ?: ''));
            }
            $nombre = basename($nombre);
            if ($nombre === '' || strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'apk') {
                continue;
            }
            $archivo = $directorio . '/' . $nombre;
            if (is_file($archivo) && @unlink($archivo)) {
                $archivosBorrados++;
            }
        }
    }

    JsonResponse::ok([
        'actualizaciones_borradas' => $actualizaciones,
        'eventos_borrados' => $eventos,
        'archivos_apk_borrados' => $archivosBorrados,
    ]);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    JsonResponse::serverError($e, 'app_actualizaciones_limpiar');
}
