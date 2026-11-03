<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Http\JsonResponse;
use App\Maquinas\TecnicoAndroid;
use App\Security\Security;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') JsonResponse::error('Metodo no permitido', 405);
Security::enforceJsonBodyLimit();
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) JsonResponse::error('JSON invalido', 400);

$email = strtolower(trim((string) ($body['email'] ?? $body['correo'] ?? '')));
$password = (string) ($body['password'] ?? $body['contrasena'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    JsonResponse::error('Correo y contrasena son obligatorios', 422);
}

try {
    $key = Security::loginThrottleKey('mosi-instalador:' . $email);
    if (Security::isLoginThrottled($key)) JsonResponse::error('Demasiados intentos. Intenta mas tarde.', 429);
    $sesion = TecnicoAndroid::login(Connection::get(), $email, $password, [
        'numero_serie' => null,
        'app_version' => trim((string) ($body['app_version'] ?? '')) ?: null,
    ]);
    if (!$sesion || empty($sesion['permisos']['puede_activar_maquina'])) {
        if (TecnicoAndroid::ultimoCodigoErrorLogin() === 'CREDENCIALES_INVALIDAS') Security::recordFailedLogin($key);
        JsonResponse::error(TecnicoAndroid::ultimoErrorLogin() ?: 'El usuario no puede instalar MOSI', 401);
    }
    Security::clearLoginThrottle($key);
    JsonResponse::ok($sesion);
} catch (Throwable $e) {
    JsonResponse::serverError($e, 'mosi_instalador_login');
}
