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

    $canalServidor = AppActualizaciones::canalServidor($_GET['canal_app'] ?? null);
    $idActualizacion = filter_input(INPUT_GET, 'id_actualizacion', FILTER_VALIDATE_INT) ?: null;
    if ($idActualizacion === null) {
        $ultima = AppActualizaciones::ultimaActiva($pdo, $canalServidor);
        $idActualizacion = $ultima['id_actualizacion'] ?? null;
    }
    if ($idActualizacion === null) {
        JsonResponse::ok([
            'actualizacion' => null,
            'seguimiento' => [],
            'resumen' => [],
        ]);
    }

    $stmtActualizacion = $pdo->prepare(
        "SELECT id_actualizacion, canal_app, version_code, version_name, apk_url, apk_archivo, sha256,
                obligatoria, activa, fecha_publicacion, notas, fecha
         FROM vendingm.app_actualizaciones
         WHERE id_actualizacion = :id
           AND canal_app = :canal_app"
    );
    $stmtActualizacion->execute(['id' => $idActualizacion, 'canal_app' => $canalServidor]);
    $actualizacion = $stmtActualizacion->fetch(PDO::FETCH_ASSOC);
    if (!$actualizacion) {
        JsonResponse::error('Actualizacion no encontrada', 404);
    }
    $actualizacion = AppActualizaciones::normalizarActualizacion($actualizacion);

    $stmt = $pdo->prepare(
        "WITH eventos AS (
            SELECT ev.id_evento, ev.id_actualizacion, ev.id_maquina, ev.numero_serie,
                   ev.tipo_evento, ev.version_code_actual, ev.version_name_actual,
                   ev.version_code_objetivo, ev.codigo_error, ev.mensaje, ev.fecha,
                   m.codigo_interno, m.nombre_maquina, m.nombre_ubicacion,
                   ROW_NUMBER() OVER (
                       PARTITION BY COALESCE(CONVERT(NVARCHAR(30), ev.id_maquina), ev.numero_serie)
                       ORDER BY ev.fecha DESC, ev.id_evento DESC
                   ) AS rn
            FROM vendingm.app_actualizaciones_eventos ev
            LEFT JOIN vendingm.maquinas m ON m.id_maquina = ev.id_maquina
            WHERE (ev.id_actualizacion = :id_actualizacion
               OR ev.version_code_objetivo = :version_code)
              AND COALESCE(ev.canal_app, 'cliente') = :canal_app
        )
        SELECT id_evento, id_actualizacion, id_maquina, numero_serie,
               CASE
                   WHEN tipo_evento = 'consulta'
                        AND version_code_objetivo IS NULL
                        AND version_code_actual >= :version_code_estado THEN 'instalada'
                   WHEN tipo_evento = 'consulta' THEN 'pendiente'
                   WHEN tipo_evento = 'descarga' THEN 'descargando'
                   WHEN tipo_evento = 'instalacion' THEN 'instalada'
                   WHEN tipo_evento = 'error' THEN 'error'
                   ELSE tipo_evento
               END AS estado,
               tipo_evento AS ultimo_evento,
               version_code_actual, version_name_actual,
               COALESCE(version_code_objetivo, :version_code_estado_select) AS version_code_objetivo,
               codigo_error, mensaje, fecha AS fecha_ultimo_evento,
               codigo_interno, nombre_maquina, nombre_ubicacion
        FROM eventos
        WHERE rn = 1
        ORDER BY fecha_ultimo_evento DESC, numero_serie"
    );
    $stmt->execute([
        'id_actualizacion' => $idActualizacion,
        'version_code' => (int) $actualizacion['version_code'],
        'version_code_estado' => (int) $actualizacion['version_code'],
        'version_code_estado_select' => (int) $actualizacion['version_code'],
        'canal_app' => $canalServidor,
    ]);
    $seguimiento = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resumen = [
        'pendiente' => 0,
        'descargando' => 0,
        'instalada' => 0,
        'error' => 0,
    ];
    foreach ($seguimiento as $row) {
        $estado = (string) ($row['estado'] ?? '');
        if (array_key_exists($estado, $resumen)) {
            $resumen[$estado]++;
        }
    }

    JsonResponse::ok([
        'actualizacion' => $actualizacion,
        'seguimiento' => $seguimiento,
        'resumen' => $resumen,
    ]);
} catch (\Throwable $e) {
    JsonResponse::serverError($e, 'app_actualizaciones_seguimiento');
}
