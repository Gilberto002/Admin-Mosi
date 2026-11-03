<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Carrito\CarritoCompras;
use App\Config\Features;
use App\Database\Connection;
use RuntimeException;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

if (!Features::enabled('carrito_compras')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Modulo de carrito desactivado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$metodo = $_SERVER['REQUEST_METHOD'];
$accion = (string) ($_GET['accion'] ?? 'estado');

try {
    $pdo = Connection::get();

    if ($metodo === 'GET') {
        echo json_encode(['ok' => true, 'data' => CarritoCompras::obtener($pdo)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Metodo no permitido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'JSON invalido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($accion === 'seleccionar_maquina') {
        $idMaquina = filter_var($body['id_maquina'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $data = CarritoCompras::seleccionarMaquina($pdo, $idMaquina);
    } elseif ($accion === 'agregar') {
        $idMaquina = filter_var($body['id_maquina'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $numeroCarril = filter_var($body['numero_carril'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $cantidad = filter_var($body['cantidad'] ?? 1, FILTER_VALIDATE_INT) ?: 1;
        $data = CarritoCompras::agregar($pdo, $idMaquina, $numeroCarril, $cantidad);
    } elseif ($accion === 'actualizar') {
        $numeroCarril = filter_var($body['numero_carril'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $cantidad = filter_var($body['cantidad'] ?? null, FILTER_VALIDATE_INT);
        $data = CarritoCompras::actualizar($pdo, $numeroCarril, $cantidad === false ? 0 : $cantidad);
    } elseif ($accion === 'quitar') {
        $numeroCarril = filter_var($body['numero_carril'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $data = CarritoCompras::quitar($pdo, $numeroCarril);
    } elseif ($accion === 'limpiar') {
        $data = CarritoCompras::limpiar();
    } elseif ($accion === 'crear_receta') {
        $data = CarritoCompras::crearReceta(
            $pdo,
            trim((string) ($body['nombre_paciente'] ?? '')) ?: null,
            trim((string) ($body['medico_solicita'] ?? '')) ?: null
        );
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Accion no valida'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], JSON_UNESCAPED_UNICODE);
}
