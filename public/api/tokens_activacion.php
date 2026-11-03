<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;
use App\Maquinas\TokenActivacion;

Auth::requireAdmin();

try {
    $pdo = Connection::get();
    TokenActivacion::asegurarTabla($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query(
            "SELECT TOP 100 id_token_activacion, descripcion, numero_serie, usos_max, usos_actuales,
                    revocado, fecha_expiracion, fecha_creacion, fecha_revocacion, fecha_ultimo_uso,
                    CASE
                        WHEN revocado = 1 THEN 'revocado'
                        WHEN fecha_expiracion <= SYSDATETIME() THEN 'vencido'
                        WHEN usos_actuales >= usos_max THEN 'usado'
                        ELSE 'activo'
                    END AS estado
             FROM vendingm.android_tokens_activacion
             ORDER BY fecha_creacion DESC"
        );
        JsonResponse::ok($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('Metodo no permitido', 405);
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    $accion = trim((string) ($body['accion'] ?? 'generar'));

    if ($accion === 'revocar') {
        $id = filter_var($body['id_token_activacion'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            JsonResponse::error('Falta id_token_activacion', 422);
        }
        $stmt = $pdo->prepare(
            "UPDATE vendingm.android_tokens_activacion
             SET revocado = 1, fecha_revocacion = SYSDATETIME()
             WHERE id_token_activacion = :id"
        );
        $stmt->execute(['id' => $id]);
        JsonResponse::ok(['id_token_activacion' => $id, 'revocado' => true]);
    }

    $horas = filter_var($body['horas_vigencia'] ?? 24, FILTER_VALIDATE_INT);
    $usos = filter_var($body['usos_max'] ?? 1, FILTER_VALIDATE_INT);
    $numeroSerie = trim((string) ($body['numero_serie'] ?? '')) ?: null;
    $descripcion = trim((string) ($body['descripcion'] ?? '')) ?: null;
    $token = TokenActivacion::generar($pdo, $horas ?: 24, $usos ?: 1, $numeroSerie, $descripcion);

    JsonResponse::ok($token, 201);
} catch (\Throwable $e) {
    JsonResponse::serverError($e, 'tokens_activacion');
}
