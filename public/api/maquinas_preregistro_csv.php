<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;
use App\Maquinas\ActivacionMaquina;
use App\Maquinas\CarrilesFisicos;

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::error('Metodo no permitido', 405);
}
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    JsonResponse::error('No se recibio el CSV', 422);
}

$archivo = $_FILES['archivo'];
$extension = strtolower((string) pathinfo((string) $archivo['name'], PATHINFO_EXTENSION));
if (!in_array($extension, ['csv', 'txt'], true)) {
    JsonResponse::error('El archivo debe ser .csv', 422);
}
if ((int) $archivo['size'] <= 0 || (int) $archivo['size'] > 3 * 1024 * 1024) {
    JsonResponse::error('El CSV excede el limite de 3 MB', 422);
}

$fh = fopen($archivo['tmp_name'], 'rb');
if (!$fh) {
    JsonResponse::error('No se pudo leer el CSV', 500);
}

try {
    $pdo = Connection::get();
    ActivacionMaquina::asegurarColumnas($pdo);

    $header = fgetcsv($fh);
    if (!$header) {
        JsonResponse::error('CSV vacio', 422);
    }
    $header = array_map(static fn($h) => strtolower(trim((string) $h)), $header);
    $idx = array_flip($header);
    if (!isset($idx['numero_serie'])) {
        JsonResponse::error('El CSV debe incluir la columna numero_serie', 422);
    }

    $creadas = 0;
    $actualizadas = 0;
    $omitidas = [];
    $filaNum = 1;

    $pdo->beginTransaction();
    while (($row = fgetcsv($fh)) !== false) {
        $filaNum++;
        $numeroSerie = campoCsv($row, $idx, 'numero_serie');
        if ($numeroSerie === '') {
            $omitidas[] = ['fila' => $filaNum, 'motivo' => 'numero_serie vacio'];
            continue;
        }

        $nombreMaquina = campoCsv($row, $idx, 'nombre_maquina') ?: $numeroSerie;
        $codigoInterno = campoCsv($row, $idx, 'codigo_interno') ?: generarCodigoInterno($numeroSerie);
        $rolMaquina = (int) (campoCsv($row, $idx, 'rol_maquina') ?: 1);
        if ($rolMaquina === 2) {
            $omitidas[] = ['fila' => $filaNum, 'numero_serie' => $numeroSerie, 'motivo' => 'CSV masivo solo preregistra maquinas maestras'];
            continue;
        }
        $rolMaquina = 1;
        $numeroCarriles = (int) (campoCsv($row, $idx, 'numero_carriles') ?: ($rolMaquina === 1 ? 69 : 58));
        $numeroCarriles = max(0, min(500, $numeroCarriles));

        $stmt = $pdo->prepare('SELECT id_maquina FROM vendingm.maquinas WHERE numero_serie = :serie');
        $stmt->execute(['serie' => $numeroSerie]);
        $idExistente = $stmt->fetchColumn();

        if ($idExistente) {
            $upd = $pdo->prepare(
                "UPDATE vendingm.maquinas
                 SET nombre_maquina = COALESCE(NULLIF(:nombre, ''), nombre_maquina),
                     numero_carriles = :carriles,
                     fecha_actualizacion = SYSDATETIME()
                 WHERE id_maquina = :id AND ISNULL(activada, 0) = 0"
            );
            $upd->execute([
                'nombre' => $nombreMaquina,
                'carriles' => $numeroCarriles,
                'id' => (int) $idExistente,
            ]);
            if ($upd->rowCount() > 0) {
                CarrilesFisicos::crearFaltantes($pdo, (int) $idExistente, $numeroCarriles);
                $actualizadas++;
            } else {
                $omitidas[] = ['fila' => $filaNum, 'numero_serie' => $numeroSerie, 'motivo' => 'ya activada'];
            }
            continue;
        }

        $codigoInterno = codigoInternoDisponible($pdo, $codigoInterno);
        $ins = $pdo->prepare(
            "INSERT INTO vendingm.maquinas
             (numero_serie, nombre_maquina, codigo_interno, numero_carriles, id_estatus, rol_maquina, activada)
             OUTPUT INSERTED.id_maquina
             VALUES (:serie, :nombre, :codigo, :carriles, 1, :rol, 0)"
        );
        $ins->execute([
            'serie' => $numeroSerie,
            'nombre' => $nombreMaquina,
            'codigo' => $codigoInterno,
            'carriles' => $numeroCarriles,
            'rol' => $rolMaquina,
        ]);
        $idMaquina = (int) $ins->fetchColumn();
        CarrilesFisicos::crearFaltantes($pdo, $idMaquina, $numeroCarriles);
        $creadas++;
    }
    $pdo->commit();

    JsonResponse::ok([
        'creadas' => $creadas,
        'actualizadas' => $actualizadas,
        'omitidas' => $omitidas,
    ]);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    JsonResponse::serverError($e, 'maquinas_preregistro_csv');
} finally {
    fclose($fh);
}

function campoCsv(array $row, array $idx, string $campo): string
{
    if (!isset($idx[$campo])) {
        return '';
    }
    return trim((string) ($row[$idx[$campo]] ?? ''));
}

function generarCodigoInterno(string $numeroSerie): string
{
    $base = strtoupper((string) preg_replace('/[^A-Z0-9]+/i', '', $numeroSerie));
    return substr($base !== '' ? $base : 'MAQ', 0, 40);
}

function codigoInternoDisponible(PDO $pdo, string $codigo): string
{
    $base = substr($codigo, 0, 36);
    $actual = $base;
    $i = 1;
    $stmt = $pdo->prepare('SELECT 1 FROM vendingm.maquinas WHERE codigo_interno = :codigo');
    while (true) {
        $stmt->execute(['codigo' => $actual]);
        if (!$stmt->fetchColumn()) {
            return $actual;
        }
        $i++;
        $actual = $base . '-' . $i;
    }
}
