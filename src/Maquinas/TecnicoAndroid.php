<?php
declare(strict_types=1);

namespace App\Maquinas;

use PDO;

final class TecnicoAndroid
{
    private const ROLES = ['admin', 'tecnico_instalador', 'tecnico_soporte'];
    private const HORAS_SESION = 12;
    private static string $ultimoErrorLogin = 'Credenciales invalidas';
    private static string $ultimoCodigoErrorLogin = 'CREDENCIALES_INVALIDAS';

    public static function asegurarTablas(PDO $pdo): void
    {
        $pdo->exec("
            IF OBJECT_ID('vendingm.android_tecnico_sesiones', 'U') IS NULL
            BEGIN
                CREATE TABLE vendingm.android_tecnico_sesiones (
                    id_sesion INT IDENTITY(1,1) PRIMARY KEY,
                    id_usuario INT NOT NULL,
                    token_hash NVARCHAR(255) NOT NULL,
                    revocado BIT NOT NULL CONSTRAINT DF_android_tecnico_sesiones_revocado DEFAULT 0,
                    fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_android_tecnico_sesiones_creacion DEFAULT SYSDATETIME(),
                    fecha_expiracion DATETIME2 NOT NULL,
                    fecha_ultimo_uso DATETIME2 NULL,
                    ip NVARCHAR(64) NULL,
                    user_agent NVARCHAR(300) NULL
                );
                CREATE INDEX IX_android_tecnico_sesiones_usuario ON vendingm.android_tecnico_sesiones(id_usuario, revocado, fecha_expiracion);
            END;

            IF OBJECT_ID('vendingm.android_tecnico_eventos', 'U') IS NULL
            BEGIN
                CREATE TABLE vendingm.android_tecnico_eventos (
                    id_evento INT IDENTITY(1,1) PRIMARY KEY,
                    id_usuario INT NULL,
                    id_maquina INT NULL,
                    numero_serie NVARCHAR(100) NULL,
                    accion NVARCHAR(80) NOT NULL,
                    resultado NVARCHAR(30) NOT NULL,
                    mensaje NVARCHAR(800) NULL,
                    app_version NVARCHAR(50) NULL,
                    payload_json NVARCHAR(MAX) NULL,
                    ip NVARCHAR(64) NULL,
                    user_agent NVARCHAR(300) NULL,
                    fecha DATETIME2 NOT NULL CONSTRAINT DF_android_tecnico_eventos_fecha DEFAULT SYSDATETIME()
                );
                CREATE INDEX IX_android_tecnico_eventos_fecha ON vendingm.android_tecnico_eventos(fecha DESC);
                CREATE INDEX IX_android_tecnico_eventos_usuario ON vendingm.android_tecnico_eventos(id_usuario, fecha DESC);
                CREATE INDEX IX_android_tecnico_eventos_maquina ON vendingm.android_tecnico_eventos(id_maquina, fecha DESC);
            END;
        ");
    }

    public static function rolesPermitidos(): array
    {
        return self::ROLES;
    }

    public static function login(PDO $pdo, string $email, string $password, array $contexto = []): ?array
    {
        self::$ultimoErrorLogin = 'Credenciales invalidas';
        self::$ultimoCodigoErrorLogin = 'CREDENCIALES_INVALIDAS';
        self::asegurarTablas($pdo);
        $stmt = $pdo->prepare(
            "SELECT id_usuario, nombre, apellido, email, password_hash, rol, activo
             FROM vendingm.usuarios
             WHERE LOWER(email) = LOWER(:email)"
        );
        $stmt->execute(['email' => trim($email)]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario || !password_verify($password, (string) $usuario['password_hash'])) {
            self::registrarEvento($pdo, null, null, $contexto['numero_serie'] ?? null, 'login_tecnico', 'error', self::$ultimoErrorLogin, $contexto['app_version'] ?? null);
            return null;
        }
        if (!(bool) $usuario['activo']) {
            self::$ultimoErrorLogin = 'La cuenta esta desactivada';
            self::$ultimoCodigoErrorLogin = 'CUENTA_DESACTIVADA';
            self::registrarEvento($pdo, (int) $usuario['id_usuario'], null, $contexto['numero_serie'] ?? null, 'login_tecnico', 'error', self::$ultimoErrorLogin, $contexto['app_version'] ?? null);
            return null;
        }
        if (!in_array((string) $usuario['rol'], self::ROLES, true)) {
            self::$ultimoErrorLogin = 'Este usuario no puede activar maquinas. Asignale el rol Tecnico instalador o usa una cuenta Administrador.';
            self::$ultimoCodigoErrorLogin = 'ROL_SIN_PERMISO_ACTIVACION';
            self::registrarEvento($pdo, (int) $usuario['id_usuario'], null, $contexto['numero_serie'] ?? null, 'login_tecnico', 'error', self::$ultimoErrorLogin, $contexto['app_version'] ?? null);
            return null;
        }

        $token = 'TECH_' . bin2hex(random_bytes(32));
        // El token ya tiene 256 bits aleatorios. Un SHA-256 con comparación
        // constante es suficiente y evita falsos negativos entre el login y
        // la siguiente petición de activación. Se conserva compatibilidad con
        // hashes password_* emitidos por versiones anteriores.
        $hash = self::hashTokenSesion($token);

        // Una cuenta mantiene una sola sesión técnica vigente. Así la app no
        // puede reutilizar accidentalmente un token anterior tras otro login.
        $pdo->prepare(
            "UPDATE vendingm.android_tecnico_sesiones
             SET revocado = 1
             WHERE id_usuario = :id_usuario AND revocado = 0"
        )->execute(['id_usuario' => (int) $usuario['id_usuario']]);
        $stmtSesion = $pdo->prepare(
            "INSERT INTO vendingm.android_tecnico_sesiones
             (id_usuario, token_hash, fecha_expiracion, ip, user_agent)
             OUTPUT INSERTED.id_sesion, INSERTED.fecha_expiracion
             VALUES (:id_usuario, :token_hash, DATEADD(hour, :horas, SYSDATETIME()), :ip, :user_agent)"
        );
        $stmtSesion->bindValue('id_usuario', (int) $usuario['id_usuario'], PDO::PARAM_INT);
        $stmtSesion->bindValue('token_hash', $hash);
        $stmtSesion->bindValue('horas', self::HORAS_SESION, PDO::PARAM_INT);
        $stmtSesion->bindValue('ip', self::ip());
        $stmtSesion->bindValue('user_agent', self::userAgent());
        $stmtSesion->execute();
        $sesionCreada = $stmtSesion->fetch(PDO::FETCH_ASSOC);
        $idSesion = (int) ($sesionCreada['id_sesion'] ?? 0);
        $fechaExpiracion = $sesionCreada['fecha_expiracion'] ?? null;
        if ($fechaExpiracion instanceof \DateTimeInterface) {
            $fechaExpiracion = $fechaExpiracion->format('Y-m-d H:i:s');
        } else {
            $fechaExpiracion = substr(trim((string) $fechaExpiracion), 0, 19);
        }
        if ($idSesion <= 0 || $fechaExpiracion === '') {
            throw new \RuntimeException('No fue posible crear la sesion tecnica.');
        }

        self::registrarEvento($pdo, (int) $usuario['id_usuario'], $contexto['id_maquina'] ?? null, $contexto['numero_serie'] ?? null, 'login_tecnico', 'ok', null, $contexto['app_version'] ?? null);

        return [
            'token' => $token,
            'id_sesion' => $idSesion,
            'fecha_expiracion' => $fechaExpiracion,
            'usuario' => self::serializarUsuario($usuario),
            'permisos' => self::permisosPorRol((string) $usuario['rol']),
        ];
    }

    public static function ultimoErrorLogin(): string
    {
        return self::$ultimoErrorLogin;
    }

    public static function ultimoCodigoErrorLogin(): string
    {
        return self::$ultimoCodigoErrorLogin;
    }

    public static function autenticar(PDO $pdo, ?string $token = null): ?array
    {
        self::asegurarTablas($pdo);
        $token = $token !== null ? trim($token) : self::tokenDesdeRequest();
        if ($token === '' || !str_starts_with($token, 'TECH_')) {
            return null;
        }

        $stmt = $pdo->query(
            "SELECT s.id_sesion, s.id_usuario, s.token_hash, u.nombre, u.apellido, u.email, u.rol, u.activo
             FROM vendingm.android_tecnico_sesiones s
             INNER JOIN vendingm.usuarios u ON u.id_usuario = s.id_usuario
             WHERE s.revocado = 0
               AND s.fecha_expiracion > SYSDATETIME()
               AND u.activo = 1
             ORDER BY s.fecha_creacion DESC"
        );

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sesion) {
            if (!in_array((string) $sesion['rol'], self::ROLES, true)) {
                continue;
            }
            if (self::tokenCoincide($token, (string) $sesion['token_hash'])) {
                $pdo->prepare(
                    "UPDATE vendingm.android_tecnico_sesiones
                     SET fecha_ultimo_uso = SYSDATETIME()
                     WHERE id_sesion = :id"
                )->execute(['id' => (int) $sesion['id_sesion']]);

                return self::serializarUsuario($sesion) + ['id_sesion' => (int) $sesion['id_sesion']];
            }
        }

        return null;
    }

