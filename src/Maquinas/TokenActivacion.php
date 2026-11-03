<?php
declare(strict_types=1);

namespace App\Maquinas;

use App\Api\AndroidApi;
use PDO;

final class TokenActivacion
{
    public static function asegurarTabla(PDO $pdo): void
    {
        $pdo->exec("
            IF OBJECT_ID('vendingm.android_tokens_activacion', 'U') IS NULL
            BEGIN
                CREATE TABLE vendingm.android_tokens_activacion (
                    id_token_activacion INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                    token_hash NVARCHAR(255) NOT NULL,
                    descripcion NVARCHAR(160) NULL,
                    numero_serie NVARCHAR(100) NULL,
                    usos_max INT NOT NULL CONSTRAINT DF_android_tokens_activacion_usos_max DEFAULT 1,
                    usos_actuales INT NOT NULL CONSTRAINT DF_android_tokens_activacion_usos_actuales DEFAULT 0,
                    revocado BIT NOT NULL CONSTRAINT DF_android_tokens_activacion_revocado DEFAULT 0,
                    fecha_expiracion DATETIME2 NOT NULL,
                    fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_android_tokens_activacion_creacion DEFAULT SYSDATETIME(),
                    fecha_revocacion DATETIME2 NULL,
                    fecha_ultimo_uso DATETIME2 NULL
                );
                CREATE INDEX IX_android_tokens_activacion_activos
                    ON vendingm.android_tokens_activacion (revocado, fecha_expiracion, usos_actuales);
            END
        ");
    }

    public static function generar(PDO $pdo, int $horasVigencia, int $usosMax, ?string $numeroSerie = null, ?string $descripcion = null): array
    {
        self::asegurarTabla($pdo);
        $horasVigencia = max(1, min(168, $horasVigencia));
        $usosMax = max(1, min(500, $usosMax));
        $token = 'ACT-' . implode('-', [
            self::bloque(),
            self::bloque(),
            self::bloque(),
            self::bloque(),
        ]);
        $hash = password_hash($token, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare(
            "INSERT INTO vendingm.android_tokens_activacion
             (token_hash, descripcion, numero_serie, usos_max, fecha_expiracion)
             OUTPUT INSERTED.id_token_activacion, INSERTED.fecha_expiracion
             VALUES (:hash, :descripcion, :numero_serie, :usos_max, DATEADD(hour, :horas, SYSDATETIME()))"
        );
        $stmt->execute([
            'hash' => $hash,
            'descripcion' => self::texto($descripcion, 160),
            'numero_serie' => self::texto($numeroSerie, 100),
            'usos_max' => $usosMax,
            'horas' => $horasVigencia,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'id_token_activacion' => (int) $row['id_token_activacion'],
            'token' => $token,
            'usos_max' => $usosMax,
            'fecha_expiracion' => $row['fecha_expiracion'],
        ];
    }

    public static function validarRecibido(PDO $pdo, array $body, string $numeroSerie, bool $consumir): string
    {
        self::asegurarTabla($pdo);
        $token = self::tokenRecibido($body);
        if ($token === '') {
            AndroidApi::responder(['ok' => false, 'error' => 'Token temporal de activacion requerido'], 401);
        }

        if (self::tokenGlobalValido($token)) {
            return 'global';
        }

        $stmt = $pdo->prepare(
            "SELECT id_token_activacion, token_hash, numero_serie, usos_max, usos_actuales
             FROM vendingm.android_tokens_activacion
             WHERE revocado = 0
               AND fecha_expiracion > SYSDATETIME()
               AND usos_actuales < usos_max
             ORDER BY fecha_creacion DESC"
        );
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!password_verify($token, (string) $row['token_hash'])) {
                continue;
            }
            $serieToken = trim((string) ($row['numero_serie'] ?? ''));
            if ($serieToken !== '' && strcasecmp($serieToken, $numeroSerie) !== 0) {
                AndroidApi::responder(['ok' => false, 'error' => 'Token de activacion no corresponde a este numero de serie'], 403);
            }
            if ($consumir) {
                $upd = $pdo->prepare(
                    "UPDATE vendingm.android_tokens_activacion
                     SET usos_actuales = usos_actuales + 1,
                         fecha_ultimo_uso = SYSDATETIME(),
                         revocado = CASE WHEN usos_actuales + 1 >= usos_max THEN 1 ELSE revocado END,
                         fecha_revocacion = CASE WHEN usos_actuales + 1 >= usos_max THEN SYSDATETIME() ELSE fecha_revocacion END
                     WHERE id_token_activacion = :id
                       AND revocado = 0
                       AND fecha_expiracion > SYSDATETIME()
                       AND usos_actuales < usos_max"
                );
                $upd->execute(['id' => (int) $row['id_token_activacion']]);
                if ($upd->rowCount() < 1) {
                    AndroidApi::responder(['ok' => false, 'error' => 'Token de activacion ya fue usado'], 409);
                }
            } else {
                $pdo->prepare(
                    "UPDATE vendingm.android_tokens_activacion
                     SET fecha_ultimo_uso = SYSDATETIME()
                     WHERE id_token_activacion = :id"
                )->execute(['id' => (int) $row['id_token_activacion']]);
            }

            return 'temporal';
        }

        AndroidApi::responder(['ok' => false, 'error' => 'Token de activacion invalido o vencido'], 401);
    }

    private static function tokenRecibido(array $body): string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', (string) $auth, $m)) {
            return trim($m[1]);
        }
        foreach (['X-Android-Activation-Token', 'x-android-activation-token', 'X-Android-Token', 'x-android-token'] as $header) {
            if (!empty($headers[$header])) {
                return trim((string) $headers[$header]);
            }
        }

        return trim((string) ($body['token_activacion'] ?? $body['activation_token'] ?? ''));
    }

    private static function tokenGlobalValido(string $token): bool
    {
        $global = trim((string) ($_ENV['ANDROID_API_TOKEN'] ?? getenv('ANDROID_API_TOKEN') ?: ''));
        return $global !== '' && hash_equals($global, $token);
    }

    private static function bloque(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $texto = '';
        for ($i = 0; $i < 4; $i++) {
            $texto .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        return $texto;
    }

    private static function texto(?string $valor, int $maximo): ?string
    {
        $texto = trim((string) $valor);
        return $texto === '' ? null : substr($texto, 0, $maximo);
    }
}
