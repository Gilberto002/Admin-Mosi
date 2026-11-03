<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Actualizaciones\AppActualizaciones;
use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;

Auth::requireAdmin();

try {
    $pdo = Connection::get();
    AppActualizaciones::asegurarTablas($pdo);

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        JsonResponse::error('Metodo no permitido', 405);
    }

    $numeroSerie = strtoupper(trim((string) ($_GET['numero_serie'] ?? $_GET['serie'] ?? '')));
    $tipoEvento = strtolower(trim((string) ($_GET['tipo_evento'] ?? $_GET['evento'] ?? '')));
    $canalServidor = AppActualizaciones::canalServidor($_GET['canal_app'] ?? null);
    $limite = filter_input(INPUT_GET, 'limite', FILTER_VALIDATE_INT) ?: 200;
    $limite = max(1, min(500, $limite));
    $where = ["COALESCE(ev.canal_app, 'cliente') = :canal_app"];
    $params = ['canal_app' => $canalServidor];

    if ($numeroSerie !== '') {
        $where[] = 'UPPER(ev.numero_serie) = :numero_serie';
        $params['numero_serie'] = $numeroSerie;
    }
    if ($tipoEvento !== '') {
        $where[] = 'ev.tipo_evento = :tipo_evento';
        $params['tipo_evento'] = $tipoEvento;
    }

    $sql = "SELECT TOP {$limite} ev.id_evento, ev.id_actualizacion, ev.id_maquina, ev.id_mosi, ev.numero_serie,
                COALESCE(ev.canal_app, 'cliente') AS canal_app,
                ev.tipo_evento, ev.version_code_actual, ev.version_name_actual,
                ev.version_code_objetivo, ev.codigo_error, ev.mensaje, ev.payload_json, ev.respuesta_json, ev.fecha,
                m.codigo_interno, COALESCE(m.nombre_maquina,tm.nombre) nombre_maquina, COALESCE(m.nombre_ubicacion,tm.ubicacion) nombre_ubicacion
         FROM vendingm.app_actualizaciones_eventos ev
         LEFT JOIN vendingm.maquinas m ON m.id_maquina = ev.id_maquina
         LEFT JOIN vendingm.telemedicina_mosi tm ON tm.id_mosi = ev.id_mosi
         " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
         ORDER BY ev.fecha DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    JsonResponse::ok($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (\Throwable $e) {
    JsonResponse::serverError($e, 'app_actualizaciones_eventos');
}
