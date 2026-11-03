<?php

declare(strict_types=1);

namespace App\Auth;

use App\Database\Connection;
use App\Security\Security;
use PDO;

final class Auth
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function user(): ?array
    {
        self::start();
        return $_SESSION['usuario'] ?? null;
    }

    public static function id(): ?int
    {
        $usuario = self::user();
        return $usuario ? (int) $usuario['id_usuario'] : null;
    }

    public static function isAdmin(): bool
    {
        $usuario = self::user();
        return $usuario && ($usuario['rol'] ?? '') === 'admin';
    }

    public static function isCliente(): bool
    {
        $usuario = self::user();
        return $usuario && ($usuario['rol'] ?? '') === 'cliente';
    }

    public static function isClinico(): bool
    {
        $rol = (string) (self::user()['rol'] ?? '');
        return in_array($rol, ['medico', 'psicologo', 'nutriologo'], true);
    }

    public static function homePage(): string
    {
        if (self::isCliente()) return 'cliente_dashboard.php';
        if (self::isClinico()) return 'personal_clinico.php';
        return 'dashboard.php';
    }

    public static function requireCliente(): void
    {
        self::requireLogin();
        if (self::isCliente() || self::isAdmin()) {
            return;
        }

        if (self::isApiRequest()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Acceso exclusivo para clientes'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: dashboard.php');
        exit;
    }

    public static function isUsuarioMaquinasDashboard(): bool
    {
        return self::id() === 3;
    }

    public static function login(string $email, string $password): bool
    {
        // Bloqueo tras 5 intentos fallidos (15 min), por email+IP -- ver
        // src/Security/Security.php. login.php ya trata cualquier `false`
        // como "credenciales inválidas", así que no hace falta que distinga
        // el caso throttled.
        $throttleKey = Security::loginThrottleKey($email);
        if (Security::isLoginThrottled($throttleKey)) {
            return false;
        }

        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            "SELECT id_usuario, nombre, apellido, email, password_hash, rol, activo
             FROM vendingm.usuarios
             WHERE email = :email"
        );
        $stmt->execute(['email' => strtolower(trim($email))]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        $rol = (string) ($usuario['rol'] ?? '');
        $rolSoloAppTecnica = in_array($rol, ['tecnico_instalador', 'tecnico_soporte'], true);
        if (!$usuario
            || !(bool) $usuario['activo']
            || $rolSoloAppTecnica
            || !password_verify($password, (string) $usuario['password_hash'])
        ) {
            Security::recordFailedLogin($throttleKey);
            return false;
        }

        Security::clearLoginThrottle($throttleKey);
        self::start();
        session_regenerate_id(true);
        $_SESSION['usuario'] = [
            'id_usuario' => (int) $usuario['id_usuario'],
            'nombre' => (string) $usuario['nombre'],
            'apellido' => (string) $usuario['apellido'],
            'email' => (string) $usuario['email'],
            'rol' => (string) $usuario['rol'],
        ];

        $upd = $pdo->prepare('UPDATE vendingm.usuarios SET ultimo_acceso = SYSDATETIME() WHERE id_usuario = :id');
        $upd->execute(['id' => (int) $usuario['id_usuario']]);

        return true;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }

    public static function requireLogin(): void
    {
        if (self::user() !== null) {
            if (self::isApiRequest()) {
                self::verifyCsrf();
            }
            return;
        }

        if (self::isApiRequest()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Sesion requerida'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: login.php');
        exit;
    }

    /**
     * Token de sesión (synchronizer token pattern) para proteger contra CSRF
     * las páginas y endpoints que pasan por requireLogin()/requireAdmin().
     * Se emite en el <head> de cada página (meta[name=csrf-token]) y el
     * frontend lo reenvía en el header X-CSRF-Token de cada fetch de escritura.
     */
    public static function csrfToken(): string
    {
        return Security::ensureCsrfToken();
    }

    public static function verifyCsrf(): void
    {
        Security::requireCsrfForUnsafeMethods();
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (self::isAdmin() && !self::isUsuarioMaquinasDashboard()) {
            return;
        }

        if (self::isApiRequest()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'No tienes permisos de administrador'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(403);
        echo 'No tienes permisos de administrador.';
        exit;
    }

    public static function canAccessMachine(PDO $pdo, int $idMaquina): bool
    {
        if (self::isAdmin()) {
            return true;
        }

        $idUsuario = self::id();
        if (!$idUsuario) {
            return false;
        }

        if (self::isClinico()) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM vendingm.telemedicina_personal p
                 INNER JOIN vendingm.telemedicina_personal_mosi pm ON pm.id_personal = p.id_personal
                 INNER JOIN vendingm.telemedicina_mosi_maquinas mm ON mm.id_mosi = pm.id_mosi
                 WHERE p.id_usuario = :id_usuario AND mm.id_maquina = :id_maquina AND p.activo = 1"
            );
            $stmt->execute(['id_usuario' => $idUsuario, 'id_maquina' => $idMaquina]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $sinAsignaciones = self::isCliente()
            ? "OR NOT EXISTS (
                SELECT 1 FROM vendingm.usuarios_maquinas ua
                WHERE ua.id_usuario = :id_usuario_sin_asignaciones
              )"
            : '';
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM vendingm.maquinas m
             WHERE m.id_maquina = :id_maquina
               AND (
                    EXISTS (
                        SELECT 1 FROM vendingm.usuarios_maquinas um
                        WHERE um.id_usuario = :id_usuario
                          AND um.id_maquina = CASE WHEN m.rol_maquina = 2 THEN m.id_maquina_padre ELSE m.id_maquina END
                    )
                    {$sinAsignaciones}
               )"
        );
        $params = ['id_maquina' => $idMaquina, 'id_usuario' => $idUsuario];
        if (self::isCliente()) {
            $params['id_usuario_sin_asignaciones'] = $idUsuario;
        }
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function machineScope(string $alias, array &$params, string $column = 'id_maquina'): string
    {
        if (self::isAdmin()) {
            return '';
        }

        $idUsuario = self::id();
        $params['auth_id_usuario'] = $idUsuario ?? 0;
        $expr = "{$alias}.{$column}";

        if (self::isClinico()) {
            return " AND EXISTS (
                SELECT 1
                FROM vendingm.telemedicina_personal tp
                INNER JOIN vendingm.telemedicina_personal_mosi tpm ON tpm.id_personal = tp.id_personal
                INNER JOIN vendingm.telemedicina_mosi_maquinas tmm ON tmm.id_mosi = tpm.id_mosi
                WHERE tp.id_usuario = :auth_id_usuario
                  AND tp.activo = 1
                  AND tmm.id_maquina = {$expr}
            )";
        }

        $asignada = "EXISTS (
            SELECT 1
            FROM vendingm.usuarios_maquinas um
            INNER JOIN vendingm.maquinas ms ON ms.id_maquina = {$expr}
            WHERE um.id_usuario = :auth_id_usuario
              AND um.id_maquina = CASE WHEN ms.rol_maquina = 2 THEN ms.id_maquina_padre ELSE ms.id_maquina END
        )";

        if (!self::isCliente()) {
            return " AND {$asignada}";
        }

        $params['auth_id_usuario_fallback'] = $idUsuario ?? 0;
        // Un cliente nuevo puede consultar la red activa hasta que el administrador
        // delimite su alcance asignándole una o más máquinas principales.
        return " AND (
            {$asignada}
            OR NOT EXISTS (
                SELECT 1 FROM vendingm.usuarios_maquinas ua
                WHERE ua.id_usuario = :auth_id_usuario_fallback
            )
        )";
    }

    public static function machineCondition(string $alias, array &$params, string $column = 'id_maquina'): ?string
    {
        $scope = self::machineScope($alias, $params, $column);
        return $scope === '' ? null : preg_replace('/^\s*AND\s+/i', '', trim($scope));
    }

    private static function isApiRequest(): bool
    {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        return str_contains($script, '/api/')
            || str_contains($script, '/android_clientes/');
    }
}
