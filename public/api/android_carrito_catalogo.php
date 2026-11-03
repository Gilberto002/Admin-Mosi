<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Api\AndroidApi;
use App\Catalogo\ProductoCatalogo;
use App\Database\Connection;

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    AndroidApi::responder(['ok' => false, 'error' => 'Metodo no permitido'], 405);
}

$body = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? AndroidApi::body()
    : [
        'id_maquina' => filter_input(INPUT_GET, 'id_maquina', FILTER_VALIDATE_INT) ?: null,
        'numero_serie' => trim((string) ($_GET['numero_serie'] ?? '')),
        'id_maquina_auth' => filter_input(INPUT_GET, 'id_maquina_auth', FILTER_VALIDATE_INT) ?: null,
        'numero_serie_auth' => trim((string) ($_GET['numero_serie_auth'] ?? '')),
    ];

try {
    $pdo = Connection::get();
    ProductoCatalogo::asegurarColumnas($pdo);
    $authBody = $body;
    if (!empty($body['id_maquina_auth']) || !empty($body['numero_serie_auth'])) {
        $authBody['id_maquina'] = $body['id_maquina_auth'] ?? null;
        $authBody['numero_serie'] = $body['numero_serie_auth'] ?? '';
    }
    $maquinaAuth = AndroidApi::autenticar($pdo, $authBody);

    $idMaquina = filter_var($body['id_maquina'] ?? null, FILTER_VALIDATE_INT) ?: (int) ($maquinaAuth['id_maquina'] ?? 0);
    if (!$idMaquina) {
        AndroidApi::responder(['ok' => false, 'error' => 'Falta id_maquina'], 422);
    }

    $stmtM = $pdo->prepare(
        "SELECT id_maquina, numero_serie, nombre_ubicacion, rol_maquina, id_maquina_padre
         FROM vendingm.maquinas
         WHERE id_maquina = :id"
    );
    $stmtM->execute(['id' => $idMaquina]);
    $maquina = $stmtM->fetch(PDO::FETCH_ASSOC);
    if (!$maquina) {
        AndroidApi::responder(['ok' => false, 'error' => 'Maquina no encontrada'], 404);
    }
    if ($maquinaAuth) {
        $unidadAuth = ((int) $maquinaAuth['rol_maquina'] === 2 && $maquinaAuth['id_maquina_padre'] !== null)
            ? (int) $maquinaAuth['id_maquina_padre']
            : (int) $maquinaAuth['id_maquina'];
        $stmtUnidadTarget = $pdo->prepare(
            "SELECT CASE WHEN rol_maquina = 2 AND id_maquina_padre IS NOT NULL THEN id_maquina_padre ELSE id_maquina END
             FROM vendingm.maquinas
             WHERE id_maquina = :id"
        );
        $stmtUnidadTarget->execute(['id' => $idMaquina]);
        if ($unidadAuth !== (int) $stmtUnidadTarget->fetchColumn()) {
            AndroidApi::responder(['ok' => false, 'error' => 'El token no pertenece a esta unidad'], 403);
        }
    }

    $idUnidad = ((int) $maquina['rol_maquina'] === 2 && $maquina['id_maquina_padre'] !== null)
        ? (int) $maquina['id_maquina_padre']
        : (int) $maquina['id_maquina'];

    $stmt = $pdo->prepare(
        "SELECT m.id_maquina, m.numero_serie AS numero_serie_maquina, m.rol_maquina,
                p.id_producto, ca.numero_carril, p.nombre, p.presentacion, p.descripcion, p.precio,
                p.imagen_path, ca.cantidad_actual AS stock, p.codigo_barras,
                l.numero_lote, l.fecha_caducidad
         FROM vendingm.carriles ca
         INNER JOIN vendingm.maquinas m ON m.id_maquina = ca.id_maquina
         INNER JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
         INNER JOIN vendingm.productos p ON p.id_producto = l.id_producto
         WHERE (m.id_maquina = :id_unidad OR m.id_maquina_padre = :id_unidad_padre)
           AND ca.numero_carril % 10 <> 0
           AND ca.cantidad_actual > 0
           AND ISNULL(p.activo, 1) = 1
         ORDER BY p.nombre, CASE WHEN m.rol_maquina = 1 THEN 0 ELSE 1 END, m.numero_serie, ca.numero_carril"
    );
    $stmt->execute(['id_unidad' => $idUnidad, 'id_unidad_padre' => $idUnidad]);
    $productos = array_map(static function (array $p): array {
        return [
            'id_maquina' => (int) $p['id_maquina'],
            'numero_serie_maquina' => (string) $p['numero_serie_maquina'],
            'rol_maquina' => (int) $p['rol_maquina'],
            'id_producto' => (int) $p['id_producto'],
            'numero_carril' => (int) $p['numero_carril'],
            'clave_carril' => ((int) $p['id_maquina']) . ':' . ((int) $p['numero_carril']),
            'nombre' => (string) $p['nombre'],
            'descripcion' => $p['descripcion'] ?: ($p['presentacion'] ?: null),
            'precio' => $p['precio'] !== null ? (float) $p['precio'] : null,
            'stock' => (int) $p['stock'],
            'imagen_url' => ProductoCatalogo::urlPublica($p['imagen_path'] ?? null),
            'codigo_barras' => $p['codigo_barras'] ?: null,
            'numero_lote' => $p['numero_lote'] ?: null,
            'fecha_caducidad' => $p['fecha_caducidad'] ?: null,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    AndroidApi::responder([
        'ok' => true,
        'data' => [
            'maquina' => [
                'id_maquina' => (int) $maquina['id_maquina'],
                'numero_serie' => (string) $maquina['numero_serie'],
                'nombre' => $maquina['nombre_ubicacion'] ?: null,
                'id_unidad' => $idUnidad,
            ],
            'productos' => $productos,
            'reglas_stock' => [
                'fuente' => 'carril',
                'reserva_pedidos_pendientes' => false,
                'descuento' => 'al_finalizar_surtido',
            ],
        ],
    ]);
} catch (\Throwable $e) {
    AndroidApi::responder(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], 500);
}
