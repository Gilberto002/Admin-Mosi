<?php
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$idMaquina = filter_input(INPUT_POST, 'id_maquina', FILTER_VALIDATE_INT);
$usuario = trim((string) ($_POST['usuario'] ?? 'admin'));

if (!$idMaquina) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta id_maquina']);
    exit;
}

if (!isset($_FILES['excel']) || $_FILES['excel']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Archivo Excel faltante o inválido']);
    exit;
}

try {
    $pdo = Connection::get();

    $stmt = $pdo->prepare(
        'SELECT m.numero_serie, m.rol_maquina, p.numero_serie AS numero_serie_padre
         FROM vendingm.maquinas m
         LEFT JOIN vendingm.maquinas p ON p.id_maquina = m.id_maquina_padre
         WHERE m.id_maquina = :id'
    );
    $stmt->execute(['id' => $idMaquina]);
    $maquina = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$maquina) {
        throw new \RuntimeException('Máquina no encontrada.');
    }

    $numeroSerialPrincipal = (int) $maquina['rol_maquina'] === 2
        ? (string) ($maquina['numero_serie_padre'] ?? '')
        : (string) $maquina['numero_serie'];

    if ($numeroSerialPrincipal === '') {
        throw new \RuntimeException('La máquina esclava no tiene una principal vinculada.');
    }

    $resultado = (new PlanimetriaImportador($pdo))->importar(
        $_FILES['excel']['tmp_name'],
        $numeroSerialPrincipal,
        $usuario,
        Auth::id()
    );

    echo json_encode([
        'ok' => true,
        'data' => [
            'id_reabastecimiento' => $resultado['id_reabastecimiento'],
            'filas_leidas'        => $resultado['filas_leidas'],
            'sin_fecha_caducidad' => $resultado['sin_fecha'],
            'sin_carril'          => $resultado['sin_carril'],
            'sin_traspaso'        => $resultado['sin_traspaso'],
            // Se devolvían solo desde maquina_importar_stock.php, así que
            // esta pantalla no tenía cómo avisar que un carril lleno dejó
            // la carga en cero ni ofrecer ampliar su capacidad. El usuario
            // veía "Carga completada" con cero piezas movidas.
            'capacidad_ajustada'  => $resultado['capacidad_ajustada'],
            'capacidad_pendiente' => $resultado['capacidad_pendiente'],
            'carril_lleno'        => $resultado['carril_lleno'],
        ],
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')]);
}
