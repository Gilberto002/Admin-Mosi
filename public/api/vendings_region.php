<?php
// public/api/vendings_region.php
// Selector por región para Resurtir (public/resurtir.php): agrupa las
// máquinas vendingm por región y las lista ordenadas por cuántos carriles
// tienen en alerta (mismo criterio que ya usa disponibilidad_cercana.php
// accion=bajo_stock) -- reemplaza el buscador plano de "Sucursal/máquina"
// como punto de entrada principal.
//
// Solo vendingm -- las máquinas Intecco quedan fuera de este flujo por
// decisión explícita del usuario 2026-09-04 (siguen accesibles desde la
// búsqueda directa que se conserva en resurtir.php como opción secundaria).
//
// Región = mismo criterio ya usado en disponibilidad_cercana.php: el estado
// REAL de la sucursal vinculada (cat_Estados, vía id_Ciudad) si existe;
// si no, el texto libre estado_geo capturado en la propia máquina (Activación
// v3 o editado a mano en Máquinas); si tampoco hay eso, "Sin región".
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;
use App\Security\Security;

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

// Mismo estimado que ya usa disponibilidad_cercana.php -- vendingm.carriles.capacidad_maxima
// no está poblada hoy por ningún flujo.
const CAPACIDAD_CARRIL_DEFAULT = 10;
const UMBRAL_PORCENTAJE_DEFAULT = 50;

try {
    $pdo = Connection::get();
    $accion = (string) ($_GET['accion'] ?? 'regiones');

    $capacidadCarril = max(1, filter_input(INPUT_GET, 'capacidad_carril', FILTER_VALIDATE_INT) ?: CAPACIDAD_CARRIL_DEFAULT);
    $umbralPorcentaje = max(1, min(100, filter_input(INPUT_GET, 'umbral_porcentaje', FILTER_VALIDATE_INT) ?: UMBRAL_PORCENTAJE_DEFAULT));

    $maquinas = obtenerMaquinasConRegion($pdo, $capacidadCarril, $umbralPorcentaje);

    if ($accion === 'regiones') {
        $grupos = [];
        foreach ($maquinas as $m) {
            $clave = $m['region'] ?? '__sin_region__';
            if (!isset($grupos[$clave])) {
                $grupos[$clave] = [
                    'region' => $m['region'],
                    'region_origen' => $m['region_origen'],
                    'total_maquinas' => 0,
                    'total_carriles_en_alerta' => 0,
                ];
            }
            $grupos[$clave]['total_maquinas']++;
            $grupos[$clave]['total_carriles_en_alerta'] += $m['carriles_en_alerta'];
        }
        $lista = array_values($grupos);
        // Sin región al final; el resto alfabético.
        usort($lista, static function (array $a, array $b): int {
            if (($a['region'] === null) !== ($b['region'] === null)) {
                return $a['region'] === null ? 1 : -1;
            }
            return strcmp((string) $a['region'], (string) $b['region']);
        });
        JsonResponse::ok($lista);
    }

    if ($accion === 'listado') {
        $region = $_GET['region'] ?? null;
        $sinRegion = $region === '__sin_region__';
        $filtradas = array_values(array_filter($maquinas, static function (array $m) use ($region, $sinRegion): bool {
            if ($sinRegion) {
                return $m['region'] === null;
            }
            return $m['region'] === $region;
        }));
        // Menor stock disponible primero = más carriles en alerta primero.
        usort($filtradas, static function (array $a, array $b): int {
            return $b['carriles_en_alerta'] <=> $a['carriles_en_alerta']
                ?: strcmp((string) $a['numero_serie'], (string) $b['numero_serie']);
        });
        JsonResponse::ok($filtradas);
    }

    JsonResponse::error('Accion no valida', 400);
} catch (\Throwable $e) {
    Security::logException($e, 'vendings_region');
    JsonResponse::error('No se pudo obtener el listado de Máquinas dispensadoras.', 500);
}

/**
 * @return list<array{id_maquina:int, numero_serie:string, nombre_maquina:?string,
 *   nombre_ubicacion:?string, numero_carriles:int, carriles_en_alerta:int,
 *   region:?string, region_origen:string}>
 */
function obtenerMaquinasConRegion(PDO $pdo, int $capacidadCarril, int $umbralPorcentaje): array
{
    $params = [
        'capacidad' => $capacidadCarril,
        'umbral' => $umbralPorcentaje,
    ];
    $condiciones = ['m.rol_maquina = 1', "m.id_estatus <> 3"];
    $authCond = Auth::machineCondition('m', $params);
    if ($authCond) {
        $condiciones[] = $authCond;
    }
    $where = 'WHERE ' . implode(' AND ', $condiciones);

    // Solo maestras (rol_maquina=1): una esclava hereda sucursal/ubicación de
    // su maestra y no tiene carriles propios en el flujo de Resurtir de hoy
    // -- mismo criterio que ya usa el resto de admin.php/maquinas.php.
    $stmt = $pdo->prepare(
        "SELECT
            m.id_maquina, m.numero_serie, m.nombre_maquina, m.nombre_ubicacion, m.numero_carriles,
            COALESCE(NULLIF(est.st_Estado, ''), NULLIF(m.estado_geo, '')) AS region,
            CASE
                WHEN est.st_Estado IS NOT NULL THEN 'sucursal_vinculada'
                WHEN NULLIF(m.estado_geo, '') IS NOT NULL THEN 'texto_activacion_v3'
                ELSE 'sin_region'
            END AS region_origen,
            ISNULL(alerta.carriles_en_alerta, 0) AS carriles_en_alerta
         FROM vendingm.maquinas m
         LEFT JOIN cat_SucursalClinica suc ON suc.id_SucursalClinica = m.id_sucursal_clinica
         LEFT JOIN cat_Estados est ON suc.id_Ciudad = est.id_Estado
         OUTER APPLY (
             -- Mismo criterio que disponibilidad_cercana.php accion=bajo_stock:
             -- carril ocupado (>0) y por debajo del umbral de capacidad.
             SELECT COUNT(*) AS carriles_en_alerta
             FROM vendingm.carriles ca
             WHERE ca.id_maquina = m.id_maquina
               AND ca.numero_carril % 10 <> 0
               AND ca.cantidad_actual > 0
               AND (ca.cantidad_actual * 100.0 / :capacidad) <= :umbral
         ) alerta
         {$where}
         ORDER BY m.numero_serie"
    );
    $stmt->execute($params);

    return array_map(static function (array $fila): array {
        return [
            'id_maquina' => (int) $fila['id_maquina'],
            'numero_serie' => (string) $fila['numero_serie'],
            'nombre_maquina' => $fila['nombre_maquina'],
            'nombre_ubicacion' => $fila['nombre_ubicacion'],
            'numero_carriles' => (int) $fila['numero_carriles'],
            'region' => $fila['region'],
            'region_origen' => (string) $fila['region_origen'],
            'carriles_en_alerta' => (int) $fila['carriles_en_alerta'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}
