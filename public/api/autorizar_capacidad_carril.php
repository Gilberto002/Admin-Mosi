<?php
// public/api/autorizar_capacidad_carril.php
// Resuelve una ampliación de vendingm.carriles.capacidad_maxima pendiente,
// detectada durante una carga de stock (Excel) que excedió la capacidad ya
// registrada -- ver App\Import\LoteRegistrador::obtenerCapacidadPendiente().
// La carga original nunca se bloqueó (se limitó al espacio disponible); lo
// que no cupo (cantidad_pendiente) se quedó sin cargar, y este endpoint es
// donde el administrador decide:
//   - "autorizar": sube capacidad_maxima al valor propuesto Y completa la
//     carga de las unidades que se habían quedado pendientes (mismo motor
//     que "Cargar stock", App\Import\LoteRegistrador -- un traspaso real
//     nuevo, con su propio reabastecimiento para trazabilidad).
//   - "descartar": no hace falta tocar nada -- capacidad_maxima ya se quedó
//     en su valor actual y la carga ya se limitó a eso durante la carga
//     original: descartar es solo una confirmación en pantalla.
// No hay una columna "capacidad_maxima_propuesta": el valor propuesto y los
// datos del lote (numero_lote/fecha_caducidad) viajan en el mismo JSON que
// ya se le mostró al administrador (ver admin.php), no se persisten aparte.
// Decisión del usuario 2026-09-10.
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;
use App\Import\LoteRegistrador;
use App\Security\Security;
// Sin "use PDO;": este archivo NO tiene namespace (es un script suelto,
// no una clase bajo App\...), así que PDO ya es una clase global visible
// directamente -- un "use" de un nombre no compuesto aquí no hace nada
// salvo generar el warning "The use statement with non-compound name
// 'PDO' has no effect", que con display_errors activo se imprimía ANTES
// del JSON y lo invalidaba del lado del navegador ("JSON.parse:
// unexpected character..."). Bug real detectado 2026-09-10 en producción.

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::error('Método no permitido, use POST', 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

$idCarril = filter_var($body['id_carril'] ?? null, FILTER_VALIDATE_INT);
$accion = (string) ($body['accion'] ?? '');

if (!$idCarril || $idCarril <= 0) {
    JsonResponse::error('id_carril inválido.', 422);
}
if (!in_array($accion, ['autorizar', 'descartar'], true)) {
    JsonResponse::error('accion inválida, use "autorizar" o "descartar".', 422);
}

if ($accion === 'descartar') {
    // Nada que persistir -- ver comentario de cabecera.
    JsonResponse::ok(['id_carril' => $idCarril, 'accion' => 'descartar']);
}

$idProducto = filter_var($body['id_producto'] ?? null, FILTER_VALIDATE_INT);
$numeroLote = trim((string) ($body['numero_lote'] ?? ''));
$fechaCaducidad = trim((string) ($body['fecha_caducidad'] ?? ''));
$cantidadPendiente = filter_var($body['cantidad_pendiente'] ?? null, FILTER_VALIDATE_INT);
$capacidadNueva = filter_var($body['capacidad_propuesta'] ?? null, FILTER_VALIDATE_INT);

if (!$idProducto || $cantidadPendiente === false || $cantidadPendiente <= 0
    || $capacidadNueva === false || $capacidadNueva <= 0
) {
    JsonResponse::error('Faltan datos de la fila pendiente (id_producto, cantidad_pendiente, capacidad_propuesta).', 422);
}

try {
    $pdo = Connection::get();

    $stmtCarril = $pdo->prepare(
        'SELECT c.id_maquina, c.numero_carril, m.id_sucursal_clinica
         FROM vendingm.carriles c
         INNER JOIN vendingm.maquinas m ON m.id_maquina = c.id_maquina
         WHERE c.id_carril = :id'
    );
    $stmtCarril->execute(['id' => $idCarril]);
    $carril = $stmtCarril->fetch(PDO::FETCH_ASSOC);
    if (!$carril) {
        JsonResponse::error('Carril no encontrado.', 404);
    }
    $idMaquina = (int) $carril['id_maquina'];
    $idSucursalOrigen = (int) ($carril['id_sucursal_clinica'] ?? 0);
    if ($idSucursalOrigen <= 0) {
        JsonResponse::error('La máquina de este carril no tiene una sucursal vinculada.', 422);
    }

    if (!Auth::canAccessMachine($pdo, $idMaquina)) {
        JsonResponse::error('No tienes acceso a esta máquina.', 403);
    }

    $usuario = Auth::user()['email'] ?? 'sistema';

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE vendingm.carriles SET capacidad_maxima = :cap WHERE id_carril = :id')
            ->execute(['cap' => $capacidadNueva, 'id' => $idCarril]);

        $insReab = $pdo->prepare(
            'INSERT INTO vendingm.reabastecimientos (nombre_archivo_origen, usuario_operador, observaciones)
             OUTPUT INSERTED.id_reabastecimiento
             VALUES (:origen, :usuario, :obs)'
        );
        $insReab->execute([
            'origen' => 'Autorización manual de capacidad',
            'usuario' => $usuario,
            'obs' => "Ampliación de capacidad_maxima autorizada para carril {$carril['numero_carril']} (máquina {$idMaquina}): se completa carga de {$cantidadPendiente} unidad(es) que había quedado pendiente.",
        ]);
        $idReabastecimiento = (int) $insReab->fetchColumn();

        $sinTraspaso = (new LoteRegistrador($pdo))->registrar(
            $idMaquina,
            $idReabastecimiento,
            [[
                'numero_carril' => $carril['numero_carril'],
                'id_producto' => $idProducto,
                'numero_lote' => $numeroLote,
                'fecha_caducidad' => $fechaCaducidad ?: null,
                'cantidad' => $cantidadPendiente,
            ]],
            $idSucursalOrigen,
            (int) Auth::id()
        );

        if ($sinTraspaso) {
            // capacidad_maxima ya se subió, pero la carga de lo pendiente no
            // se pudo completar (ej. sin existencia en la sucursal) -- se
            // revierte todo para no dejar la capacidad ampliada sin el
            // stock que la justificaba.
            throw new \RuntimeException($sinTraspaso[0]['motivo']);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (\Throwable) {
                // Ignorado -- ver mismo patrón en resurtir_vendingm.php
                // (XACT_ABORT implícito del driver).
            }
        }
        throw $e;
    }

    JsonResponse::ok(['id_carril' => $idCarril, 'accion' => 'autorizar', 'capacidad_nueva' => $capacidadNueva]);
} catch (\PDOException $e) {
    Security::logException($e, 'autorizar_capacidad_carril');
    JsonResponse::error('No se pudo autorizar la ampliación.', 500);
} catch (\RuntimeException $e) {
    JsonResponse::error($e->getMessage(), 422);
} catch (\Throwable $e) {
    Security::logException($e, 'autorizar_capacidad_carril');
    JsonResponse::error('No se pudo autorizar la ampliación.', 500);
}
