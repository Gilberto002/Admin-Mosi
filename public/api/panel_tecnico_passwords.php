<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;
use App\Maquinas\ActivacionMaquina;
use App\Maquinas\PanelTecnicoAcceso;

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    JsonResponse::error('Metodo no permitido', 405);
}

$q = trim((string) ($_GET['q'] ?? ''));
$estado = trim((string) ($_GET['estado'] ?? ''));

try {
    $pdo = Connection::get();
    ActivacionMaquina::asegurarColumnas($pdo);
    PanelTecnicoAcceso::asegurarColumnas($pdo);

    $condiciones = ['m.id_estatus <> 3'];
    $params = [];
    if ($q !== '') {
        $condiciones[] = '(m.numero_serie LIKE :q1 OR m.codigo_interno LIKE :q2 OR m.nombre_maquina LIKE :q3 OR m.nombre_ubicacion LIKE :q4)';
        $params['q1'] = "%{$q}%";
        $params['q2'] = "%{$q}%";
        $params['q3'] = "%{$q}%";
        $params['q4'] = "%{$q}%";
    }
    if ($estado === 'activada') {
        $condiciones[] = 'ISNULL(m.activada, 0) = 1';
    } elseif ($estado === 'pendiente') {
        $condiciones[] = 'ISNULL(m.activada, 0) = 0';
    } elseif ($estado === 'sin_password') {
        $condiciones[] = 'm.panel_tecnico_password_hash IS NULL';
    }

    $stmt = $pdo->prepare(
        "SELECT TOP 300
                m.id_maquina, m.numero_serie, m.codigo_interno, m.nombre_maquina,
                m.nombre_ubicacion, m.rol_maquina, m.activada, m.fecha_activacion,
                m.fecha_ultima_activacion, m.panel_tecnico_password_generada,
                CASE WHEN m.panel_tecnico_password_hash IS NULL THEN 0 ELSE 1 END AS password_generada
         FROM vendingm.maquinas m
         WHERE " . implode(' AND ', $condiciones) . "
         ORDER BY ISNULL(m.activada, 0), m.numero_serie"
    );
    $stmt->execute($params);

    JsonResponse::ok([
        'maquinas' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'master_password_configurada' => PanelTecnicoAcceso::hashMaestro($pdo) !== null,
    ]);
} catch (\Throwable $e) {
    JsonResponse::serverError($e, 'panel_tecnico_passwords');
}
