<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Catalogo\ProductoCatalogo;
use App\Database\Connection;
use App\Vending\RecetaFolio;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Connection::get();
    ProductoCatalogo::asegurarColumnas($pdo);
    $pdo->exec("
        IF COL_LENGTH('vendingm.detalle_receta', 'id_maquina') IS NULL
            ALTER TABLE vendingm.detalle_receta ADD id_maquina INT NULL
    ");

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'JSON invalido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $idMaquina = filter_var($body['id_maquina'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $totalRecetas = min(100, max(1, filter_var($body['total_recetas'] ?? 100, FILTER_VALIDATE_INT) ?: 100));
    $minItemsPorReceta = min(10, max(1, filter_var($body['min_items_por_receta'] ?? 5, FILTER_VALIDATE_INT) ?: 5));
    $maxItemsPorReceta = min(10, max($minItemsPorReceta, filter_var($body['max_items_por_receta'] ?? 10, FILTER_VALIDATE_INT) ?: 10));
    if (!$idMaquina) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Falta id_maquina'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!Auth::canAccessMachine($pdo, $idMaquina)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No tienes acceso a esta maquina'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmtUnidad = $pdo->prepare(
        "SELECT id_maquina, rol_maquina, id_maquina_padre, numero_serie, nombre_ubicacion
         FROM vendingm.maquinas
         WHERE id_maquina = :id"
    );
    $stmtUnidad->execute(['id' => $idMaquina]);
    $maquina = $stmtUnidad->fetch(PDO::FETCH_ASSOC);
    if (!$maquina) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Maquina no encontrada'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $idUnidad = ((int) $maquina['rol_maquina'] === 2 && $maquina['id_maquina_padre'] !== null)
        ? (int) $maquina['id_maquina_padre']
        : (int) $maquina['id_maquina'];

    $stmtCarriles = $pdo->prepare(
        "SELECT m.id_maquina, m.numero_serie, m.rol_maquina,
                ca.numero_carril, COALESCE(ca.id_producto, l.id_producto) AS id_producto,
                ISNULL(ca.cantidad_actual, 0) AS cantidad_actual,
                p.nombre AS producto_nombre, p.codigo_barras, p.presentacion
         FROM vendingm.maquinas m
         INNER JOIN vendingm.carriles ca ON ca.id_maquina = m.id_maquina
         LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
         INNER JOIN vendingm.productos p ON p.id_producto = COALESCE(ca.id_producto, l.id_producto)
         WHERE (m.id_maquina = :id_unidad OR m.id_maquina_padre = :id_unidad_padre)
           AND ca.numero_carril % 10 <> 0
           AND ISNULL(ca.cantidad_actual, 0) >= 1
           AND (
                (m.rol_maquina = 1 AND ca.numero_carril BETWEEN 1 AND 69)
                OR (m.rol_maquina = 2 AND ca.numero_carril BETWEEN 1 AND 58)
           )
         ORDER BY m.rol_maquina, m.numero_serie, ca.numero_carril"
    );
    $stmtCarriles->execute(['id_unidad' => $idUnidad, 'id_unidad_padre' => $idUnidad]);
    $carriles = $stmtCarriles->fetchAll(PDO::FETCH_ASSOC);

    $principal = [];
    $esclavas = [];
    foreach ($carriles as $carril) {
        $fila = [
            'id_maquina' => (int) $carril['id_maquina'],
            'numero_serie' => (string) $carril['numero_serie'],
            'numero_carril' => (int) $carril['numero_carril'],
            'id_producto' => (int) $carril['id_producto'],
            'cantidad_virtual' => (int) $carril['cantidad_actual'],
            'producto_nombre' => (string) $carril['producto_nombre'],
            'codigo_barras' => $carril['codigo_barras'],
            'presentacion' => $carril['presentacion'],
        ];
        if ((int) $carril['rol_maquina'] === 1 && (int) $carril['id_maquina'] === $idUnidad) {
            $principal[] = $fila;
        } elseif ((int) $carril['rol_maquina'] === 2) {
            $esclavas[] = $fila;
        }
    }

    $pool = array_values(array_merge($principal, $esclavas));
    if (count($pool) < $minItemsPorReceta) {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'error' => "Se necesitan al menos {$minItemsPorReceta} carriles con medicamento y stock para crear recetas de prueba.",
            'data' => [
                'principal_disponibles' => count($principal),
                'esclavas_disponibles' => count($esclavas),
                'total_disponibles' => count($pool),
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $seleccionarItems = static function (array &$pool, int $cantidadDeseada, int $minimo, int $offset): array {
        $disponibles = array_values(array_filter($pool, static fn(array $item): bool => (int) $item['cantidad_virtual'] > 0));
        if (count($disponibles) < $minimo) {
            return [];
        }

        $cantidad = min($cantidadDeseada, count($disponibles));
        $seleccion = [];
        $usados = [];
        $n = count($pool);
        $cursor = $offset % max(1, $n);
        $vueltas = 0;

        while (count($seleccion) < $cantidad && $vueltas < ($n * 2)) {
            $idx = $cursor % $n;
            $item = $pool[$idx];
            $clave = $item['id_maquina'] . ':' . $item['numero_carril'];
            if ((int) $item['cantidad_virtual'] > 0 && !isset($usados[$clave])) {
                $pool[$idx]['cantidad_virtual']--;
                $item['cantidad_virtual'] = (int) $pool[$idx]['cantidad_virtual'];
                $seleccion[] = $item;
                $usados[$clave] = true;
            }
            $cursor++;
            $vueltas++;
        }

        return count($seleccion) >= $minimo ? $seleccion : [];
    };

    $pdo->beginTransaction();
    RecetaFolio::asegurarColumnas($pdo);
    $insReceta = $pdo->prepare(
        "INSERT INTO vendingm.recetas (folio, codigo_receta, tipo_receta, id_maquina, nombre_paciente, medico_solicita, id_estatus, fecha_creacion)
         OUTPUT INSERTED.id_receta, INSERTED.codigo_receta AS folio
         VALUES (NEWID(), :codigo_receta, 'cliente_prueba', :id_maquina, :paciente, :medico, 1, SYSDATETIME())"
    );
    $insDetalle = $pdo->prepare(
        "INSERT INTO vendingm.detalle_receta (id_receta, id_maquina, numero_carril, id_producto, cantidad_solicitada)
         VALUES (:id_receta, :id_maquina, :numero_carril, :id_producto, 1)"
    );

    $recetas = [];
    $usoCarriles = [];
    $productosGenerados = [];
    $totalItemsGenerados = 0;
    $itemsPorReceta = [];
    for ($i = 0; $i < $totalRecetas; $i++) {
        $cantidadDeseada = $minItemsPorReceta + ($i % (($maxItemsPorReceta - $minItemsPorReceta) + 1));
        $items = $seleccionarItems($pool, $cantidadDeseada, $minItemsPorReceta, $i * 3);
        if (!$items) {
            break;
        }
        $insReceta->execute([
            'codigo_receta' => RecetaFolio::siguiente($pdo),
            'id_maquina' => $idUnidad,
            'paciente' => sprintf('Paciente Prueba %03d', $i + 1),
            'medico' => 'Control de Calidad SYBI',
        ]);
        $receta = $insReceta->fetch(PDO::FETCH_ASSOC);
        $idReceta = (int) $receta['id_receta'];
        foreach ($items as $item) {
            $insDetalle->execute([
                'id_receta' => $idReceta,
                'id_maquina' => $item['id_maquina'],
                'numero_carril' => $item['numero_carril'],
                'id_producto' => $item['id_producto'],
            ]);
            $usoCarriles[$item['id_maquina'] . ':' . $item['numero_carril']] = true;
            $claveProducto = (string) $item['id_producto'];
            if (!isset($productosGenerados[$claveProducto])) {
                $productosGenerados[$claveProducto] = [
                    'id_producto' => $item['id_producto'],
                    'producto' => $item['producto_nombre'],
                    'codigo_barras' => $item['codigo_barras'],
                    'presentacion' => $item['presentacion'],
                    'cantidad' => 0,
                    'recetas' => 0,
                    'recetas_vistas' => [],
                ];
            }
            $productosGenerados[$claveProducto]['cantidad']++;
            if (!isset($productosGenerados[$claveProducto]['recetas_vistas'][$idReceta])) {
                $productosGenerados[$claveProducto]['recetas']++;
                $productosGenerados[$claveProducto]['recetas_vistas'][$idReceta] = true;
            }
        }
        $totalItemsGenerados += count($items);
        $itemsPorReceta[] = count($items);
        $recetas[] = [
            'id_receta' => $idReceta,
            'folio' => (string) $receta['folio'],
            'items' => array_map(static function (array $item): array {
                return [
                    'id_maquina' => $item['id_maquina'],
                    'numero_serie' => $item['numero_serie'],
                    'numero_carril' => $item['numero_carril'],
                    'producto_nombre' => $item['producto_nombre'],
                    'codigo_barras' => $item['codigo_barras'],
                    'presentacion' => $item['presentacion'],
                ];
            }, $items),
        ];
    }
    if (!$recetas) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'error' => 'No hay stock suficiente para generar recetas de prueba con el rango solicitado.',
            'data' => [
                'min_items_por_receta' => $minItemsPorReceta,
                'max_items_por_receta' => $maxItemsPorReceta,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pdo->commit();

    $folios = array_column($recetas, 'folio');
    $loteId = bin2hex(random_bytes(16));
    $_SESSION['recetas_prueba_lotes'] ??= [];
    $_SESSION['recetas_prueba_lotes'][$loteId] = [
        'folios' => $folios,
        'creado_en' => time(),
    ];

    foreach ($_SESSION['recetas_prueba_lotes'] as $idLoteSesion => $loteSesion) {
        if (!is_array($loteSesion) || (int) ($loteSesion['creado_en'] ?? 0) < time() - 7200) {
            unset($_SESSION['recetas_prueba_lotes'][$idLoteSesion]);
        }
    }

    $productosGenerados = array_values(array_map(static function (array $producto): array {
        unset($producto['recetas_vistas']);
        return $producto;
    }, $productosGenerados));
    usort($productosGenerados, static fn(array $a, array $b): int => ($b['cantidad'] <=> $a['cantidad']) ?: strcmp((string) $a['producto'], (string) $b['producto']));

    echo json_encode([
        'ok' => true,
        'data' => [
            'total_recetas' => count($recetas),
            'items_por_receta' => [
                'min' => min($itemsPorReceta),
                'max' => max($itemsPorReceta),
                'promedio' => round($totalItemsGenerados / max(1, count($recetas)), 2),
                'detalle' => $itemsPorReceta,
            ],
            'min_items_por_receta' => $minItemsPorReceta,
            'max_items_por_receta' => $maxItemsPorReceta,
            'total_medicamentos' => $totalItemsGenerados,
            'productos_generados' => $productosGenerados,
            'carriles_distintos_usados' => count($usoCarriles),
            'folios' => $folios,
            'recetas' => $recetas,
            'print_url' => 'recetas_formales.php?lote=' . rawurlencode($loteId) . '&auto_print=1',
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], JSON_UNESCAPED_UNICODE);
}
