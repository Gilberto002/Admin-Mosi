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
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (is_array($body)) $q = trim((string) ($body['q'] ?? $q));
    }
    if (is_array($body ?? null) && ($body['diagnostico_catalogo'] ?? false) === true) {
        $hallazgos = [];
        $bases = $pdo->query("SELECT name FROM sys.databases WHERE HAS_DBACCESS(name)=1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($bases as $base) {
            $baseSeguro = str_replace(']', ']]', (string) $base);
            try {
                $sql = "SELECT DB_NAME() AS base_actual, s.name AS esquema, t.name AS tabla
                        FROM [{$baseSeguro}].sys.tables t
                        INNER JOIN [{$baseSeguro}].sys.schemas s ON s.schema_id=t.schema_id
                        WHERE LOWER(t.name) IN ('cat_clientes','cat_sucursalclinica')";
                foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                    $fila['base'] = (string) $base;
                    unset($fila['base_actual']);
                    $hallazgos[] = $fila;
                }
            } catch (Throwable) {
                continue;
            }
        }
        JsonResponse::ok($hallazgos);
    }
    if (strlen($q) > 200) JsonResponse::error('Busqueda demasiado larga', 422);
    JsonResponse::ok(ActivacionMaquina::buscarClientes($pdo, $q, 100));
} catch (Throwable $e) {
    JsonResponse::error(Security::publicError($e, 'mosi_instalacion_clientes'), 500);
}
