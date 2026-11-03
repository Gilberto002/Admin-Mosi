<?php
// public/api/abasto_maquinas.php
// Reporte de abasto por máquina (public/abasto_maquinas.php), con las reglas
// de docs/abasto_maquina.xlsx -- ver App\Vending\AbastoMaquinas.
//
// GET ?accion=regiones                  -> regiones con # de máquinas y # de alertas
// GET ?accion=maquinas&region=X         -> máquinas de la región, mayor ponderación primero
// GET ?accion=detalle&id_maquina=N      -> medicamentos con carril de una máquina
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;
use App\Security\Security;
use App\Vending\AbastoMaquinas;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Connection::get();
    $accion = (string) ($_GET['accion'] ?? 'regiones');
    $maquinas = AbastoMaquinas::maquinas($pdo);

    if ($accion === 'regiones') {
        $grupos = [];
        foreach ($maquinas as $m) {
            $clave = $m['region'] ?? '__sin_region__';
            $grupos[$clave] ??= ['region' => $m['region'], 'total_maquinas' => 0, 'total_alertas_totales' => 0];
            $grupos[$clave]['total_maquinas']++;
            $grupos[$clave]['total_alertas_totales'] += $m['alertas_totales'];
        }
        $lista = array_values($grupos);
        // Sin región al final; el resto alfabético (mismo orden que vendings_region.php).
        usort($lista, static function (array $a, array $b): int {
            if (($a['region'] === null) !== ($b['region'] === null)) {
                return $a['region'] === null ? 1 : -1;
            }
            return strcmp((string) $a['region'], (string) $b['region']);
        });
        JsonResponse::ok($lista);
    }

    if ($accion === 'maquinas') {
        $region = $_GET['region'] ?? null;
        $filtradas = array_values(array_filter($maquinas, static function (array $m) use ($region): bool {
            return $region === '__sin_region__' ? $m['region'] === null : $m['region'] === $region;
        }));
        // Mayor ponderación primero; a igualdad, el menor % de abasto.
        usort($filtradas, static function (array $a, array $b): int {
            return $b['ponderacion'] <=> $a['ponderacion']
                ?: ($a['porcentaje_abasto'] ?? 101) <=> ($b['porcentaje_abasto'] ?? 101)
                ?: strcmp($a['numero_serie'], $b['numero_serie']);
        });
        JsonResponse::ok(array_map(static function (array $m): array {
            unset($m['carriles']);
            return $m;
        }, $filtradas));
    }

    if ($accion === 'detalle') {
        $idMaquina = filter_input(INPUT_GET, 'id_maquina', FILTER_VALIDATE_INT);
        if (!$idMaquina) {
            JsonResponse::error('Falta id_maquina', 422);
        }
        foreach ($maquinas as $m) {
            if ($m['id_maquina'] === $idMaquina) {
                JsonResponse::ok($m);
            }
        }
        JsonResponse::error('Máquina no encontrada', 404);
    }

    JsonResponse::error('Accion no valida', 400);
} catch (\Throwable $e) {
    Security::logException($e, 'abasto_maquinas');
    JsonResponse::error('No se pudo obtener el reporte de abasto.', 500);
}
