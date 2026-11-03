<?php
// public/api/resurtir_carril.php
// Consola de resurtido: orquesta la maquina fisica (API de Intecco/Kabina)
// y el traspaso real de inventario (App\Vending\TraspasoService) desde un
// solo lugar, en vez de que el JS del navegador llame a los dos por
// separado (asi vivia en system/adminVending/js/main.js, con la secuencia
// Intecco->ERP copiada 3 veces con manejo de error distinto cada vez).
//
// GET  ?accion=carriles&id_sucursal_origen=N   -> estado actual de los
//      carriles de la maquina de esa sucursal (Intecco) + cuanto hay
//      disponible en la sucursal real por producto (ERP), para cruzarlos
//      en la UI igual que hacia el panel viejo.
// POST { id_sucursal_origen, id_upc, id_column, id_row, id_mod,
//        cantidad_actual, cantidad_nueva } -> ajusta el carril (subir,
//        bajar o vaciar) y registra el movimiento real correspondiente.
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Http\IntecoClient;
use App\Http\JsonResponse;
use App\Vending\TraspasoService;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

/**
 * Intecco identifica cada producto con su propio "idProduct" -- en este
 * sistema ese campo se carga con el mismo UPC que usa el catálogo de ML
 * (cat_ProductosAlmacenMaster.id_UPC), no hay una tabla de mapeo aparte.
 * Mismo criterio que ya usaba el legado: la variable que sale de la
 * respuesta de Intecco se pasa tal cual como "idUpc" al registrar el
 * traspaso real (ver system/adminVending/js/main.js).
 */

function resolverIdMachine(PDO $pdo, int $idSucursalOrigen): int
{
    $stmt = $pdo->prepare(
        "SELECT st_vendingMachine FROM cat_SucursalClinica
         WHERE id_SucursalClinica = :id AND i_Activo = 1
           AND st_vendingMachine IS NOT NULL AND st_vendingMachine <> ''"
    );
    $stmt->execute(['id' => $idSucursalOrigen]);
    $idMachine = $stmt->fetchColumn();

    if (!$idMachine) {
        throw new \RuntimeException("La sucursal {$idSucursalOrigen} no tiene una máquina de vending configurada (cat_SucursalClinica.st_vendingMachine)");
    }

    return (int) $idMachine;
}

function resolverIdProductoAlmacen(PDO $pdo, string $idUpc): int
{
    $stmt = $pdo->prepare('SELECT id_ProductoAlmacen FROM cat_ProductosAlmacenMaster WHERE id_UPC = :upc');
    $stmt->execute(['upc' => $idUpc]);
    $id = $stmt->fetchColumn();

    if (!$id) {
        throw new \RuntimeException('No se encontró el producto en el catálogo de almacén (UPC ' . $idUpc . ')');
    }

    return (int) $id;
}

