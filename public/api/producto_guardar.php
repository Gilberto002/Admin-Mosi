<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Auth\Auth;
use App\Http\JsonResponse;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido, use POST']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

$idProducto = filter_var($body['id_producto'] ?? null, FILTER_VALIDATE_INT) ?: null; // null = crear
$codigoBarras = trim((string) ($body['codigo_barras'] ?? ''));
$nombre = trim((string) ($body['nombre'] ?? ''));
$presentacion = trim((string) ($body['presentacion'] ?? '')) ?: null;
$sustanciaActiva = trim((string) ($body['sustancia_activa'] ?? '')) ?: null;
$idCategoria = filter_var($body['id_categoria'] ?? null, FILTER_VALIDATE_INT) ?: null;
$activo = filter_var($body['activo'] ?? true, FILTER_VALIDATE_BOOLEAN);

$errores = [];
if ($codigoBarras === '') {
    $errores[] = 'codigo_barras es obligatorio';
}
if ($nombre === '') {
    $errores[] = 'nombre es obligatorio';
}
if ($errores) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => implode('; ', $errores)]);
    exit;
}

try {
    $pdo = Connection::get();

    if ($idProducto === null) {
        $ins = $pdo->prepare(
            'INSERT INTO vendingm.productos
             (codigo_barras, nombre, presentacion, sustancia_activa, id_categoria, activo)
             OUTPUT INSERTED.id_producto
             VALUES (:codigo, :nombre, :presentacion, :sustancia, :categoria, :activo)'
        );
        $ins->execute([
            'codigo' => $codigoBarras,
            'nombre' => $nombre,
            'presentacion' => $presentacion,
            'sustancia' => $sustanciaActiva,
            'categoria' => $idCategoria,
            'activo' => $activo ? 1 : 0,
        ]);
        $idProducto = (int) $ins->fetchColumn();
        $accion = 'creado';
    } else {
        $upd = $pdo->prepare(
            'UPDATE vendingm.productos
             SET codigo_barras = :codigo, nombre = :nombre, presentacion = :presentacion,
                 sustancia_activa = :sustancia, id_categoria = :categoria, activo = :activo,
                 fecha_actualizacion = SYSDATETIME()
             WHERE id_producto = :id'
        );
        $upd->execute([
            'codigo' => $codigoBarras,
            'nombre' => $nombre,
            'presentacion' => $presentacion,
            'sustancia' => $sustanciaActiva,
            'categoria' => $idCategoria,
            'activo' => $activo ? 1 : 0,
            'id' => $idProducto,
        ]);
        if ($upd->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Producto no encontrado']);
            exit;
        }
        $accion = 'actualizado';
    }

    echo json_encode(['ok' => true, 'data' => ['id_producto' => $idProducto, 'accion' => $accion]]);
} catch (\PDOException $e) {
    // Violación de UQ_productos_codigo_barras (vendingm.productos.codigo_barras).
    if ($e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Ya existe un producto con ese código de barras']);
        exit;
    }
    JsonResponse::serverError($e, 'producto_guardar');
} catch (\Throwable $e) {
    JsonResponse::serverError($e, 'producto_guardar');
}
