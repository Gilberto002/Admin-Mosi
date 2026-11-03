<?php
// public/api/resurtir_vendingm.php
// Ajuste manual de un carril de una máquina vendingm desde la pantalla de
// Resurtir (mismo lugar donde ya se ajustan sucursales Intecco, ver
// resurtir_carril.php) -- pero para máquinas de vendingm.maquinas, que no
// pasan por la API de Intecco. Reusa las mismas piezas que ya usa el resto
// del proyecto: App\Import\LoteRegistrador para subir stock (mismo motor que
// "Cargar stock (Excel)") y App\Dispensacion\SalidaRegistrador para bajar/
// vaciar (mismo motor que dispensación real, motivo Merma).
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Dispensacion\SalidaRegistrador;
use App\Http\JsonResponse;
use App\Import\LoteRegistrador;
use App\Security\Security;
use App\Vending\PedidoCedisService;
use App\Vending\RecepcionPedidoService;
use App\Vending\SolicitudPedidoGenerador;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido, use POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

$idMaquina = filter_var($body['id_maquina'] ?? null, FILTER_VALIDATE_INT);
$numeroCarril = filter_var($body['numero_carril'] ?? null, FILTER_VALIDATE_INT);
$cantidadActual = filter_var($body['cantidad_actual'] ?? 0, FILTER_VALIDATE_INT);
$cantidadNueva = filter_var($body['cantidad_nueva'] ?? null, FILTER_VALIDATE_INT);
$codigoBarras = trim((string) ($body['codigo_barras'] ?? ''));
$numeroLote = trim((string) ($body['numero_lote'] ?? ''));
$fechaCaducidad = trim((string) ($body['fecha_caducidad'] ?? ''));
// Si esta carga responde a una solicitud que ya se envio a CEDIS
// (PedidoCedisService), se liga aqui para confirmar la recepcion real del
// pedido en el mismo momento -- ver App\Vending\RecepcionPedidoService.
$idSolicitud = filter_var($body['id_solicitud'] ?? null, FILTER_VALIDATE_INT) ?: null;

