<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use App\Database\Connection;

$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$pdo = Connection::get();


$palabrasControl = ['ANTIBIOTIC', 'PSICOTROP', 'ESTUPEFACIENTE', 'CONTROLADO'];

// --- 1. Sacar pares distintos (grupo, uso_terapeutico) de todo lo importado ---
$stmt = $pdo->query(
    "SELECT DISTINCT grupo, uso_terapeutico
     FROM vendingm.staging_productos_excel
     WHERE grupo IS NOT NULL AND LTRIM(RTRIM(grupo)) <> ''
       AND uso_terapeutico IS NOT NULL AND LTRIM(RTRIM(uso_terapeutico)) <> ''"
);
$pares = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$pares) {
    fwrite(STDERR, "No hay filas en staging_productos_excel con grupo/uso_terapeutico.\n");
    fwrite(STDERR, "Corre primero importar_planimetria.php con tu catálogo.\n");
    exit(1);
}

// --- 2. Detectar grupos con más de un texto de uso_terapeutico distinto ---
$porGrupo = [];
foreach ($pares as $p) {
    $grupo  = trim((string) $p['grupo']);
    $texto  = trim((string) $p['uso_terapeutico']);
    $porGrupo[$grupo][$texto] = true;
}

$gruposAmbiguos = [];
foreach ($porGrupo as $grupo => $textos) {
    if (count($textos) > 1) {
        $gruposAmbiguos[$grupo] = array_keys($textos);
    }
}

if ($gruposAmbiguos) {
    echo "--- GRUPOS AMBIGUOS (mismo GRUPO, distinto USO TERAPEUTICO) ---\n";
    echo "Estos NO se van a mapear automáticamente. Corrige la inconsistencia\n";
    echo "en el Excel/staging o decide manualmente y vuelve a correr el script.\n\n";
    foreach ($gruposAmbiguos as $grupo => $textos) {
        echo "GRUPO={$grupo}:\n";
        foreach ($textos as $t) {
            echo "   - \"{$t}\"\n";
        }
    }
    echo "\n";
}

// --- 3. Crear categorías + mapeos para los grupos NO ambiguos ---
$creadas = [];
$mapeosCreados = 0;

foreach ($porGrupo as $grupo => $textos) {
    if (isset($gruposAmbiguos[$grupo])) {
        continue; // se salta, ya reportado arriba
    }

    $nombreCategoria = array_key_first($textos);

    // ¿Ya existe la categoría por nombre?
    $stmt = $pdo->prepare('SELECT id_categoria FROM vendingm.categorias_producto WHERE nombre = :nombre');
    $stmt->execute(['nombre' => $nombreCategoria]);
    $idCategoria = $stmt->fetchColumn();

    if ($idCategoria === false) {
        $esControl = 0;
        foreach ($palabrasControl as $palabra) {
            if (stripos($nombreCategoria, $palabra) !== false) {
                $esControl = 1;
                break;
            }
        }

        $ins = $pdo->prepare(
            'INSERT INTO vendingm.categorias_producto
             (nombre, requiere_receta, es_control_especial, activo)
             OUTPUT INSERTED.id_categoria
             VALUES (:nombre, :receta, :control, 1)'
        );
        $ins->execute([
            'nombre'  => $nombreCategoria,
            'receta'  => $esControl, // si es control especial, asumimos que también requiere receta
            'control' => $esControl,
        ]);
        $idCategoria = (int) $ins->fetchColumn();

        $creadas[] = ['nombre' => $nombreCategoria, 'es_control_especial' => $esControl];
    }

    // ¿Ya existe el mapeo grupo -> categoria?
    $stmt = $pdo->prepare('SELECT id_mapeo FROM vendingm.mapeo_grupo_categoria WHERE grupo_excel = :grupo');
    $stmt->execute(['grupo' => $grupo]);
    $idMapeo = $stmt->fetchColumn();

    if ($idMapeo === false) {
        $ins = $pdo->prepare(
            'INSERT INTO vendingm.mapeo_grupo_categoria (grupo_excel, id_categoria)
             VALUES (:grupo, :categoria)'
        );
        $ins->execute(['grupo' => $grupo, 'categoria' => $idCategoria]);
        $mapeosCreados++;
    }
}

// --- 4. Reporte final ---
echo "--- RESULTADO ---\n";
echo "Categorías nuevas creadas: " . count($creadas) . "\n";
foreach ($creadas as $c) {
    $marca = $c['es_control_especial'] ? '  [posible CONTROL ESPECIAL - revisar]' : '';
    echo "  - {$c['nombre']}{$marca}\n";
}
echo "Mapeos grupo->categoría nuevos: {$mapeosCreados}\n";

if ($gruposAmbiguos) {
    echo "\nGRUPOS PENDIENTES por ambigüedad: " . count($gruposAmbiguos) . " (ver arriba)\n";
}

echo "\nRevisa manualmente 'requiere_receta' y 'es_control_especial' en categorias_producto.\n";
echo "El detector de palabras clave es solo un punto de partida, no un criterio regulatorio confiable.\n";