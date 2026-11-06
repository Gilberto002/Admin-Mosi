<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Api\AndroidApi;
use App\Catalogo\ProductoCatalogo;
use App\Database\Connection;

AndroidApi::requirePost();
$body = AndroidApi::body();

// TEMPORAL PRUEBAS: reporta stock suficiente para permitir surtir recetas de prueba.
// Cambiar a false para volver a reportar el stock real del carril.
$omitirStockTemporal = false;

try {
    $pdo = Connection::get();
    ProductoCatalogo::asegurarColumnas($pdo);
    $pdo->exec("
        IF COL_LENGTH('vendingm.detalle_receta', 'id_maquina') IS NULL
            ALTER TABLE vendingm.detalle_receta ADD id_maquina INT NULL
    ");
    $maquina = AndroidApi::autenticar($pdo, $body);
    if (!$maquina) {
        AndroidApi::responder(['ok' => false, 'error' => 'Falta id_maquina o numero_serie'], 422);
    }
    $folio = AndroidApi::resolverFolioReceta($pdo, AndroidApi::folio($body));

    $stmtR = $pdo->prepare(
        "SELECT r.id_receta, COALESCE(r.codigo_receta, CONVERT(varchar(36), r.folio)) AS folio,
                r.id_maquina, m.numero_serie, m.nombre_ubicacion,
                r.nombre_paciente, r.medico_solicita, r.id_estatus, er.nombre AS estatus_nombre,
                r.fecha_creacion, r.fecha_entregada, r.origen, r.fecha_expiracion
         FROM vendingm.recetas r
         INNER JOIN vendingm.maquinas m ON m.id_maquina = r.id_maquina
         LEFT JOIN vendingm.estatus_receta er ON er.id_estatus = r.id_estatus
         WHERE r.codigo_receta = :folio_codigo OR CONVERT(varchar(36), r.folio) = :folio_uuid"
    );
    $stmtR->execute([
        'folio_codigo' => $folio,
        'folio_uuid' => $folio,
    ]);
    $receta = $stmtR->fetch(PDO::FETCH_ASSOC);

    if (!$receta) {
        AndroidApi::responder(['ok' => false, 'error' => 'Receta no encontrada'], 404);
    }
    if ($receta['fecha_expiracion'] !== null
        && strtotime((string) $receta['fecha_expiracion']) <= time()) {
        AndroidApi::responder(['ok' => false, 'error' => 'La receta vending expiro; solicita su reimpresion'], 410);
    }

    if ($maquina) {
        $unidadAuth = ((int) $maquina['rol_maquina'] === 2 && $maquina['id_maquina_padre'] !== null)
            ? (int) $maquina['id_maquina_padre']
            : (int) $maquina['id_maquina'];
        $stmtUnidadReceta = $pdo->prepare(
            "SELECT CASE WHEN rol_maquina = 2 AND id_maquina_padre IS NOT NULL THEN id_maquina_padre ELSE id_maquina END
             FROM vendingm.maquinas
             WHERE id_maquina = :id"
        );
        $stmtUnidadReceta->execute(['id' => $receta['id_maquina']]);
        if ($unidadAuth !== (int) $stmtUnidadReceta->fetchColumn()) {
            AndroidApi::responder(['ok' => false, 'error' => 'La receta no pertenece a esta maquina'], 403);
        }
    }
    if ((int) $receta['id_estatus'] === AndroidApi::ESTATUS_ENTREGADA) {
        AndroidApi::responder(['ok' => false, 'error' => 'La receta ya fue entregada'], 409);
    }
    if ((int) $receta['id_estatus'] === AndroidApi::ESTATUS_FALLIDA) {
        AndroidApi::responder(['ok' => false, 'error' => 'La receta esta marcada como fallida'], 409);
    }

    $stmtD = $pdo->prepare(
        "SELECT dr.id_detalle_receta, COALESCE(dr.id_maquina, :id_maquina) AS id_maquina,
                m.numero_serie AS numero_serie_maquina, m.rol_maquina,
                dr.numero_carril, dr.id_producto, p.nombre AS producto_nombre,
                p.codigo_barras, p.presentacion, p.descripcion, p.precio, p.imagen_path,
                dr.cantidad_solicitada, dr.cantidad_entregada,
                ca.cantidad_actual AS stock_carril, ISNULL(ls.stock_lotes, 0) AS stock_lotes,
                l.numero_lote, l.fecha_caducidad
         FROM vendingm.detalle_receta dr
         INNER JOIN vendingm.productos p ON p.id_producto = dr.id_producto
         LEFT JOIN vendingm.maquinas m ON m.id_maquina = COALESCE(dr.id_maquina, :id_maquina2)
         LEFT JOIN vendingm.carriles ca
            ON ca.id_maquina = COALESCE(dr.id_maquina, :id_maquina3) AND ca.numero_carril = dr.numero_carril
         LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
         OUTER APPLY (
             SELECT SUM(lx.cantidad_actual) AS stock_lotes
             FROM vendingm.lotes lx
             WHERE lx.id_carril = ca.id_carril
               AND lx.id_producto = dr.id_producto
         ) ls
         WHERE dr.id_receta = :id_receta
         ORDER BY CASE WHEN m.rol_maquina = 1 THEN 0 ELSE 1 END, m.numero_serie, dr.numero_carril"
    );
    $stmtD->execute([
        'id_maquina' => $receta['id_maquina'],
        'id_maquina2' => $receta['id_maquina'],
        'id_maquina3' => $receta['id_maquina'],
        'id_receta' => $receta['id_receta'],
    ]);
    $receta['items'] = array_map(static function (array $item) use ($omitirStockTemporal): array {
        $item['id_maquina'] = (int) $item['id_maquina'];
        $item['rol_maquina'] = $item['rol_maquina'] !== null ? (int) $item['rol_maquina'] : null;
        $item['id_producto'] = (int) $item['id_producto'];
        $item['numero_carril'] = (int) $item['numero_carril'];
        $item['cantidad_solicitada'] = (int) $item['cantidad_solicitada'];
        $item['cantidad_entregada'] = $item['cantidad_entregada'] !== null ? (int) $item['cantidad_entregada'] : 0;
        $item['cantidad_pendiente'] = max(0, $item['cantidad_solicitada'] - $item['cantidad_entregada']);
        $stockCarril = $item['stock_carril'] !== null ? (int) $item['stock_carril'] : 0;
        $stockLotes = (int) $item['stock_lotes'];
        $stockReal = min($stockCarril, $stockLotes);
        $item['stock'] = $omitirStockTemporal ? max($stockReal, $item['cantidad_pendiente']) : $stockReal;
        $item['stock_suficiente'] = $item['stock'] >= $item['cantidad_pendiente'];
        $item['clave_carril'] = $item['id_maquina'] . ':' . $item['numero_carril'];
        $item['maquina_destino'] = [
            'id_maquina' => $item['id_maquina'],
            'numero_serie' => $item['numero_serie_maquina'],
            'rol_maquina' => $item['rol_maquina'],
            'tipo' => $item['rol_maquina'] === 2 ? 'esclava' : 'principal',
            'numero_carril' => $item['numero_carril'],
            'clave_carril' => $item['clave_carril'],
        ];
        $item['precio'] = $item['precio'] !== null ? (float) $item['precio'] : null;
        $item['descripcion'] = $item['descripcion'] ?: ($item['presentacion'] ?: null);
        $item['imagen_url'] = ProductoCatalogo::urlPublica($item['imagen_path'] ?? null);
        unset($item['imagen_path'], $item['stock_carril'], $item['stock_lotes']);
        return $item;
    }, $stmtD->fetchAll(PDO::FETCH_ASSOC));

    if (!$receta['items']) {
        AndroidApi::responder(['ok' => false, 'error' => 'La receta no tiene productos configurados'], 409);
    }

    $sinStock = array_values(array_filter(
        $receta['items'],
        static fn (array $item): bool => !$item['stock_suficiente']
    ));
    if ($sinStock) {
        AndroidApi::responder([
            'ok' => false,
            'error' => 'Stock fisico insuficiente para surtir la receta',
            'data' => [
                'folio' => $receta['folio'],
                'items_sin_stock' => array_map(static fn (array $item): array => [
                    'id_maquina' => $item['id_maquina'],
                    'numero_carril' => $item['numero_carril'],
                    'id_producto' => $item['id_producto'],
                    'codigo_barras' => $item['codigo_barras'],
                    'cantidad_pendiente' => $item['cantidad_pendiente'],
                    'stock' => $item['stock'],
                ], $sinStock),
            ],
        ], 409);
    }

    AndroidApi::responder([
        'ok' => true,
        'data' => $receta,
        'meta' => ['stock_omitido_temporalmente' => $omitirStockTemporal],
    ]);
} catch (\Throwable $e) {
    AndroidApi::responder(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], 500);
}
