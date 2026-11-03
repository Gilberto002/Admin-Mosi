<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Actualizaciones\AppActualizaciones;
use App\Actualizaciones\ApkInspector;
use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::error('Metodo no permitido', 405);
}

$versionCodeManual = filter_var($_POST['version_code'] ?? null, FILTER_VALIDATE_INT);
$versionNameManual = trim((string) ($_POST['version_name'] ?? ''));
$obligatoria = isset($_POST['obligatoria']) && in_array((string) $_POST['obligatoria'], ['1', 'true', 'on'], true);
$activa = !isset($_POST['activa']) || in_array((string) $_POST['activa'], ['1', 'true', 'on'], true);
$canalApp = AppActualizaciones::canalServidor($_POST['canal_app'] ?? null);
$fechaPublicacion = normalizarFechaPublicacion($_POST['fecha_publicacion'] ?? null);
$notas = trim((string) ($_POST['notas'] ?? '')) ?: null;

if (!isset($_FILES['apk']) || $_FILES['apk']['error'] !== UPLOAD_ERR_OK) {
    JsonResponse::error('No se recibio el APK', 422);
}

$archivo = $_FILES['apk'];
$extension = strtolower((string) pathinfo((string) $archivo['name'], PATHINFO_EXTENSION));
if ($extension !== 'apk') {
    JsonResponse::error('El archivo debe tener extension .apk', 422);
}

$tamanoMaximo = 250 * 1024 * 1024;
if ((int) $archivo['size'] <= 0 || (int) $archivo['size'] > $tamanoMaximo) {
    JsonResponse::error('El APK excede el limite permitido de 250 MB', 422);
}

$directorio = dirname(__DIR__) . '/uploads/apks';
if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) {
    JsonResponse::error('No se pudo preparar el directorio de APKs', 500);
}

$rutaDestino = $directorio . '/upload-' . bin2hex(random_bytes(8)) . '.apk';
if (!move_uploaded_file($archivo['tmp_name'], $rutaDestino)) {
    JsonResponse::error('No se pudo guardar el APK subido', 500);
}

$sha256 = hash_file('sha256', $rutaDestino);
if ($sha256 === false) {
    @unlink($rutaDestino);
    JsonResponse::error('No se pudo calcular el SHA-256 del APK', 500);
}

try {
    $metadataApk = ApkInspector::inspeccionar($rutaDestino);
} catch (\Throwable $e) {
    @unlink($rutaDestino);
    JsonResponse::error($e->getMessage(), 422);
}

$versionCode = (int) $metadataApk['version_code'];
$versionName = (string) $metadataApk['version_name'];
if ($versionCodeManual !== false && $versionCodeManual !== null && (int) $versionCodeManual !== $versionCode) {
    @unlink($rutaDestino);
    JsonResponse::error("El version_code capturado ({$versionCodeManual}) no coincide con el APK ({$versionCode}).", 422);
}
if ($versionNameManual !== '' && $versionNameManual !== $versionName) {
    @unlink($rutaDestino);
    JsonResponse::error("El version_name capturado ({$versionNameManual}) no coincide con el APK ({$versionName}).", 422);
}
if (strlen($versionName) > 50) {
    @unlink($rutaDestino);
    JsonResponse::error('version_name del APK excede 50 caracteres', 422);
}

$nombreArchivo = sprintf('medical-life-%s-v%s-%d-%s.apk', $canalApp, preg_replace('/[^A-Za-z0-9._-]/', '-', $versionName), $versionCode, bin2hex(random_bytes(4)));
$rutaFinal = $directorio . '/' . $nombreArchivo;
if (!rename($rutaDestino, $rutaFinal)) {
    @unlink($rutaDestino);
    JsonResponse::error('No se pudo preparar el nombre final del APK', 500);
}
$rutaDestino = $rutaFinal;
$apkUrlTemporal = publicUrl('/uploads/apks/' . $nombreArchivo);

try {
    $pdo = Connection::get();
    AppActualizaciones::asegurarTablas($pdo);
    $stmtUltima = $pdo->prepare("SELECT TOP 1 version_code FROM vendingm.app_actualizaciones WHERE canal_app = :canal_app ORDER BY version_code DESC, fecha DESC");
    $stmtUltima->execute(['canal_app' => $canalApp]);
    $ultimaVersionCode = $stmtUltima->fetchColumn();
    if ($ultimaVersionCode !== false && $versionCode <= (int) $ultimaVersionCode) {
        @unlink($rutaDestino);
        JsonResponse::error("El APK trae version_code {$versionCode}, pero la ultima publicada es {$ultimaVersionCode}. Sube una version mayor.", 422);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO vendingm.app_actualizaciones
         (canal_app, version_code, version_name, apk_url, apk_archivo, sha256, obligatoria, activa, fecha_publicacion, notas)
         OUTPUT INSERTED.id_actualizacion
         VALUES (:canal_app, :version_code, :version_name, :apk_url, :apk_archivo, :sha256, :obligatoria, :activa, :fecha_publicacion, :notas)"
    );
    $stmt->execute([
        'canal_app' => $canalApp,
        'version_code' => $versionCode,
        'version_name' => $versionName,
        'apk_url' => $apkUrlTemporal,
        'apk_archivo' => $nombreArchivo,
        'sha256' => $sha256,
        'obligatoria' => $obligatoria ? 1 : 0,
        'activa' => $activa ? 1 : 0,
        'fecha_publicacion' => $fechaPublicacion,
        'notas' => $notas,
    ]);
    $idActualizacion = (int) $stmt->fetchColumn();
    $apkUrl = AppActualizaciones::urlDescarga($idActualizacion, $canalApp);
    $stmtUrl = $pdo->prepare(
        "UPDATE vendingm.app_actualizaciones
         SET apk_url = :apk_url
         WHERE id_actualizacion = :id"
    );
    $stmtUrl->execute([
        'apk_url' => $apkUrl,
        'id' => $idActualizacion,
    ]);

    JsonResponse::ok([
        'id_actualizacion' => $idActualizacion,
        'canal_app' => $canalApp,
        'version_code' => $versionCode,
        'version_name' => $versionName,
        'apk_url' => $apkUrl,
        'sha256' => $sha256,
    ], 201);
} catch (\Throwable $e) {
    @unlink($rutaDestino);
    JsonResponse::serverError($e, 'app_actualizacion_subir');
}

function publicUrl(string $rutaPublica): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api')), '/');
    $base = preg_replace('#/api$#', '', $base);

    return ($https ? 'https://' : 'http://') . $host . $base . $rutaPublica;
}

function normalizarFechaPublicacion(mixed $valor): ?string
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
