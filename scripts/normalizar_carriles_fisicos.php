<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Maquinas\CarrilesFisicos;

$args = $argv ?? [];
$confirmar = in_array('--confirmar', $args, true);
$idMaquina = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--id-maquina=')) {
        $idMaquina = filter_var(substr($arg, 13), FILTER_VALIDATE_INT) ?: null;
    }
}

$pdo = Connection::get();
$sql = 'SELECT id_maquina, numero_serie, numero_carriles FROM vendingm.maquinas';
$params = [];
if ($idMaquina !== null) {
    $sql .= ' WHERE id_maquina = :id_maquina';
    $params['id_maquina'] = $idMaquina;
}
$sql .= ' ORDER BY numero_serie';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$maquinas = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$maquinas) {
    fwrite(STDERR, "No se encontraron máquinas.\n");
    exit(1);
}

$stmtInvalidos = $pdo->prepare(
    'SELECT numero_carril, cantidad_actual, id_producto, id_lote_actual
     FROM vendingm.carriles
     WHERE id_maquina = :id AND numero_carril % 10 = 0
     ORDER BY numero_carril'
);

foreach ($maquinas as $maquina) {
    $id = (int) $maquina['id_maquina'];
    $ultimo = (int) ($maquina['numero_carriles'] ?? 0);
    $stmtInvalidos->execute(['id' => $id]);
    $invalidos = $stmtInvalidos->fetchAll(PDO::FETCH_ASSOC);
    $ocupados = array_filter($invalidos, static fn(array $c): bool =>
        (int) ($c['cantidad_actual'] ?? 0) !== 0 || $c['id_producto'] !== null || $c['id_lote_actual'] !== null
    );
    printf(
        "%s (id=%d): último=%d, físicos=%d, múltiplos de 10=%d, con datos=%d\n",
        $maquina['numero_serie'],
        $id,
        $ultimo,
        CarrilesFisicos::cantidadHasta($ultimo),
        count($invalidos),
        count($ocupados)
    );
    if ($ocupados) {
        $detalle = array_map(static fn(array $c): string => sprintf(
            '%d (stock=%d, producto=%s, lote=%s)',
            (int) $c['numero_carril'],
            (int) ($c['cantidad_actual'] ?? 0),
            $c['id_producto'] ?? 'sin producto',
            $c['id_lote_actual'] ?? 'sin lote'
        ), $ocupados);
        echo '  Reubicar antes de eliminar: ' . implode('; ', $detalle) . "\n";
    }

    if ($confirmar) {
        $pdo->beginTransaction();
        try {
            CarrilesFisicos::crearFaltantes($pdo, $id, $ultimo);
            $eliminados = CarrilesFisicos::eliminarInvalidosVacios($pdo, $id);
            $pdo->commit();
            echo "  Normalizada: {$eliminados} posición(es) vacía(s) eliminada(s).\n";
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

if (!$confirmar) {
    echo "\nVista previa. Ejecuta nuevamente con --confirmar para normalizar sin borrar carriles que tengan datos.\n";
}
