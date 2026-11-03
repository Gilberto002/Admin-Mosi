<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Database\Connection;
use PhpOffice\PhpSpreadsheet\IOFactory;

$archivo = $argv[1] ?? 'C:\\Users\\gilberto.gracia\\Downloads\\maquinas_despachadoras_direccion.xlsx';
$confirmar = in_array('--confirmar', $argv, true);

if (!is_file($archivo)) {
    fwrite(STDERR, "No existe el archivo: {$archivo}\n");
    exit(1);
}

$pdo = Connection::get();
asegurarColumnasUbicacion($pdo);

$spreadsheet = IOFactory::load($archivo);
$sheet = $spreadsheet->getActiveSheet();
$rows = $sheet->toArray(null, true, true, true);
$headers = array_map(static fn($v): string => strtolower(trim((string) $v)), $rows[1] ?? []);
$col = static function (string $nombre) use ($headers): ?string {
    $idx = array_search($nombre, $headers, true);
    return $idx === false ? null : (string) $idx;
};

$cols = [
    'estado' => $col('nom_ent'),
    'municipio' => $col('nom_mun'),
    'localidad' => $col('nom_loc'),
    'clues' => $col('clues'),
    'unidad' => $col('nombre_de_la_unidad'),
    'nivel' => $col('nivel_atencion'),
    'latitud' => $col('latitud'),
    'longitud' => $col('longitud'),
    'institucion' => $col('institucion_grupo'),
    'direccion' => $col('direccion_completa'),
];

foreach (['estado', 'municipio', 'localidad', 'clues', 'unidad', 'direccion'] as $requerida) {
    if (!$cols[$requerida]) {
        fwrite(STDERR, "Falta columna requerida en Excel: {$requerida}\n");
        exit(1);
    }
}

$buscar = $pdo->prepare(
    "SELECT TOP 1 id_maquina, numero_serie
     FROM vendingm.maquinas
     WHERE codigo_interno = :clues OR clues = :clues2
        OR UPPER(LTRIM(RTRIM(nombre_ubicacion))) = UPPER(LTRIM(RTRIM(:unidad)))
     ORDER BY CASE WHEN codigo_interno = :clues3 OR clues = :clues4 THEN 0 ELSE 1 END"
);
$actualizar = $pdo->prepare(
    "UPDATE vendingm.maquinas
     SET clues = :clues,
         estado_geo = :estado,
         municipio = :municipio,
         localidad = :localidad,
         nivel_atencion = :nivel,
         institucion_grupo = :institucion,
         nombre_ubicacion = COALESCE(NULLIF(nombre_ubicacion, ''), :unidad),
         direccion = :direccion,
         latitud = COALESCE(:latitud, latitud),
         longitud = COALESCE(:longitud, longitud),
         fecha_actualizacion = SYSDATETIME()
     WHERE id_maquina = :id_maquina"
);

$leidas = 0;
$actualizadas = 0;
$sinMatch = [];

foreach (array_slice($rows, 1) as $row) {
    $clues = trim((string) ($row[$cols['clues']] ?? ''));
    $unidad = trim((string) ($row[$cols['unidad']] ?? ''));
    if ($clues === '' && $unidad === '') {
        continue;
    }
    $leidas++;
    $buscar->execute([
        'clues' => $clues,
        'clues2' => $clues,
        'clues3' => $clues,
        'clues4' => $clues,
        'unidad' => $unidad,
    ]);
    $maquina = $buscar->fetch(PDO::FETCH_ASSOC);
    if (!$maquina) {
        $sinMatch[] = "{$clues} - {$unidad}";
        continue;
    }
    if ($confirmar) {
        $actualizar->execute([
            'clues' => $clues ?: null,
            'estado' => texto($row[$cols['estado']] ?? null),
            'municipio' => texto($row[$cols['municipio']] ?? null),
            'localidad' => texto($row[$cols['localidad']] ?? null),
            'nivel' => texto($row[$cols['nivel']] ?? null),
            'institucion' => texto($row[$cols['institucion']] ?? null),
            'unidad' => $unidad ?: null,
            'direccion' => texto($row[$cols['direccion']] ?? null),
            'latitud' => numero($row[$cols['latitud']] ?? null),
            'longitud' => numero($row[$cols['longitud']] ?? null),
            'id_maquina' => (int) $maquina['id_maquina'],
        ]);
        $actualizadas += $actualizar->rowCount();
    } else {
        $actualizadas++;
    }
}

echo "Filas leidas: {$leidas}\n";
echo ($confirmar ? 'Maquinas actualizadas' : 'Coincidencias encontradas') . ": {$actualizadas}\n";
echo "Sin match: " . count($sinMatch) . "\n";
foreach (array_slice($sinMatch, 0, 30) as $item) {
    echo "  {$item}\n";
}
if (!$confirmar) {
    echo "\nVista previa. Ejecuta con --confirmar para actualizar maquinas.\n";
}

function texto(mixed $valor): ?string
{
    $texto = trim((string) $valor);
    return $texto === '' ? null : $texto;
}

function numero(mixed $valor): ?float
{
    return is_numeric($valor) ? (float) $valor : null;
}

function asegurarColumnasUbicacion(PDO $pdo): void
{
    $pdo->exec("
        IF COL_LENGTH('vendingm.maquinas', 'clues') IS NULL ALTER TABLE vendingm.maquinas ADD clues NVARCHAR(30) NULL;
        IF COL_LENGTH('vendingm.maquinas', 'estado_geo') IS NULL ALTER TABLE vendingm.maquinas ADD estado_geo NVARCHAR(100) NULL;
        IF COL_LENGTH('vendingm.maquinas', 'municipio') IS NULL ALTER TABLE vendingm.maquinas ADD municipio NVARCHAR(120) NULL;
        IF COL_LENGTH('vendingm.maquinas', 'localidad') IS NULL ALTER TABLE vendingm.maquinas ADD localidad NVARCHAR(120) NULL;
        IF COL_LENGTH('vendingm.maquinas', 'nivel_atencion') IS NULL ALTER TABLE vendingm.maquinas ADD nivel_atencion NVARCHAR(80) NULL;
        IF COL_LENGTH('vendingm.maquinas', 'institucion_grupo') IS NULL ALTER TABLE vendingm.maquinas ADD institucion_grupo NVARCHAR(120) NULL;
    ");
}
