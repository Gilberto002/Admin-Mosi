<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Actualizaciones\AppActualizaciones;
use App\Auth\Auth;
use App\Database\Connection;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
    exit;
}

$id = filter_input(INPUT_GET, 'id_actualizacion', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Falta id_actualizacion']);
    exit;
}

try {
    $pdo = Connection::get();
    AppActualizaciones::asegurarTablas($pdo);
    $canalApp = AppActualizaciones::normalizarCanal($_GET['canal_app'] ?? null);
    if ($canalApp === AppActualizaciones::CANAL_MOSI) {
        Auth::requireAdmin();
    }
    $stmt = $pdo->prepare(
        "SELECT id_actualizacion, canal_app, version_code, version_name, apk_url, apk_archivo, sha256, activa
         FROM vendingm.app_actualizaciones
         WHERE id_actualizacion = :id
           AND canal_app = :canal_app"
    );
    $stmt->execute(['id' => $id, 'canal_app' => $canalApp]);
    $actualizacion = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$actualizacion || !(bool) $actualizacion['activa']) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'APK no disponible']);
        exit;
    }

    $archivo = trim((string) ($actualizacion['apk_archivo'] ?? ''));
    if ($archivo === '') {
        $path = parse_url((string) ($actualizacion['apk_url'] ?? ''), PHP_URL_PATH) ?: '';
        $archivo = basename($path);
    }
    $archivo = basename($archivo);
    if ($archivo === '' || strtolower(pathinfo($archivo, PATHINFO_EXTENSION)) !== 'apk') {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Archivo APK invalido']);
        exit;
    }

    $ruta = dirname(__DIR__) . '/uploads/apks/' . $archivo;
    if (!is_file($ruta) || !is_readable($ruta)) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Archivo APK no encontrado']);
        exit;
    }

    $tamano = filesize($ruta);
    if ($tamano === false || $tamano <= 0) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Archivo APK vacio']);
        exit;
    }

    // IIS/PHP puede mantener un buffer de salida. readfile() termina intentando
    // reservar en memoria el APK completo y falla con paquetes mayores a 128 MB.
    // Cerramos cualquier buffer y transmitimos en bloques constantes.
    @set_time_limit(0);
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Disposition: attachment; filename="' . addcslashes($archivo, '"\\') . '"');
    header('Content-Length: ' . $tamano);
    header('X-APK-SHA256: ' . $actualizacion['sha256']);
    header('Cache-Control: private, max-age=0, must-revalidate');

    $stream = fopen($ruta, 'rb');
    if ($stream === false) {
        throw new RuntimeException('No se pudo abrir el APK para descarga');
    }
    try {
        while (!feof($stream)) {
            $bloque = fread($stream, 1024 * 1024);
            if ($bloque === false) {
                throw new RuntimeException('No se pudo leer el APK');
            }
            echo $bloque;
            flush();
            if (connection_aborted()) {
                break;
            }
        }
    } finally {
        fclose($stream);
    }
    exit;
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')]);
}
