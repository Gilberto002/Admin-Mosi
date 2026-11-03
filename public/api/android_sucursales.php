<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Api\AndroidApi;
use App\Database\Connection;
use App\Maquinas\ActivacionMaquina;

$body = leerBodySucursales();
$texto = trim((string) ($body['q'] ?? $_GET['q'] ?? ''));
$numeroSerie = trim((string) ($body['numero_serie'] ?? $_GET['numero_serie'] ?? ''));
if (strlen($texto) > 200) {
    AndroidApi::responder(['ok' => false, 'error' => 'La busqueda es demasiado larga'], 422);
}
if ($numeroSerie === '') {
    AndroidApi::responder(['ok' => false, 'error' => 'Falta numero_serie'], 422);
}

try {
    $pdo = Connection::get();
    ActivacionMaquina::asegurarColumnas($pdo);
    validarSerieActivacion($pdo, $numeroSerie);

    $sucursales = ActivacionMaquina::buscarSucursales($pdo, $texto, 20);
    AndroidApi::responder([
        'ok' => true,
        'data' => $sucursales,
        'meta' => [
            'q' => $texto,
            'solo_activas' => true,
            'total' => count($sucursales),
        ],
    ]);
} catch (\Throwable $e) {
    AndroidApi::responder(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'android_sucursales')], 500);
}

function leerBodySucursales(): array
{
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        return [];
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        AndroidApi::responder(['ok' => false, 'error' => 'Metodo no permitido'], 405);
    }

    $raw = file_get_contents('php://input');
    $json = json_decode((string) $raw, true);
    if (is_array($json)) {
        return $json;
    }

    return $_POST ?: [];
}

function validarSerieActivacion(PDO $pdo, string $numeroSerie): void
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM vendingm.maquinas
         WHERE numero_serie = :numero_serie
           AND ISNULL(id_estatus, 1) <> 3'
    );
    $stmt->execute(['numero_serie' => $numeroSerie]);
    if (!$stmt->fetchColumn()) {
        AndroidApi::responder([
            'ok' => false,
            'error' => 'La maquina no esta preregistrada o esta fuera de servicio',
        ], 404);
    }
}
