<?php
// public/api/maquina_importar_stock.php
// Recibe un Excel de planimetría/stock desde el modal "Cargar stock" de admin.php
// y ejecuta el mismo flujo que scripts/importar_planimetria.php.
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Auth\Auth;
use App\Import\PlanimetriaImportador;

// Una carga completa de Excel puede disparar cientos de traspasos reales
// (cada uno con varias sentencias SQL: FIFO, folio, salida, clon/entrada,
// detalle, sync de precio), fácilmente más que el límite por defecto de
// PHP -- se sube al máximo posible SOLO en este endpoint puntual (no
// globalmente, para no dejar sin límite al resto del sitio por accidente).
set_time_limit(0);
ini_set('max_execution_time', '0');

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

function jsonError(string $mensaje, int $codigo = 400): never
{
    http_response_code($codigo);
    echo json_encode(['ok' => false, 'error' => $mensaje]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método no permitido.', 405);
}

$numeroSerial = trim((string) ($_POST['numero_serie'] ?? ''));
if ($numeroSerial === '') {
    jsonError('Falta el número de serie de la máquina principal.');
}

if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    jsonError('No se recibió el archivo Excel (o hubo un error al subirlo).');
}

$archivo   = $_FILES['archivo'];
$extension = strtolower((string) pathinfo($archivo['name'], PATHINFO_EXTENSION));
if (!in_array($extension, ['xlsx', 'xls'], true)) {
    jsonError('El archivo debe ser .xlsx o .xls.');
}

$tamanoMaximo = 15 * 1024 * 1024; // 15 MB
if ($archivo['size'] > $tamanoMaximo) {
    jsonError('El archivo es demasiado grande (máx. 15 MB).');
}

$rutaTemp = sys_get_temp_dir() . '/planimetria_' . bin2hex(random_bytes(8)) . '.' . $extension;
if (!move_uploaded_file($archivo['tmp_name'], $rutaTemp)) {
    jsonError('No se pudo guardar el archivo subido.', 500);
}

$sesion = Auth::user();
$usuario = $sesion ? (string) $sesion['email'] : 'admin-web';
$idOperador = Auth::id();

try {
    $pdo        = Connection::get();
    $importador = new PlanimetriaImportador($pdo);
    $resultado  = $importador->importar($rutaTemp, $numeroSerial, $usuario, $idOperador);

    echo json_encode(['ok' => true, 'data' => $resultado]);
} catch (\PDOException $e) {
    // Error real de servidor/BD -- PDOException hereda de RuntimeException,
    // así que este catch tiene que ir ANTES para no mostrarlo como si fuera
    // un mensaje de validación de negocio.
    jsonError(\App\Security\Security::publicError($e, 'api'), 500);
} catch (\RuntimeException $e) {
    // Validación de negocio (Excel inválido -- ExcelColumnasFaltantesException
    // incluida --, máquina sin sucursal vinculada, serial inexistente, etc.)
    // -- mensaje claro para el admin, no el genérico "Ocurrió un error
    // interno" que antes tapaba estos casos también. Bug real detectado
    // 2026-09-10 al implementar la validación de columnas del Excel.
    jsonError($e->getMessage(), 422);
} catch (\Throwable $e) {
    jsonError(\App\Security\Security::publicError($e, 'api'), 500);
} finally {
    if (file_exists($rutaTemp)) {
        @unlink($rutaTemp);
    }
}
