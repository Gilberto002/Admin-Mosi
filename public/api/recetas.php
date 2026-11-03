<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Auth\Auth;
use App\Catalogo\ProductoCatalogo;
use App\Vending\RecetaFolio;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$metodo = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = Connection::get();
    ProductoCatalogo::asegurarColumnas($pdo);
    RecetaFolio::asegurarColumnas($pdo);
    $pdo->exec("
        IF COL_LENGTH('vendingm.detalle_receta', 'id_maquina') IS NULL
            ALTER TABLE vendingm.detalle_receta ADD id_maquina INT NULL
    ");

    if ($metodo === 'GET') {
        $idReceta = filter_input(INPUT_GET, 'id_receta', FILTER_VALIDATE_INT);
        $folio    = trim((string) ($_GET['folio'] ?? ''));

        if (!$idReceta && $folio === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Falta id_receta o folio']);
            exit;
        }

        $whereReceta = $idReceta
            ? 'r.id_receta = :id_receta'
            : '(r.codigo_receta = :folio_codigo OR CONVERT(varchar(36), r.folio) = :folio_uuid)';
        $paramsReceta = $idReceta
            ? ['id_receta' => $idReceta]
            : ['folio_codigo' => $folio, 'folio_uuid' => $folio];

        $stmtR = $pdo->prepare(
            "SELECT r.id_receta, COALESCE(r.codigo_receta, CONVERT(varchar(36), r.folio)) AS folio,
                    r.id_maquina, m.numero_serie, m.nombre_ubicacion,
                    r.nombre_paciente, r.medico_solicita, r.id_estatus, er.nombre AS estatus_nombre,
                    r.fecha_creacion, r.fecha_entregada
             FROM vendingm.recetas r
             INNER JOIN vendingm.maquinas m ON m.id_maquina = r.id_maquina
             LEFT JOIN vendingm.estatus_receta er ON er.id_estatus = r.id_estatus
             WHERE {$whereReceta}"
        );
        $stmtR->execute($paramsReceta);
        $receta = $stmtR->fetch(PDO::FETCH_ASSOC);

        if (!$receta) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Receta no encontrada']);
            exit;
        }
        if (!Auth::canAccessMachine($pdo, (int) $receta['id_maquina'])) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'No tienes acceso a esta maquina']);
            exit;
        }

        $stmtD = $pdo->prepare(
            "SELECT dr.id_detalle_receta, COALESCE(dr.id_maquina, r.id_maquina) AS id_maquina,
                    m.numero_serie AS numero_serie_maquina,
                    dr.numero_carril, dr.id_producto, p.nombre AS producto_nombre,
                    p.codigo_barras, p.clave, dr.cantidad_solicitada, dr.cantidad_entregada
             FROM vendingm.detalle_receta dr
             INNER JOIN vendingm.recetas r ON r.id_receta = dr.id_receta
             LEFT JOIN vendingm.maquinas m ON m.id_maquina = COALESCE(dr.id_maquina, r.id_maquina)
             INNER JOIN vendingm.productos p ON p.id_producto = dr.id_producto
             WHERE dr.id_receta = :id
             ORDER BY m.numero_serie, dr.numero_carril"
        );
        $stmtD->execute(['id' => $receta['id_receta']]);
        $receta['items'] = $stmtD->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok' => true, 'data' => $receta]);
        exit;
    }

    if ($metodo === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
            exit;
        }

        $idMaquina      = filter_var($body['id_maquina'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $nombrePaciente = trim((string) ($body['nombre_paciente'] ?? '')) ?: null;
        $medicoSolicita = trim((string) ($body['medico_solicita'] ?? '')) ?: null;
        $items          = is_array($body['items'] ?? null) ? $body['items'] : [];
        $modoAsignacion = (string) ($body['modo_asignacion'] ?? 'carril');

        if (!$idMaquina) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Falta id_maquina']);
            exit;
        }
        if (!Auth::canAccessMachine($pdo, $idMaquina)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'No tienes acceso a esta maquina']);
            exit;
        }
        $stmtTarget = $pdo->prepare(
            "SELECT id_maquina, rol_maquina, id_maquina_padre
             FROM vendingm.maquinas
             WHERE id_maquina = :id"
        );
        $stmtTarget->execute(['id' => $idMaquina]);
        $maquinaTarget = $stmtTarget->fetch(PDO::FETCH_ASSOC);
        if (!$maquinaTarget) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Maquina no encontrada']);
            exit;
        }
        $unidadTarget = ((int) $maquinaTarget['rol_maquina'] === 2 && $maquinaTarget['id_maquina_padre'] !== null)
            ? (int) $maquinaTarget['id_maquina_padre']
            : (int) $maquinaTarget['id_maquina'];
        if (empty($items)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Agrega al menos un medicamento']);
            exit;
        }

        // Validamos cada item. Modo carril: se respeta la ubicacion elegida.
        // Modo medicamento: se reparte por carriles disponibles en orden fisico.
        $itemsValidados = [];
        if ($modoAsignacion === 'medicamento') {
            $solicitadoPorProducto = [];
            foreach ($items as $it) {
                $idProducto = filter_var($it['id_producto'] ?? null, FILTER_VALIDATE_INT) ?: null;
                $cantidad = filter_var($it['cantidad'] ?? null, FILTER_VALIDATE_INT);
                if (!$idProducto || !$cantidad || $cantidad < 1) {
                    http_response_code(422);
                    echo json_encode(['ok' => false, 'error' => 'Producto o cantidad invalida en uno de los items']);
                    exit;
                }
                $solicitadoPorProducto[$idProducto] = ($solicitadoPorProducto[$idProducto] ?? 0) + $cantidad;
            }

            $stmtProducto = $pdo->prepare(
                "SELECT TOP 1 nombre
                 FROM vendingm.productos
                 WHERE id_producto = :id"
            );
            $stmtCarrilesProducto = $pdo->prepare(
                "SELECT m.id_maquina, m.numero_serie, m.rol_maquina,
                        ca.numero_carril, ca.cantidad_actual,
                        COALESCE(ca.id_producto, l.id_producto) AS id_producto
                 FROM vendingm.maquinas m
                 INNER JOIN vendingm.carriles ca ON ca.id_maquina = m.id_maquina
                 LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
                 WHERE (m.id_maquina = :id_unidad OR m.id_maquina_padre = :id_unidad_padre)
                   AND COALESCE(ca.id_producto, l.id_producto) = :id_producto
                   AND ca.numero_carril % 10 <> 0
                   AND ISNULL(ca.cantidad_actual, 0) > 0
                 ORDER BY CASE WHEN m.rol_maquina = 1 THEN 0 ELSE 1 END,
                          m.numero_serie,
                          ca.numero_carril"
            );

            foreach ($solicitadoPorProducto as $idProducto => $cantidadSolicitada) {
                $stmtProducto->execute(['id' => $idProducto]);
                $nombreProducto = (string) ($stmtProducto->fetchColumn() ?: "Producto {$idProducto}");
                $stmtCarrilesProducto->execute([
                    'id_unidad' => $unidadTarget,
                    'id_unidad_padre' => $unidadTarget,
                    'id_producto' => $idProducto,
                ]);
                $restante = (int) $cantidadSolicitada;
                foreach ($stmtCarrilesProducto->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                    if ($restante <= 0) {
                        break;
                    }
                    $tomar = min($restante, (int) $fila['cantidad_actual']);
                    $itemsValidados[] = [
                        'id_maquina' => (int) $fila['id_maquina'],
                        'numero_carril' => (int) $fila['numero_carril'],
                        'id_producto' => (int) $idProducto,
                        'cantidad' => $tomar,
                    ];
                    $restante -= $tomar;
                }
                if ($restante > 0) {
                    http_response_code(422);
                    echo json_encode(['ok' => false, 'error' => "Stock insuficiente para {$nombreProducto} (faltan {$restante})"]);
                    exit;
                }
            }
        } else {
            foreach ($items as $it) {
                $idMaquinaItem = filter_var($it['id_maquina'] ?? null, FILTER_VALIDATE_INT) ?: $idMaquina;
                $idProducto    = filter_var($it['id_producto'] ?? null, FILTER_VALIDATE_INT) ?: null;
                $numeroCarril = filter_var($it['numero_carril'] ?? null, FILTER_VALIDATE_INT);
                $cantidad     = filter_var($it['cantidad'] ?? null, FILTER_VALIDATE_INT);
                if (!$idMaquinaItem || !$numeroCarril || !$cantidad || $cantidad < 1) {
                    http_response_code(422);
                    echo json_encode(['ok' => false, 'error' => 'Maquina, carril o cantidad invalida en uno de los items']);
                    exit;
                }

                $stmtCarril = $pdo->prepare(
                    "SELECT ca.cantidad_actual, COALESCE(ca.id_producto, l.id_producto) AS id_producto, m.numero_serie
                     FROM vendingm.carriles ca
                     INNER JOIN vendingm.maquinas m ON m.id_maquina = ca.id_maquina
                     LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
                     WHERE ca.id_maquina = :id_maquina
                       AND (m.id_maquina = :id_unidad OR m.id_maquina_padre = :id_unidad_padre)
                       AND ca.numero_carril = :numero_carril"
                );
                $stmtCarril->execute([
                    'id_maquina' => $idMaquinaItem,
                    'id_unidad' => $unidadTarget,
                    'id_unidad_padre' => $unidadTarget,
                    'numero_carril' => $numeroCarril,
                ]);
                $fila = $stmtCarril->fetch(PDO::FETCH_ASSOC);

                if (!$fila || !$fila['id_producto']) {
                    http_response_code(422);
                    echo json_encode(['ok' => false, 'error' => "El carril {$numeroCarril} no tiene producto cargado"]);
                    exit;
                }
                if ($idProducto && (int) $fila['id_producto'] !== $idProducto) {
                    http_response_code(422);
                    echo json_encode(['ok' => false, 'error' => "El producto del carril {$numeroCarril} cambio, vuelve a cargar la receta"]);
                    exit;
                }
                if ((int) $fila['cantidad_actual'] < $cantidad) {
                    http_response_code(422);
                    echo json_encode(['ok' => false, 'error' => "Stock insuficiente en {$fila['numero_serie']} carril {$numeroCarril} (disponible: {$fila['cantidad_actual']})"]);
                    exit;
                }

                $itemsValidados[] = [
                    'id_maquina' => (int) $idMaquinaItem,
                    'numero_carril' => $numeroCarril,
                    'id_producto' => (int) $fila['id_producto'],
                    'cantidad' => $cantidad,
                ];
            }
        }
        $pdo->beginTransaction();
        $folioNuevo = RecetaFolio::siguiente($pdo);

        $ins = $pdo->prepare(
            "INSERT INTO vendingm.recetas (folio, codigo_receta, tipo_receta, id_maquina, nombre_paciente, medico_solicita, id_estatus, fecha_creacion)
             OUTPUT INSERTED.id_receta, INSERTED.codigo_receta AS folio
             VALUES (NEWID(), :codigo_receta, 'cliente', :id_maquina, :paciente, :medico, 1, SYSDATETIME())"
        );
        $ins->execute([
            'codigo_receta' => $folioNuevo,
            'id_maquina' => $unidadTarget,
            'paciente' => $nombrePaciente,
            'medico' => $medicoSolicita,
        ]);
        $filaReceta = $ins->fetch(PDO::FETCH_ASSOC);
        $idReceta = (int) $filaReceta['id_receta'];
        $folio = $filaReceta['folio'];

        $insDetalle = $pdo->prepare(
            "INSERT INTO vendingm.detalle_receta (id_receta, id_maquina, numero_carril, id_producto, cantidad_solicitada)
             VALUES (:id_receta, :id_maquina, :numero_carril, :id_producto, :cantidad)"
        );
        foreach ($itemsValidados as $it) {
            $insDetalle->execute([
                'id_receta' => $idReceta,
                'id_maquina' => $it['id_maquina'],
                'numero_carril' => $it['numero_carril'],
                'id_producto' => $it['id_producto'],
                'cantidad' => $it['cantidad'],
            ]);
        }

        $pdo->commit();

        echo json_encode(['ok' => true, 'data' => ['id_receta' => $idReceta, 'folio' => $folio]]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')]);
}