if (!$idMaquina || !$numeroCarril || $cantidadNueva === false || $cantidadNueva === null || $cantidadNueva < 0) {
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

try {
    $pdo = Connection::get();

    if (!Auth::canAccessMachine($pdo, $idMaquina)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No tienes acceso a esta máquina'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmtCarril = $pdo->prepare(
        'SELECT ca.id_carril, l.id_producto, m.id_sucursal_clinica
         FROM vendingm.carriles ca
         INNER JOIN vendingm.maquinas m ON m.id_maquina = ca.id_maquina
         LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
         WHERE ca.id_maquina = :m AND ca.numero_carril = :c'
    );
    $stmtCarril->execute(['m' => $idMaquina, 'c' => $numeroCarril]);
    $carril = $stmtCarril->fetch(PDO::FETCH_ASSOC);
    if (!$carril) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Carril no encontrado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $usuario = Auth::user()['email'] ?? null;

    if ($delta > 0) {
        // ---- Surtir / reponer: mismo motor que "Cargar stock (Excel)" ----
        $idProducto = (int) ($carril['id_producto'] ?? 0);
        if (!$idProducto) {
            // Carril vacío: hace falta saber qué producto se está cargando.
            if ($codigoBarras === '') {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'Este carril está vacío: captura el código de barras del producto'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $stmtProd = $pdo->prepare('SELECT id_producto FROM vendingm.productos WHERE codigo_barras = :cb AND activo = 1');
            $stmtProd->execute(['cb' => $codigoBarras]);
            $idProducto = (int) $stmtProd->fetchColumn();
            if (!$idProducto) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => "No se encontró un producto activo con ese código de barras ({$codigoBarras})"], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        if ($numeroLote === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCaducidad)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Para subir stock captura número de lote y fecha de caducidad (Y-m-d)'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Surtir un carril vendingm es, en el fondo, un traspaso real DIRECTO
        // desde una sucursal real (un solo salto, sin sucursal virtual
        // intermedia -- confirmado con el usuario 2026-08-27) -- cierra la
        // brecha de trazabilidad que antes dejaba este ajuste manual sin
        // ningún movimiento real de inventario detrás.
        //
        // Por defecto la sucursal de origen es la vinculada a la máquina,
        // pero "Traspaso" desde Disponibilidad cercana (Resurtir) puede
        // mandar id_sucursal_origen explícito para traer producto de OTRA
        // sucursal real con existencia -- ver public/api/disponibilidad_cercana.php.
        $idSucursalOrigenBody = filter_var($body['id_sucursal_origen'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $idSucursalOrigen = $idSucursalOrigenBody
            ?? ($carril['id_sucursal_clinica'] !== null ? (int) $carril['id_sucursal_clinica'] : null);
        if ($idSucursalOrigen === null) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Esta máquina no tiene una sucursal de farmacia vinculada. Vincúlala desde Máquinas antes de surtir, o elige una sucursal desde Disponibilidad cercana.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->beginTransaction();
        try {
            // Si esta carga esta ligada a una solicitud que ya se envio a
            // CEDIS, confirma la recepcion real del pedido ANTES de tocar
            // el stock -- si el pedido en CEDIS todavia no llego a "en
            // transito" (almacen no ha generado el envio),
            // RecepcionPedidoService lanza RuntimeException y toda esta
            // transaccion se revierte sin ajustar ningun carril (decision
            // del usuario 2026-09-03: bloquear la confirmacion completa en
            // vez de cerrar solo del lado de vendingm mientras tanto).
            if ($idSolicitud !== null) {
                $stmtSolicitud = $pdo->prepare(
                    'SELECT id_pedido_cedis1 FROM vendingm.solicitudes_pedido WHERE id_solicitud = :id'
                );
                $stmtSolicitud->execute(['id' => $idSolicitud]);
                $filaSolicitud = $stmtSolicitud->fetch(PDO::FETCH_ASSOC);
                if (!$filaSolicitud) {
                    throw new \RuntimeException("No se encontró la solicitud {$idSolicitud}.");
                }
                $idPedidoCedisLigado = $filaSolicitud['id_pedido_cedis1'] !== null ? (int) $filaSolicitud['id_pedido_cedis1'] : null;
                if (!$idPedidoCedisLigado) {
                    throw new \RuntimeException("La solicitud {$idSolicitud} no tiene un pedido de CEDIS asociado.");
                }

                RecepcionPedidoService::recibirPedido($pdo, $idPedidoCedisLigado, PedidoCedisService::ID_OPERADOR_VENDING);

                $pdo->prepare(
                    'UPDATE vendingm.solicitudes_pedido
                     SET id_estatus = :entregado, fecha_resuelta = SYSDATETIME(), resuelto_por = :usuario
                     WHERE id_solicitud = :id'
                )->execute([
                    'entregado' => SolicitudPedidoGenerador::ESTATUS_ENTREGADO,
                    'usuario' => Auth::id(),
                    'id' => $idSolicitud,
                ]);
            }

            $insReab = $pdo->prepare(
                'INSERT INTO vendingm.reabastecimientos (nombre_archivo_origen, usuario_operador, observaciones)
                 OUTPUT INSERTED.id_reabastecimiento
                 VALUES (:origen, :usuario, :obs)'
            );
            $insReab->execute([
                'origen' => 'Manual (Resurtir)',
                'usuario' => $usuario,
                'obs' => $idSucursalOrigenBody
                    ? "Traspaso manual desde Disponibilidad cercana: carril {$numeroCarril}, máquina {$idMaquina}, sucursal {$idSucursalOrigenBody}"
                    : "Ajuste manual de carril {$numeroCarril}, máquina {$idMaquina}",
            ]);
            $idReabastecimiento = (int) $insReab->fetchColumn();

            $sinTraspaso = (new LoteRegistrador($pdo))->registrar($idMaquina, $idReabastecimiento, [[
                'numero_carril' => $numeroCarril,
                'id_producto' => $idProducto,
                'numero_lote' => $numeroLote,
                'fecha_caducidad' => $fechaCaducidad,
                'cantidad' => $delta,
            ]], $idSucursalOrigen, (int) Auth::id());

            if ($sinTraspaso) {
                throw new \RuntimeException($sinTraspaso[0]['motivo']);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    } else {
        // ---- Bajar / vaciar: mismo motor que dispensación real ----
        (new SalidaRegistrador($pdo))->dispensar(
            (int) $carril['id_carril'],
            abs($delta),
            SalidaRegistrador::TIPO_MERMA,
            'Ajuste manual desde Resurtir',
            $usuario
        );
    }

    echo json_encode(['ok' => true, 'data' => ['id_maquina' => $idMaquina, 'numero_carril' => $numeroCarril, 'cantidad_nueva' => $cantidadNueva]], JSON_UNESCAPED_UNICODE);
} catch (\PDOException $e) {
    // Error real de servidor/BD (ej. falta una columna porque una migración
    // no se corrió todavía) -- PDOException hereda de RuntimeException, así
    // que este catch tiene que ir ANTES para no mostrarlo como si fuera un
    // mensaje de validación de negocio.
    Security::logException($e, 'resurtir_vendingm');
    JsonResponse::error('No se pudo ajustar el carril.', 500);
} catch (\RuntimeException $e) {
    // Validación de negocio (sucursal sin vincular/configurar, o traspaso
    // no realizable -- ej. sin existencia suficiente) -- mensaje claro para
    // el admin, no un 500 genérico.
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    Security::logException($e, 'resurtir_vendingm');
    JsonResponse::error('No se pudo ajustar el carril.', 500);
}
