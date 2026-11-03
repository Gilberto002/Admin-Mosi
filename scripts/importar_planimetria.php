<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Import\PlanimetriaImportador;
use App\Vending\TraspasoService;

$rutaExcel = $argv[1] ?? null;
$numeroSerial = $argv[2] ?? null;
$usuario = $argv[3] ?? 'sistema';
$idOperador = isset($argv[4]) ? (int) $argv[4] : TraspasoService::ID_OPERADOR_VENDING;

if (!$rutaExcel || !$numeroSerial) {
    fwrite(STDERR, "Uso: php scripts/importar_planimetria.php <archivo.xlsx> <numero_serial_principal> [usuario] [id_operador]\n");
    exit(1);
}

try {
    $resultado = (new PlanimetriaImportador(Connection::get()))->importar($rutaExcel, $numeroSerial, $usuario, $idOperador);

    echo "Importacion completa.\n";
    echo "Reabastecimiento: {$resultado['id_reabastecimiento']}\n";
    echo "Filas leidas: {$resultado['filas_leidas']}\n";
    echo "Maquina principal: {$resultado['id_maquina_principal']}\n";
    echo 'Maquina esclava: ' . ($resultado['id_maquina_esclava'] ?? 'sin esclava') . "\n";
    echo 'Carriles sin match: ' . count($resultado['no_asignados']) . "\n";
    echo 'Filas sin fecha: ' . count($resultado['sin_fecha']) . "\n";
    echo 'Lotes sin carril: ' . count($resultado['sin_carril']) . "\n";
    echo 'Filas sin traspaso completo: ' . count($resultado['sin_traspaso']) . "\n";
    echo 'Errores de staging: ' . count($resultado['errores']) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}
