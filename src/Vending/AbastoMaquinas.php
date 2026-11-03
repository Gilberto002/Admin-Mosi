<?php
// src/Vending/AbastoMaquinas.php
// Reglas del Excel docs/abasto_maquina.xlsx aplicadas a las máquinas vendingm
// (ver public/abasto_maquinas.php y public/api/abasto_maquinas.php).
//
// Por cada medicamento de una máquina:
//   % de abasto = stock en carriles / capacidad de esos carriles
//   >= 80%  OPTIMO | <= 60% ALERTA TOTAL | <= 70% BAJO | resto (70-80%) BUENO
// (mismo orden de evaluación que la fórmula de la columna J del Excel: un 80%
// exacto es OPTIMO, un 70% exacto es BAJO y un 60% exacto es ALERTA TOTAL).
//
// Ponderación de una máquina ("urgencia ponderada", elegida por el usuario):
// suma de la ponderación del Excel (volumen a desplazar en 12 meses entre el
// total) de los medicamentos de la máquina que están en BAJO o ALERTA TOTAL.
declare(strict_types=1);

namespace App\Vending;

use App\Auth\Auth;
use PDO;

final class AbastoMaquinas
{
    // vendingm.carriles.capacidad_maxima no está poblada por ningún flujo hoy
    // -- mismo estimado que ya usan vendings_region.php y disponibilidad_cercana.php.
    public const CAPACIDAD_CARRIL_DEFAULT = 10;

    public const OPTIMO = 'OPTIMO';
    public const BUENO = 'BUENO';
    public const BAJO = 'BAJO';
    public const ALERTA_TOTAL = 'ALERTA TOTAL';

    /** @var list<array{nombre:string, patrones:list<string>, ponderacion:float}>|null */
    private static ?array $catalogo = null;

    /**
     * Estatus según las reglas del Excel. Aritmética entera (stock*100 contra
     * capacidad*N) para que 80/70/60% exactos no dependan de redondeo flotante.
     */
    public static function estatus(int $stock, int $capacidad): string
    {
        if ($capacidad <= 0) {
            return self::ALERTA_TOTAL;
        }
        $x = $stock * 100;
        if ($x >= 80 * $capacidad) {
            return self::OPTIMO;
        }
        if ($x <= 60 * $capacidad) {
            return self::ALERTA_TOTAL;
        }
        if ($x <= 70 * $capacidad) {
            return self::BAJO;
        }
        return self::BUENO;
    }

    public static function enRiesgo(string $estatus): bool
    {
        return $estatus === self::BAJO || $estatus === self::ALERTA_TOTAL;
    }

    /** @return list<array{nombre:string, patrones:list<string>, ponderacion:float}> */
    private static function catalogo(): array
    {
        if (self::$catalogo !== null) {
            return self::$catalogo;
        }
        $filas = require dirname(__DIR__, 2) . '/config/abasto_ponderacion.php';
        $total = 0;
        foreach ($filas as $f) {
            $total += (int) $f['volumen'];
        }
        self::$catalogo = [];
        foreach ($filas as $f) {
            self::$catalogo[] = [
                'nombre' => (string) $f['nombre'],
                'patrones' => array_map([self::class, 'normalizar'], $f['patrones']),
                'ponderacion' => $total > 0 ? ((int) $f['volumen']) / $total : 0.0,
            ];
        }
        return self::$catalogo;
    }

    public static function normalizar(string $texto): string
    {
        $t = mb_strtolower(trim($texto), 'UTF-8');
        return strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }

