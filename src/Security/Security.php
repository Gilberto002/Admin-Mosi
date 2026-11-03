<?php

declare(strict_types=1);

namespace App\Security;

use Throwable;

final class Security
{
    private const CSRF_SESSION_KEY = 'csrf_token';
    private const CSRF_COOKIE = 'ML_CSRF_TOKEN';
    private const LOGIN_ATTEMPTS_KEY = 'login_attempts';

    public static function configureSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', self::sameSite());
        ini_set('session.cookie_secure', self::isHttps() ? '1' : '0');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => self::sameSite(),
        ]);
    }

    public static function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cross-Origin-Resource-Policy: same-origin');

        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function ensureCsrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION[self::CSRF_SESSION_KEY]) || !is_string($_SESSION[self::CSRF_SESSION_KEY])) {
            $_SESSION[self::CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
        }

        $token = $_SESSION[self::CSRF_SESSION_KEY];
        self::setCsrfCookie($token);

        return $token;
    }

    public static function csrfInput(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::ensureCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function requireCsrfForUnsafeMethods(): void
    {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $esperado = self::ensureCsrfToken();
        $recibido = self::csrfFromRequest();

        if ($recibido === '' || !hash_equals($esperado, $recibido)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Solicitud no autorizada'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    public static function publicError(Throwable $e, string $context = 'app'): string
    {
        self::logException($e, $context);
        if (($_ENV['APP_ENV'] ?? 'production') === 'development') {
            return $e->getMessage();
        }

        return 'Ocurrio un error interno. Intenta mas tarde.';
    }

    public static function loginThrottleKey(string $email): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return hash('sha256', strtolower(trim($email)) . '|' . $ip);
    }

    public static function isLoginThrottled(string $key): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        self::cleanupLoginAttempts();
        $attempt = $_SESSION[self::LOGIN_ATTEMPTS_KEY][$key] ?? null;
        return is_array($attempt)
            && (int) ($attempt['count'] ?? 0) >= 5
            && (int) ($attempt['until'] ?? 0) > time();
    }

    public static function recordFailedLogin(string $key): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        self::cleanupLoginAttempts();
        $attempt = $_SESSION[self::LOGIN_ATTEMPTS_KEY][$key] ?? ['count' => 0, 'until' => 0];
        $count = (int) ($attempt['count'] ?? 0) + 1;
        $_SESSION[self::LOGIN_ATTEMPTS_KEY][$key] = [
            'count' => $count,
            'until' => $count >= 5 ? time() + 900 : 0,
        ];
    }

    public static function clearLoginThrottle(string $key): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        unset($_SESSION[self::LOGIN_ATTEMPTS_KEY][$key]);
    }

    public static function logException(Throwable $e, string $context = 'app'): void
    {
        $logPath = dirname(__DIR__, 2) . '/logs/app_errors.log';
        $line = sprintf(
            "[%s] [%s] %s in %s:%d%s%s%s",
            date('Y-m-d H:i:s'),
            $context,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            PHP_EOL,
            $e->getTraceAsString(),
            PHP_EOL
        );

        @file_put_contents($logPath, $line, FILE_APPEND);
    }

    public static function enforceJsonBodyLimit(int $maxBytes = 1048576): void
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > $maxBytes) {
            http_response_code(413);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Payload demasiado grande'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    private static function csrfFromRequest(): string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach (['X-CSRF-Token', 'x-csrf-token', 'X-Requested-CSRF', 'x-requested-csrf'] as $header) {
            if (!empty($headers[$header])) {
                return trim((string) $headers[$header]);
            }
        }

        foreach (['HTTP_X_CSRF_TOKEN', 'REDIRECT_HTTP_X_CSRF_TOKEN', 'HTTP_X_REQUESTED_CSRF'] as $serverKey) {
            if (!empty($_SERVER[$serverKey])) {
                return trim((string) $_SERVER[$serverKey]);
            }
        }

        $tokenPost = trim((string) ($_POST['_csrf'] ?? ''));
        if ($tokenPost !== '') {
            return $tokenPost;
        }

        return '';
    }

    private static function cleanupLoginAttempts(): void
    {
        $attempts = $_SESSION[self::LOGIN_ATTEMPTS_KEY] ?? [];
        if (!is_array($attempts)) {
            $_SESSION[self::LOGIN_ATTEMPTS_KEY] = [];
            return;
        }

        foreach ($attempts as $key => $attempt) {
            if (is_array($attempt) && (int) ($attempt['until'] ?? 0) > 0 && (int) $attempt['until'] <= time()) {
                unset($attempts[$key]);
            }
        }
        $_SESSION[self::LOGIN_ATTEMPTS_KEY] = $attempts;
    }

    private static function setCsrfCookie(string $token): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::CSRF_COOKIE, $token, [
            'expires' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => self::isHttps(),
            'httponly' => false,
            'samesite' => self::sameSite(),
        ]);
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    private static function sameSite(): string
    {
        return $_ENV['SESSION_SAMESITE'] ?? 'Strict';
    }
}
