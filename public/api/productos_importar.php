<?php
// public/api/productos_importar.php
// Recibe el Excel de carga masiva del catalogo de productos desde el boton
// "Cargar Excel" de productos.php y hace upsert por "clave" (Cuadro
// Basico/CIS) via App\Import\ProductosCatalogoImportador -- ver esa clase
// para el detalle de la logica. Mismo patron de subida que
// maquina_importar_stock.php (guardar a temp, procesar, borrar temp).
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Catalogo\ProductoCatalogo;
use App\Database\Connection;
use App\Import\ProductosCatalogoImportador;

set_time_limit(0);
ini_set('max_execution_time', '0');

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

function jsonError(string $mensaje, int $codigo = 400): never
{
    http_response_code($codigo);
    echo json_encode(['ok' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método no permitido.', 405);
}

if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    jsonError('No se recibió el archivo Excel (o hubo un error al subirlo).');
}

$archivo = $_FILES['archivo'];
$extension = strtolower((string) pathinfo($archivo['name'], PATHINFO_EXTENSION));
if (!in_array($extension, ['xlsx', 'xls'], true)) {
    jsonError('El archivo debe ser .xlsx o .xls.');
}

$tamanoMaximo = 15 * 1024 * 1024; // 15 MB
if ($archivo['size'] > $tamanoMaximo) {
    jsonError('El archivo es demasiado grande (máx. 15 MB).');
}

$rutaTemp = sys_get_temp_dir() . '/productos_' . bin2hex(random_bytes(8)) . '.' . $extension;
if (!move_uploaded_file($archivo['tmp_name'], $rutaTemp)) {
    jsonError('No se pudo guardar el archivo subido.', 500);
}

try {
    $pdo = Connection::get();
    ProductoCatalogo::asegurarColumnas($pdo);

    $importador = new ProductosCatalogoImportador($pdo);
    $resultado = $importador->importar($rutaTemp);

    echo json_encode(['ok' => true, 'data' => $resultado], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    jsonError(\App\Security\Security::publicError($e, 'api'), 500);
} finally {
    if (file_exists($rutaTemp)) {
        @unlink($rutaTemp);
    }
}