try {
    $pdo = Connection::get();
    $metodo = $_SERVER['REQUEST_METHOD'];

    if ($metodo === 'GET' && ($_GET['accion'] ?? '') === 'sucursales') {
        // Sucursales reales para el selector de resurtir.php. Vivía antes en
        // api/estadisticas.php?accion=sucursales, pero ese endpoint ya es
        // 100% vendingm (ver decisión del 2026-08-24 en la memoria del
        // proyecto) -- se movió aquí, que es donde vive el resto de la
        // lógica de farmacia de este módulo.
        //
        // Se excluyen las sucursales virtuales ("VENDING - ...", ver
        // TraspasoService::asegurarSucursalVirtual()): la migración legada
        // clona st_vendingMachine junto con el resto de la fila, así que sin
        // este filtro una virtual aparecería aquí como si fuera una
        // sucursal real más, duplicando la entrada de su origen.
        TraspasoService::asegurarEsquema($pdo);
        $stmt = $pdo->query(
            "SELECT id_SucursalClinica, st_Nombre FROM cat_SucursalClinica
             WHERE i_Activo = 1 AND st_vendingMachine IS NOT NULL AND st_vendingMachine <> ''
               AND id_SucursalOrigenVending IS NULL
             ORDER BY st_Nombre"
        );
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo === 'GET') {
        $idSucursalOrigen = filter_input(INPUT_GET, 'id_sucursal_origen', FILTER_VALIDATE_INT);
        if (!$idSucursalOrigen) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Falta id_sucursal_origen'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $idMachine = resolverIdMachine($pdo, $idSucursalOrigen);

        $intecco = new IntecoClient();
        $stock = $intecco->getStock($idMachine, withDetails: true);

        // Disponible en la sucursal REAL por producto (id_UPC), para que la
        // UI pueda mostrar "En sucursal: N" junto a cada carril, igual que
        // el panel viejo (productosVending en system/adminVending/php/funciones.php).
        $stmtSucursal = $pdo->prepare(
            "SELECT t2.id_UPC, t2.st_Nombre AS st_NombreProducto, SUM(t1.Stock) AS disponibles
             FROM view_CEDStockSucursalCaducidad t1
             INNER JOIN cat_ProductosAlmacenMaster t2 ON t1.id_ProductoAlmacen = t2.id_ProductoAlmacen
             WHERE t1.id_Sucursal = :idSucursal AND t2.i_Activo = 1
             GROUP BY t2.id_UPC, t2.st_Nombre"
        );
        $stmtSucursal->execute(['idSucursal' => $idSucursalOrigen]);

        $enSucursal = [];
        foreach ($stmtSucursal->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $enSucursal[(string) $fila['id_UPC']] = [
                'nombre' => $fila['st_NombreProducto'],
                'disponibles' => (float) $fila['disponibles'],
            ];
        }

        echo json_encode([
            'ok' => true,
            'data' => [
                'id_machine' => $idMachine,
                'carriles' => $stock['inventory'] ?? [],
                'productos' => $stock['details'] ?? [],
                'en_sucursal' => $enSucursal,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = $_POST;
        }

        $idSucursalOrigen = filter_var($body['id_sucursal_origen'] ?? null, FILTER_VALIDATE_INT);
        $idUpc = trim((string) ($body['id_upc'] ?? ''));
        $idColumn = filter_var($body['id_column'] ?? null, FILTER_VALIDATE_INT);
        $idRow = filter_var($body['id_row'] ?? null, FILTER_VALIDATE_INT);
        $idMod = filter_var($body['id_mod'] ?? 0, FILTER_VALIDATE_INT);
        $cantidadActual = filter_var($body['cantidad_actual'] ?? 0, FILTER_VALIDATE_INT);
        $cantidadNueva = filter_var($body['cantidad_nueva'] ?? null, FILTER_VALIDATE_INT);

        if (!$idSucursalOrigen || $idUpc === '' || $idColumn === false || $idColumn === null
            || $idRow === false || $idRow === null || $cantidadNueva === false || $cantidadNueva === null || $cantidadNueva < 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Faltan datos por ingresar o son inválidos'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $cantidadActual = max(0, (int) $cantidadActual);

        $delta = $cantidadNueva - $cantidadActual;
        if ($delta === 0) {
            echo json_encode(['ok' => true, 'data' => ['sin_cambios' => true]], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Se resuelve el producto en el catálogo de ML ANTES de tocar Intecco:
        // si el UPC no existe, se falla aquí y la máquina física ni se toca
        // (el legado hacía esta validación después, cuando Intecco ya había
        // sido modificado).
        $idProductoAlmacen = resolverIdProductoAlmacen($pdo, $idUpc);
        $idMachine = resolverIdMachine($pdo, $idSucursalOrigen);

        $intecco = new IntecoClient();

        if ($cantidadActual === 0) {
            $intecco->saveInventory([
                'idMachine' => $idMachine,
                'idColumn' => $idColumn,
                'idProduct' => $idUpc,
                'quantity' => $cantidadNueva,
                'idRow' => $idRow,
                'lot' => 'GENERAL',
                'idMod' => $idMod,
                'reserved' => 0,
            ]);
        } elseif ($cantidadNueva === 0) {
            $intecco->removeInventory([
                'idMachine' => $idMachine,
                'idRow' => $idRow,
                'idColumn' => $idColumn,
                'idProduct' => $idUpc,
                'idMod' => $idMod,
            ]);
        } elseif ($delta > 0) {
            // El endpoint de Intecco solo acepta sumar (delta), no fijar una
            // cantidad absoluta -- mismo comportamiento que ya documentaba el legado.
            $intecco->updateInventory([
                'idMachine' => $idMachine,
                'idColumn' => $idColumn,
                'quantity' => $delta,
                'idRow' => $idRow,
                'idMod' => $idMod,
            ]);
        } else {
            // Bajar sin vaciar: Intecco no soporta restar directamente, así
            // que se vacía el carril y se vuelve a crear con la cantidad
            // nueva (mismo "borra y recrea" que ya usaba el legado).
            $intecco->removeInventory([
                'idMachine' => $idMachine,
                'idRow' => $idRow,
                'idColumn' => $idColumn,
                'idProduct' => $idUpc,
                'idMod' => $idMod,
            ]);
            $intecco->saveInventory([
                'idMachine' => $idMachine,
                'idColumn' => $idColumn,
                'idProduct' => $idUpc,
                'quantity' => $cantidadNueva,
                'idRow' => $idRow,
                'lot' => 'GENERAL',
                'idMod' => $idMod,
                'reserved' => 0,
            ]);
        }

        // La máquina física ya quedó actualizada en este punto. De aquí en
        // adelante cualquier falla se reporta con intecco_ya_actualizado=true
        // para que el operador sepa que el carril SÍ cambió aunque el
        // movimiento real en el ERP no se haya podido registrar (igual que
        // advertía el panel viejo) -- no hay forma de "deshacer" en Intecco.
        try {
            $pdo->beginTransaction();
            if ($delta > 0) {
                // Cargar el carril es un traspaso real completo en una sola
                // llamada: descuenta FIFO directo de la sucursal real Y
                // acredita a su sucursal virtual, sin depender de que esta
                // última ya tenga existencia previa.
                $resultado = TraspasoService::cargarStockFisico(
                    $pdo, $idProductoAlmacen, $delta, $idSucursalOrigen, (int) Auth::id(),
                    "Resurtir Intecco: maquina {$idMachine}, fila {$idRow}, columna {$idColumn}"
                );
            } else {
                // Corrección administrativa (vaciar/bajar sin ser una
                // dispensación/merma real): traspaso real completo en
                // sentido contrario -- descuenta lo que realmente salió del
                // carril (abs($delta)) del almacén virtual de la máquina y
                // lo acredita de vuelta a la sucursal real.
                $resultado = TraspasoService::registrarCorreccion($pdo, $idProductoAlmacen, abs($delta), $idSucursalOrigen, (int) Auth::id());
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'error' => 'El carril de la máquina se actualizó, pero no se pudo registrar el movimiento real de inventario: ' . $e->getMessage(),
                'intecco_ya_actualizado' => true,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // registrarTraspaso()/registrarCorreccion() ya no lanzan por
        // existencia insuficiente -- cierran el folio con nota de
        // "incompleto" y devuelven completo:false. La máquina física ya
        // tiene el delta completo aplicado (arriba), así que se avisa igual
        // que en el catch de arriba, pero sin perder el rastro de auditoría
        // parcial que sí se alcanzó a registrar.
        if (!$resultado['completo']) {
            http_response_code(200);
            echo json_encode([
                'ok' => false,
                'error' => "El carril de la máquina se actualizó, pero el movimiento real de inventario quedó incompleto: se traspasaron {$resultado['cantidad_movida']} de {$resultado['cantidad_solicitada']} pieza(s) (sin existencia suficiente en la sucursal).",
                'intecco_ya_actualizado' => true,
                'data' => $resultado,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(['ok' => true, 'data' => $resultado], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    JsonResponse::errorFromException($e, 'No se pudo completar la operación de resurtido.');
}
