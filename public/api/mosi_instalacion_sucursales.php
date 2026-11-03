<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Http\JsonResponse;
use App\Maquinas\ActivacionMaquina;
use App\Maquinas\TecnicoAndroid;
use App\Security\Security;

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) JsonResponse::error('Metodo no permitido', 405);
try {
    $pdo = Connection::get();
    if (!TecnicoAndroid::autenticar($pdo)) JsonResponse::error('Sesion de instalador invalida o vencida', 401);
    $q = trim((string) ($_GET['q'] ?? ''));
    $idCliente = trim((string) ($_GET['id_cliente'] ?? ''));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (is_array($body)) {
            $q = trim((string) ($body['q'] ?? $q));
            $idCliente = trim((string) ($body['id_cliente'] ?? $idCliente));
        }
    }
    if (strlen($q) > 200) JsonResponse::error('Busqueda demasiado larga', 422);
    if ($idCliente === '') JsonResponse::error('Cliente obligatorio', 422);
    JsonResponse::ok(ActivacionMaquina::buscarSucursales($pdo, $q, 50, $idCliente));
} catch (Throwable $e) {
    JsonResponse::error(Security::publicError($e, 'mosi_instalacion_sucursales'), 500);
}
