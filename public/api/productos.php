<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Catalogo\ProductoCatalogo;
use App\Database\Connection;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

$metodo = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = Connection::get();
    ProductoCatalogo::asegurarColumnas($pdo);

    if ($metodo === 'GET') {
        $q = trim((string) ($_GET['q'] ?? ''));
        $soloActivos = ($_GET['activos'] ?? '') === '1';
        $params = [];
        $where = [];

        if ($q !== '') {
            $where[] = '(p.nombre LIKE :q1 OR p.codigo_barras LIKE :q2 OR p.presentacion LIKE :q3 OR p.descripcion LIKE :q4 OR p.clave LIKE :q5)';
            $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = $params['q5'] = "%{$q}%";
        }
        if ($soloActivos) {
            $where[] = 'p.activo = 1';
        }

        $stmt = $pdo->prepare(
            "SELECT TOP 500 p.id_producto, p.codigo_barras, p.clave, p.nombre, p.presentacion, p.descripcion,
                    p.precio, p.imagen_path, p.activo, c.nombre_categoria AS categoria
             FROM vendingm.productos p
             LEFT JOIN vendingm.categorias c ON c.id_categoria = p.id_categoria
             " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
             ORDER BY p.nombre"
        );
        $stmt->execute($params);
        $data = array_map(static function (array $p): array {
            $p['id_producto'] = (int) $p['id_producto'];
            $p['precio'] = $p['precio'] !== null ? (float) $p['precio'] : null;
            $p['activo'] = (bool) $p['activo'];
            $p['imagen_url'] = ProductoCatalogo::urlPublica($p['imagen_path'] ?? null);
            unset($p['imagen_path']);
            return $p;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Metodo no permitido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $id = filter_input(INPUT_POST, 'id_producto', FILTER_VALIDATE_INT) ?: null;
    $codigo = trim((string) ($_POST['codigo_barras'] ?? ''));
    $clave = trim((string) ($_POST['clave'] ?? '')) ?: null;
    $nombre = trim((string) ($_POST['nombre'] ?? ''));
    $presentacion = trim((string) ($_POST['presentacion'] ?? '')) ?: null;
    $descripcion = trim((string) ($_POST['descripcion'] ?? '')) ?: null;
    $precio = isset($_POST['precio']) && $_POST['precio'] !== '' ? (float) $_POST['precio'] : null;
    $activo = filter_var($_POST['activo'] ?? '1', FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    $eliminarImagen = ($_POST['eliminar_imagen'] ?? '') === '1';

    if ($codigo === '' || $nombre === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Codigo de barras y nombre son obligatorios'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($precio !== null && $precio < 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'El precio no puede ser negativo'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $imagenPath = null;
    if ($id) {
        $stmtActual = $pdo->prepare('SELECT imagen_path FROM vendingm.productos WHERE id_producto = :id');
        $stmtActual->execute(['id' => $id]);
        $imagenPath = $stmtActual->fetchColumn();
        if ($imagenPath === false) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Producto no encontrado'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if ($eliminarImagen) {
        $imagenPath = null;
    }
    if (isset($_FILES['imagen']) && is_array($_FILES['imagen']) && ($_FILES['imagen']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $imagenPath = ProductoCatalogo::guardarImagen($_FILES['imagen']);
    }

    if ($id) {
        $stmt = $pdo->prepare(
            "UPDATE vendingm.productos
             SET codigo_barras = :codigo, clave = :clave, nombre = :nombre, presentacion = :presentacion,
                 descripcion = :descripcion, precio = :precio, imagen_path = :imagen_path,
                 activo = :activo, fecha_actualizacion = SYSDATETIME()
             WHERE id_producto = :id"
        );
        $stmt->execute([
            'codigo' => $codigo,
            'clave' => $clave,
            'nombre' => $nombre,
            'presentacion' => $presentacion,
            'descripcion' => $descripcion,
            'precio' => $precio,
            'imagen_path' => $imagenPath,
            'activo' => $activo,
            'id' => $id,
        ]);
        $accion = 'actualizado';
    } else {
        $idCategoria = categoriaDefaultProducto($pdo);
        $stmt = $pdo->prepare(
            "INSERT INTO vendingm.productos
                (codigo_barras, clave, nombre, presentacion, descripcion, precio, imagen_path, activo, id_categoria)
             OUTPUT INSERTED.id_producto
             VALUES (:codigo, :clave, :nombre, :presentacion, :descripcion, :precio, :imagen_path, :activo, :id_categoria)"
        );
        $stmt->execute([
            'codigo' => $codigo,
            'clave' => $clave,
            'nombre' => $nombre,
            'presentacion' => $presentacion,
            'descripcion' => $descripcion,
            'precio' => $precio,
            'imagen_path' => $imagenPath,
            'activo' => $activo,
            'id_categoria' => $idCategoria,
        ]);
        $id = (int) $stmt->fetchColumn();
        $accion = 'creado';
    }

    echo json_encode(['ok' => true, 'data' => ['id_producto' => $id, 'accion' => $accion]], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], JSON_UNESCAPED_UNICODE);
}

function categoriaDefaultProducto(PDO $pdo): int
{
    foreach ([
        "SELECT TOP 1 id_categoria FROM vendingm.categorias_producto WHERE nombre = 'Sin clasificar'",
        "SELECT TOP 1 id_categoria FROM vendingm.categorias WHERE nombre_categoria = 'Sin clasificar'",
        "SELECT TOP 1 id_categoria FROM vendingm.categorias_producto ORDER BY id_categoria",
        "SELECT TOP 1 id_categoria FROM vendingm.categorias ORDER BY id_categoria",
    ] as $sql) {
        try {
            $id = $pdo->query($sql)->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        } catch (\Throwable) {
            continue;
        }
    }

    return 1;
}