    /**
     * Renglón del Excel al que pertenece un producto (índice del catálogo) o
     * null si no coincide con ninguno.
     */
    private static function indiceCatalogo(?string $nombre, ?string $nombreComercial, ?string $sustancia): ?int
    {
        $texto = self::normalizar(($nombre ?? '') . ' ' . ($nombreComercial ?? '') . ' ' . ($sustancia ?? ''));
        foreach (self::catalogo() as $i => $entrada) {
            foreach ($entrada['patrones'] as $patron) {
                if ($patron !== '' && str_contains($texto, $patron)) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * Carriles con producto de las máquinas maestras visibles para el usuario,
     * agrupados por máquina. Cada carril trae su estatus; cada máquina trae
     * su % de abasto, sus alertas y su ponderación.
     *
     * @return list<array<string,mixed>>
     */
    public static function maquinas(PDO $pdo): array
    {
        $params = [];
        $condiciones = ['m.rol_maquina = 1', 'm.id_estatus <> 3'];
        $auth = Auth::machineCondition('m', $params);
        if ($auth) {
            $condiciones[] = $auth;
        }
        $where = 'WHERE ' . implode(' AND ', $condiciones);

        // Región: mismo criterio que vendings_region.php (estado real de la
        // sucursal vinculada; si no, el texto estado_geo de la máquina).
        $stmtM = $pdo->prepare(
            "SELECT m.id_maquina, m.numero_serie, m.nombre_maquina, m.nombre_ubicacion,
                    COALESCE(NULLIF(est.st_Estado, ''), NULLIF(m.estado_geo, '')) AS region
             FROM vendingm.maquinas m
             LEFT JOIN cat_SucursalClinica suc ON suc.id_SucursalClinica = m.id_sucursal_clinica
             LEFT JOIN cat_Estados est ON suc.id_Ciudad = est.id_Estado
             {$where}
             ORDER BY m.numero_serie"
        );
        $stmtM->execute($params);
        $maquinas = [];
        foreach ($stmtM->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $maquinas[(int) $f['id_maquina']] = [
                'id_maquina' => (int) $f['id_maquina'],
                'numero_serie' => (string) $f['numero_serie'],
                'nombre_maquina' => $f['nombre_maquina'],
                'nombre_ubicacion' => $f['nombre_ubicacion'],
                'region' => $f['region'],
                'carriles' => [],
            ];
        }
        if ($maquinas === []) {
            return [];
        }

        // Esclavas (rol_maquina = 2): comparten el numero de carril de su maestra
        // (el importador resta 100 al de la cabina 2), pero tienen su propio
        // id_maquina. Cuentan en las metricas de la maestra.
        $idsMaestras = implode(',', array_map('intval', array_keys($maquinas)));
        $esclavaDe = [];
        $stmtE = $pdo->query(
            "SELECT id_maquina, id_maquina_padre FROM vendingm.maquinas
             WHERE rol_maquina = 2 AND id_estatus <> 3 AND id_maquina_padre IN ({$idsMaestras})"
        );
        foreach ($stmtE->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $esclavaDe[(int) $f['id_maquina']] = (int) $f['id_maquina_padre'];
            $maquinas[(int) $f['id_maquina_padre']]['id_esclava'] = (int) $f['id_maquina'];
        }
        $idsTodas = $idsMaestras . ($esclavaDe ? ',' . implode(',', array_keys($esclavaDe)) : '');

        // numero_carril % 10 = 0 se excluye igual que en vendings_region.php.
        // p.clave es la clave de salud (Cuadro Basico/CIS) del catalogo vendingm.
        $capDefault = self::CAPACIDAD_CARRIL_DEFAULT;
        $stmtC = $pdo->query(
            "SELECT ca.id_carril, ca.id_maquina, ca.numero_carril, ca.cantidad_actual,
                    CASE WHEN ca.capacidad_maxima > 0 THEN ca.capacidad_maxima ELSE {$capDefault} END AS capacidad,
                    p.id_producto, p.codigo_barras, p.clave, p.nombre, p.nombre_comercial, p.sustancia_activa
             FROM vendingm.carriles ca
             INNER JOIN vendingm.productos p ON p.id_producto = ca.id_producto
             WHERE ca.id_maquina IN ({$idsTodas})
               AND ca.numero_carril % 10 <> 0"
        );
        $filas = $stmtC->fetchAll(PDO::FETCH_ASSOC);

        $catalogo = self::catalogo();

        // Lotes vivos por carril (vendingm.lotes con piezas): un carril puede
        // tener varios si se resurtió sin vaciarlo -- el descuento es FEFO
        // (SalidaRegistrador). carriles.id_lote_actual solo apunta al último.
        $lotesPorCarril = [];
        $idsCarril = array_map('intval', array_column($filas, 'id_carril'));
        foreach (array_chunk($idsCarril, 1000) as $lote) {
            $stmtL = $pdo->query(
                'SELECT id_carril, numero_lote, fecha_caducidad, cantidad_actual
                 FROM vendingm.lotes
                 WHERE id_carril IN (' . implode(',', $lote) . ') AND cantidad_actual > 0
                 ORDER BY fecha_caducidad ASC, id_lote ASC'
            );
            foreach ($stmtL->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $lotesPorCarril[(int) $l['id_carril']][] = [
                    'numero_lote' => trim((string) $l['numero_lote']),
                    'fecha_caducidad' => substr((string) $l['fecha_caducidad'], 0, 10),
                    'cantidad' => (int) $l['cantidad_actual'],
                ];
            }
        }

        foreach ($filas as $f) {
            $idMaquinaCarril = (int) $f['id_maquina'];
            $esEsclava = isset($esclavaDe[$idMaquinaCarril]);
            $idMaestra = $esEsclava ? $esclavaDe[$idMaquinaCarril] : $idMaquinaCarril;
            $idx = self::indiceCatalogo($f['nombre'], $f['nombre_comercial'], $f['sustancia_activa']);
            $stock = max(0, (int) $f['cantidad_actual']);
            $capacidad = (int) $f['capacidad'];
            $codigo = trim((string) $f['codigo_barras']);
            $clave = trim((string) ($f['clave'] ?? ''));
            $maquinas[$idMaestra]['carriles'][] = [
                'id_maquina' => $idMaquinaCarril,
                'cabina' => $esEsclava ? 'esclava' : 'maestra',
                'numero_carril' => (int) $f['numero_carril'],
                'lotes' => $lotesPorCarril[(int) $f['id_carril']] ?? [],
                'id_producto' => (int) $f['id_producto'],
                'codigo_barras' => $codigo,
                'clave' => $clave !== '' ? $clave : null,
                'nombre' => (string) ($f['nombre'] ?? ''),
                'indice_catalogo' => $idx,
                'ponderacion' => $idx !== null ? $catalogo[$idx]['ponderacion'] : 0.0,
                'stock' => $stock,
                'capacidad' => $capacidad,
                'porcentaje' => $capacidad > 0 ? round($stock * 100 / $capacidad, 1) : 0.0,
                'estatus' => self::estatus($stock, $capacidad),
            ];
        }

        foreach ($maquinas as &$m) {
            self::resumir($m);
        }
        unset($m);

        return array_values($maquinas);
    }

    /**
     * Calcula % de abasto, alertas y ponderación de una máquina y deja sus
     * carriles ordenados por ponderación (mayor a menor) y luego por % (menor
     * primero). El estatus que cuenta para la máquina es el del medicamento
     * (stock y capacidad sumados de todos sus carriles), como la columna
     * "% POR CLAVE" del Excel.
     *
     * @param array<string,mixed> $m
     */
    private static function resumir(array &$m): void
    {
        $porProducto = [];
        foreach ($m['carriles'] as $c) {
            $k = $c['id_producto'];
            $porProducto[$k]['stock'] = ($porProducto[$k]['stock'] ?? 0) + $c['stock'];
            $porProducto[$k]['capacidad'] = ($porProducto[$k]['capacidad'] ?? 0) + $c['capacidad'];
            $porProducto[$k]['indice'] = $c['indice_catalogo'];
            $porProducto[$k]['ponderacion'] = $c['ponderacion'];
        }

        // Si dos productos de la máquina caen en el mismo renglón del Excel,
        // su ponderación se reparte para no contarla dos veces.
        $productosPorRenglon = [];
        foreach ($porProducto as $p) {
            if ($p['indice'] !== null) {
                $productosPorRenglon[$p['indice']] = ($productosPorRenglon[$p['indice']] ?? 0) + 1;
            }
        }

        $suma = 0.0;
        $alertas = 0;
        $porcentajes = [];
        foreach ($porProducto as $p) {
            $estatus = self::estatus($p['stock'], $p['capacidad']);
            $porcentajes[] = $p['capacidad'] > 0 ? $p['stock'] * 100 / $p['capacidad'] : 0.0;
            if ($estatus === self::ALERTA_TOTAL) {
                $alertas++;
            }
            if (self::enRiesgo($estatus) && $p['indice'] !== null) {
                $suma += $p['ponderacion'] / $productosPorRenglon[$p['indice']];
            }
        }

        $m['total_medicamentos'] = count($porProducto);
        $m['alertas_totales'] = $alertas;
        $m['porcentaje_abasto'] = $porcentajes ? round(array_sum($porcentajes) / count($porcentajes), 1) : null;
        $m['ponderacion'] = round($suma * 100, 2);

        // Del más crítico al óptimo (ALERTA TOTAL, BAJO, BUENO, OPTIMO); dentro
        // de cada estatus, mayor ponderación primero y luego menor %.
        $gravedad = [self::ALERTA_TOTAL => 0, self::BAJO => 1, self::BUENO => 2, self::OPTIMO => 3];
        usort($m['carriles'], static function (array $a, array $b) use ($gravedad): int {
            return $gravedad[$a['estatus']] <=> $gravedad[$b['estatus']]
                ?: $b['ponderacion'] <=> $a['ponderacion']
                ?: $a['porcentaje'] <=> $b['porcentaje']
                ?: $a['cabina'] <=> $b['cabina']
                ?: $a['numero_carril'] <=> $b['numero_carril'];
        });

        $m['claves'] = self::agruparPorClave($m['carriles'], $gravedad);
    }

    /**
     * Un renglón por medicamento: por clave de salud (vendingm.productos.clave)
     * y, si el producto no la tiene, por id_producto. Suma stock y capacidad de
     * TODOS sus carriles (maestra y esclava) y lista los carriles para poder
     * resurtirlo completo.
     *
     * @param list<array<string,mixed>> $carriles
     * @param array<string,int> $gravedad
     * @return list<array<string,mixed>>
     */
    private static function agruparPorClave(array $carriles, array $gravedad): array
    {
        $grupos = [];
        foreach ($carriles as $c) {
            $k = $c['clave'] !== null ? 'c:' . $c['clave'] : 'p:' . $c['id_producto'];
            if (!isset($grupos[$k])) {
                $grupos[$k] = [
                    'clave' => $c['clave'],
                    'nombre' => $c['nombre'],
                    'codigo_barras' => $c['codigo_barras'],
                    'ponderacion' => $c['ponderacion'],
                    'stock' => 0,
                    'capacidad' => 0,
                    'carriles' => [],
                ];
            }
            $grupos[$k]['stock'] += $c['stock'];
            $grupos[$k]['capacidad'] += $c['capacidad'];
            $grupos[$k]['ponderacion'] = max($grupos[$k]['ponderacion'], $c['ponderacion']);
            $grupos[$k]['carriles'][] = [
                'id_maquina' => $c['id_maquina'],
                'cabina' => $c['cabina'],
                'numero_carril' => $c['numero_carril'],
                'codigo_barras' => $c['codigo_barras'],
                'stock' => $c['stock'],
                'capacidad' => $c['capacidad'],
            ];
            // Lotes del medicamento: mismo lote+caducidad en varios carriles se suma.
            foreach ($c['lotes'] as $l) {
                $kl = $l['numero_lote'] . '|' . $l['fecha_caducidad'];
                $grupos[$k]['lotes_idx'][$kl] ??= ['numero_lote' => $l['numero_lote'], 'fecha_caducidad' => $l['fecha_caducidad'], 'cantidad' => 0];
                $grupos[$k]['lotes_idx'][$kl]['cantidad'] += $l['cantidad'];
            }
        }
        foreach ($grupos as &$g) {
            $lotes = array_values($g['lotes_idx'] ?? []);
            unset($g['lotes_idx']);
            usort($lotes, static fn(array $a, array $b): int => strcmp($a['fecha_caducidad'], $b['fecha_caducidad']));
            $g['lotes'] = $lotes;
            $g['faltan'] = max(0, $g['capacidad'] - $g['stock']);
            $g['porcentaje'] = $g['capacidad'] > 0 ? round($g['stock'] * 100 / $g['capacidad'], 1) : 0.0;
            $g['estatus'] = self::estatus($g['stock'], $g['capacidad']);
        }
        unset($g);
        $lista = array_values($grupos);
        usort($lista, static function (array $a, array $b) use ($gravedad): int {
            return $gravedad[$a['estatus']] <=> $gravedad[$b['estatus']]
                ?: $b['ponderacion'] <=> $a['ponderacion']
                ?: $a['porcentaje'] <=> $b['porcentaje']
                ?: strcmp($a['nombre'], $b['nombre']);
        });
        return $lista;
    }
}
