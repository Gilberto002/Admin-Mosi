<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Http\JsonResponse;
use App\Maquinas\TecnicoAndroid;
use App\Security\Security;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    JsonResponse::ok([
        'servicio' => 'android_tecnico_login',
        'disponible' => true,
        'metodo_login' => 'POST',
        'content_type' => 'application/json',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::error('Metodo no permitido', 405);
}

Security::enforceJsonBodyLimit();
$body = leerBodyLoginTecnico();

try {
    $email = strtolower(trim((string) ($body['email'] ?? $body['correo'] ?? $body['usuario'] ?? '')));
    $numeroSerie = trim((string) ($body['numero_serie'] ?? $body['serie'] ?? $body['serial'] ?? ''));
    $password = (string) ($body['password'] ?? $body['contrasena'] ?? $body['contraseña'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        JsonResponse::error('Correo y contrasena son obligatorios', 422);
    }
    if ($numeroSerie === '') {
        JsonResponse::error(
            'Primero escanea o captura el numero de serie de la maquina.',
            422,
            ['codigo' => 'NUMERO_SERIE_REQUERIDO']
        );
    }

    $key = Security::loginThrottleKey('android-tecnico:' . $email);
    if (Security::isLoginThrottled($key)) {
        JsonResponse::error('Demasiados intentos. Intenta mas tarde.', 429);
    }

    $pdo = Connection::get();
    $stmtMaquina = $pdo->prepare('SELECT id_maquina FROM vendingm.maquinas WHERE numero_serie = :numero_serie');
    $stmtMaquina->execute(['numero_serie' => $numeroSerie]);
    $idMaquina = (int) ($stmtMaquina->fetchColumn() ?: 0);
    if ($idMaquina <= 0) {
        JsonResponse::error(
            'La maquina no esta preregistrada. Revisa el numero de serie en el panel administrador.',
            404,
            ['codigo' => 'MAQUINA_NO_PREREGISTRADA', 'numero_serie' => $numeroSerie]
        );
    }
    $sesion = TecnicoAndroid::login($pdo, $email, $password, [
        'id_maquina' => $idMaquina,
        'numero_serie' => $numeroSerie,
        'app_version' => trim((string) ($body['app_version'] ?? '')) ?: null,
    ]);

    if (!$sesion) {
        $codigo = TecnicoAndroid::ultimoCodigoErrorLogin();
        if ($codigo === 'CREDENCIALES_INVALIDAS') {
            Security::recordFailedLogin($key);
        } else {
            Security::clearLoginThrottle($key);
        }
        JsonResponse::error(
            TecnicoAndroid::ultimoErrorLogin(),
            $codigo === 'ROL_SIN_PERMISO_ACTIVACION' ? 403 : 401,
            ['codigo' => $codigo]
        );
    }

    if (empty($sesion['permisos']['puede_activar_maquina'])) {
        Security::clearLoginThrottle($key);
        JsonResponse::error(
            'La cuenta es valida, pero no tiene permiso para activar maquinas. Asignale el rol Tecnico instalador o Administrador.',
            403,
            ['codigo' => 'SIN_PERMISO_ACTIVACION']
        );
    }

    Security::clearLoginThrottle($key);
    JsonResponse::ok($sesion);
} catch (\Throwable $e) {
    JsonResponse::error(Security::publicError($e, 'android_tecnico_login'), 500);
}

function leerBodyLoginTecnico(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode((string) $raw, true);
    if (is_array($json)) {
        return $json;
    }
    if ($_POST) {
        return $_POST;
    }
    return [];
}
