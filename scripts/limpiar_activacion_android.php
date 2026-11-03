<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Api\AndroidApi;
use App\Database\Connection;

$args = $argv ?? [];
$confirmar = in_array('--confirmar', $args, true);
$todas = in_array('--todas', $args, true);
$idMaquina = null;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--id-maquina=')) {
        $idMaquina = filter_var(substr($arg, strlen('--id-maquina=')), FILTER_VALIDATE_INT) ?: null;
    }
}

if (!$todas && !$idMaquina) {
    fwrite(STDERR, <<<TXT
Uso:
  php scripts/limpiar_activacion_android.php --id-maquina=5
  php scripts/limpiar_activacion_android.php --id-maquina=5 --confirmar
  php scripts/limpiar_activacion_android.php --todas
  php scripts/limpiar_activacion_android.php --todas --confirmar

Sin --confirmar solo muestra que tokens se limpiarian.
No borra maquinas, carriles, inventario, recetas ni movimientos.

TXT);
    exit(1);
}

$pdo = Connection::get();
AndroidApi::asegurarTablas($pdo);

$where = $todas ? '' : 'WHERE t.id_maquina = :id_maquina';
$params = $todas ? [] : ['id_maquina' => $idMaquina];

$stmt = $pdo->prepare(
    "SELECT t.id_token, t.id_maquina, m.numero_serie, t.revocado, t.fecha_creacion, t.ultimo_uso
     FROM vendingm.api_tokens_maquina t
     INNER JOIN vendingm.maquinas m ON m.id_maquina = t.id_maquina
     {$where}
     ORDER BY t.id_maquina, t.fecha_creacion DESC"
);
$stmt->execute($params);
$tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$tokens) {
    echo "No hay tokens Android para limpiar.\n";
    exit(0);
}

echo "Tokens Android encontrados:\n";
foreach ($tokens as $token) {
    printf(
        "- id_token=%s id_maquina=%s serie=%s revocado=%s ultimo_uso=%s\n",
        $token['id_token'],
        $token['id_maquina'],
        $token['numero_serie'],
        (int) $token['revocado'],
        $token['ultimo_uso'] ?? 'nunca'
    );
}

if (!$confirmar) {
    echo "\nVista previa solamente. Repite el comando con --confirmar para borrar estos tokens.\n";
    exit(0);
}

$deleteWhere = $todas ? '' : 'WHERE id_maquina = :id_maquina';
$delete = $pdo->prepare("DELETE FROM vendingm.api_tokens_maquina {$deleteWhere}");
$delete->execute($params);

echo "\nListo. Tokens Android eliminados: " . $delete->rowCount() . "\n";
echo "Las maquinas, carriles, inventario, recetas y movimientos no fueron modificados.\n";