    private static function hashTokenSesion(string $token): string
    {
        return 'sha256:' . hash('sha256', $token);
    }

    private static function tokenCoincide(string $token, string $hashGuardado): bool
    {
        if (str_starts_with($hashGuardado, 'sha256:')) {
            return hash_equals($hashGuardado, self::hashTokenSesion($token));
        }

        return password_verify($token, $hashGuardado);
    }

    public static function registrarEvento(
        PDO $pdo,
        ?int $idUsuario,
        ?int $idMaquina,
        ?string $numeroSerie,
        string $accion,
        string $resultado = 'ok',
        ?string $mensaje = null,
        ?string $appVersion = null,
        ?array $payload = null
    ): void {
        self::asegurarTablas($pdo);
        $stmt = $pdo->prepare(
            "INSERT INTO vendingm.android_tecnico_eventos
             (id_usuario, id_maquina, numero_serie, accion, resultado, mensaje, app_version, payload_json, ip, user_agent)
             VALUES (:id_usuario, :id_maquina, :numero_serie, :accion, :resultado, :mensaje, :app_version, :payload_json, :ip, :user_agent)"
        );
        $stmt->execute([
            'id_usuario' => $idUsuario,
            'id_maquina' => $idMaquina,
            'numero_serie' => $numeroSerie,
            'accion' => substr($accion, 0, 80),
            'resultado' => substr($resultado, 0, 30),
            'mensaje' => $mensaje !== null ? substr($mensaje, 0, 800) : null,
            'app_version' => $appVersion !== null ? substr($appVersion, 0, 50) : null,
            'payload_json' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'ip' => self::ip(),
            'user_agent' => self::userAgent(),
        ]);
    }

    public static function permisosPorRol(string $rol): array
    {
        return [
            'puede_activar_maquina' => in_array($rol, ['admin', 'tecnico_instalador'], true),
            'puede_entrar_panel_tecnico' => in_array($rol, ['admin', 'tecnico_instalador', 'tecnico_soporte'], true),
            'puede_enviar_eventos_tecnicos' => true,
        ];
    }

    public static function tokenDesdeRequest(): string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $tokenTecnico = trim((string) ($headers['X-Tecnico-Token']
            ?? $headers['x-tecnico-token']
            ?? $_SERVER['HTTP_X_TECNICO_TOKEN']
            ?? ''));
        if ($tokenTecnico !== '') {
            return $tokenTecnico;
        }

        $auth = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private static function serializarUsuario(array $usuario): array
    {
        return [
            'id_usuario' => (int) $usuario['id_usuario'],
            'nombre' => trim((string) (($usuario['nombre'] ?? '') . ' ' . ($usuario['apellido'] ?? ''))),
            'email' => (string) $usuario['email'],
            'rol' => (string) $usuario['rol'],
        ];
    }

    private static function ip(): ?string
    {
        return isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 64) : null;
    }

    private static function userAgent(): ?string
    {
        return isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 300) : null;
    }
}
