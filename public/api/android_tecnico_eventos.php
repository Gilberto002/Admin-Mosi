<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;
use App\Maquinas\TecnicoAndroid;

Auth::requireAdmin();

try {
    $pdo = Connection::get();
    TecnicoAndroid::asegurarTablas($pdo);

    $q = trim((string) ($_GET['q'] ?? ''));
    $accion = trim((string) ($_GET['accion'] ?? ''));
    $resultado = trim((string) ($_GET['resultado'] ?? ''));
    $limite = filter_input(INPUT_GET, 'limite', FILTER_VALIDATE_INT) ?: 200;
    $limite = max(20, min(500, $limite));

    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = "(e.numero_serie LIKE :q_evento OR m.numero_serie LIKE :q_maquina OR u.nombre LIKE :q_nombre OR u.apellido LIKE :q_apellido OR u.email LIKE :q_email)";
        $like = '%' . $q . '%';
        $params['q_evento'] = $like;
        $params['q_maquina'] = $like;
        $params['q_nombre'] = $like;
        $params['q_apellido'] = $like;
        $params['q_email'] = $like;
    }
    if ($accion !== '') {
        $where[] = 'e.accion = :accion';
        $params['accion'] = $accion;
    }
    if (in_array($resultado, ['ok', 'error'], true)) {
        $where[] = 'e.resultado = :resultado';
        $params['resultado'] = $resultado;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $pdo->prepare(
        "SELECT TOP {$limite}
                e.id_evento, e.id_usuario, e.id_maquina,
                COALESCE(e.numero_serie, m.numero_serie) AS numero_serie,
                e.accion, e.resultado, e.mensaje, e.app_version, e.ip, e.user_agent, e.fecha,
                u.nombre, u.apellido, u.email, u.rol,
                m.nombre_maquina
         FROM vendingm.android_tecnico_eventos e
         LEFT JOIN vendingm.usuarios u ON u.id_usuario = e.id_usuario
         LEFT JOIN vendingm.maquinas m ON m.id_maquina = e.id_maquina
         {$whereSql}
         ORDER BY e.fecha DESC, e.id_evento DESC"
    );
    $stmt->execute($params);

    JsonResponse::ok($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (\Throwable $e) {
    JsonResponse::serverError($e, 'android_tecnico_eventos');
}
