<?php
declare(strict_types=1);

namespace App\Api;

use App\Maquinas\ConexionMaquina;
use App\Maquinas\SegmentoMaquina;
use App\Security\Security;
use PDO;

final class AndroidApi
{
    public const ESTATUS_PENDIENTE = 1;
    public const ESTATUS_ENTREGADA = 2;
    public const ESTATUS_EN_PROCESO = 3;
    public const ESTATUS_PARCIAL = 4;
    public const ESTATUS_FALLIDA = 5;

    public static function responder(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function body(): array
    {
        Security::enforceJsonBodyLimit((int) ($_ENV['ANDROID_JSON_MAX_BYTES'] ?? 1048576));
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            self::responder(['ok' => false, 'error' => 'JSON invalido'], 400);
        }
        return $body;
    }

    public static function folio(array $body, bool $requerido = true): ?string
    {
        $folio = trim((string) ($body['folio'] ?? ''));
        if ($folio === '') {
            if ($requerido) {
                self::responder(['ok' => false, 'error' => 'Falta folio'], 422);
            }
            return null;
        }

        if (
            !preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $folio)
            && !preg_match('/^[0-9a-fA-F]{8,32}$/', str_replace('-', '', $folio))
        ) {
            self::responder(['ok' => false, 'error' => 'Folio invalido. El codigo debe traer 10 digitos o un UUID anterior'], 422);
        }

        return strtoupper($folio);
    }

    public static function folioCompacto(string $folio): string
    {
        return strtoupper(str_replace('-', '', trim($folio)));
    }

    public static function folioEsUuid(string $folio): bool
    {
        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $folio) === 1;
    }

    public static function folioWhereSql(string $columna = 'r.folio'): string
    {
        return "RIGHT(REPLACE(CONVERT(varchar(36), {$columna}), '-', ''), :folio_compacto_len) = :folio_compacto";
    }

    /**
     * Permite que Android lea codigos numericos nuevos y codigos cortos de folios UUID anteriores.
     * Siempre regresa el folio real guardado en la receta para usarlo en movimientos y eventos.
     */
    public static function resolverFolioReceta(PDO $pdo, string $folio, bool $forUpdate = false): string
    {
        \App\Vending\RecetaFolio::asegurarColumnas($pdo);

        if (self::folioEsUuid($folio)) {
            return strtolower($folio);
        }

        $lock = $forUpdate ? ' WITH (UPDLOCK, ROWLOCK)' : '';
        $stmtExacto = $pdo->prepare(
            "SELECT TOP 1 COALESCE(codigo_receta, CONVERT(varchar(36), folio)) AS folio
             FROM vendingm.recetas{$lock}
             WHERE codigo_receta = :folio_codigo OR CONVERT(varchar(36), folio) = :folio_uuid"
        );
        $stmtExacto->execute([
            'folio_codigo' => $folio,
            'folio_uuid' => $folio,
        ]);
        $folioExacto = $stmtExacto->fetchColumn();
        if ($folioExacto !== false) {
            return (string) $folioExacto;
        }

        $stmt = $pdo->prepare(
            "SELECT TOP 2 COALESCE(codigo_receta, CONVERT(varchar(36), folio)) AS folio
             FROM vendingm.recetas{$lock}
             WHERE " . self::folioWhereSql('folio') . "
             ORDER BY fecha_creacion DESC, id_receta DESC"
        );
        $stmt->execute([
            'folio_compacto' => self::folioCompacto($folio),
            'folio_compacto_len' => strlen(self::folioCompacto($folio)),
        ]);
        $folios = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$folios) {
            return strtolower($folio);
        }
        if (count($folios) > 1) {
            self::responder(['ok' => false, 'error' => 'Codigo de receta ambiguo. Escanea el folio completo'], 409);
        }
        return strtolower((string) $folios[0]);
    }

    public static function requirePost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::responder(['ok' => false, 'error' => 'Metodo no permitido'], 405);
        }
    }

    public static function autenticar(PDO $pdo, array $body): array
    {
        self::asegurarTablas($pdo);
        SegmentoMaquina::asegurarColumnas($pdo);

        $tokenRecibido = self::tokenRecibido();
        if ($tokenRecibido === '') {
            self::responder(['ok' => false, 'error' => 'Token requerido'], 401);
        }

        $idMaquina = filter_var($body['id_maquina'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $numeroSerie = trim((string) ($body['numero_serie'] ?? ''));

        if (!$idMaquina && $numeroSerie === '') {
            self::validarTokenGlobal($tokenRecibido);
            return [];
        }

        $maquina = self::buscarMaquina($pdo, $idMaquina, $numeroSerie);
        if (!$maquina) {
            self::responder(['ok' => false, 'error' => 'Maquina no encontrada'], 404);
        }
        SegmentoMaquina::requerirSiAplica($maquina);

        $stmt = $pdo->prepare(
            "SELECT TOP 1 token_hash, revocado
             FROM vendingm.api_tokens_maquina
             WHERE id_maquina = :id
             ORDER BY fecha_creacion DESC"
        );
        $stmt->execute(['id' => $maquina['id_maquina']]);
        $tokenMaquina = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($tokenMaquina) {
            if ((bool) $tokenMaquina['revocado']) {
                self::responder(['ok' => false, 'error' => 'Maquina revocada'], 403);
            }
            if (!password_verify($tokenRecibido, (string) $tokenMaquina['token_hash'])) {
                self::responder(['ok' => false, 'error' => 'Token invalido'], 401);
            }
            self::actualizarPing($pdo, (int) $maquina['id_maquina']);
            return $maquina;
        }

        self::validarTokenGlobal($tokenRecibido);
        self::actualizarPing($pdo, (int) $maquina['id_maquina']);
        return $maquina;
    }

    public static function buscarMaquina(PDO $pdo, ?int $idMaquina, string $numeroSerie = ''): ?array
    {
        if ($idMaquina && $numeroSerie !== '') {
            $stmt = $pdo->prepare(
                "SELECT id_maquina, numero_serie, codigo_interno, nombre_ubicacion, rol_maquina, id_maquina_padre, id_estatus,
                        segmento_operacion
                 FROM vendingm.maquinas
                 WHERE id_maquina = :id AND numero_serie = :serie"
            );
            $stmt->execute(['id' => $idMaquina, 'serie' => $numeroSerie]);
        } elseif ($idMaquina) {
            $stmt = $pdo->prepare(
                "SELECT id_maquina, numero_serie, codigo_interno, nombre_ubicacion, rol_maquina, id_maquina_padre, id_estatus,
                        segmento_operacion
                 FROM vendingm.maquinas
                 WHERE id_maquina = :id"
            );
            $stmt->execute(['id' => $idMaquina]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT id_maquina, numero_serie, codigo_interno, nombre_ubicacion, rol_maquina, id_maquina_padre, id_estatus,
                        segmento_operacion
                 FROM vendingm.maquinas
                 WHERE numero_serie = :serie"
            );
            $stmt->execute(['serie' => $numeroSerie]);
        }

        $maquina = $stmt->fetch(PDO::FETCH_ASSOC);
        return $maquina ?: null;
    }

    public static function validarTokenGlobalRecibido(): void
    {
        $tokenRecibido = self::tokenRecibido();
        if ($tokenRecibido === '') {
            self::responder(['ok' => false, 'error' => 'Token requerido'], 401);
        }
        self::validarTokenGlobal($tokenRecibido);
    }

    public static function asegurarTablas(PDO $pdo): void
    {
        $pdo->exec("
            IF OBJECT_ID('vendingm.api_tokens_maquina', 'U') IS NULL
            BEGIN
                CREATE TABLE vendingm.api_tokens_maquina (
                    id_token INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                    id_maquina INT NOT NULL,
                    token_hash NVARCHAR(255) NOT NULL,
                    revocado BIT NOT NULL CONSTRAINT DF_api_tokens_revocado DEFAULT 0,
                    fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_api_tokens_fecha DEFAULT SYSDATETIME(),
                    fecha_revocacion DATETIME2 NULL,
                    ultimo_uso DATETIME2 NULL
                );
                CREATE INDEX IX_api_tokens_maquina ON vendingm.api_tokens_maquina (id_maquina, revocado);
            END
        ");

        $pdo->exec("
            IF OBJECT_ID('vendingm.api_eventos_android', 'U') IS NULL
            BEGIN
                CREATE TABLE vendingm.api_eventos_android (
                    id_evento INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                    request_id NVARCHAR(120) NOT NULL,
                    tipo NVARCHAR(40) NOT NULL,
                    response_json NVARCHAR(MAX) NOT NULL,
                    fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_api_android_fecha DEFAULT SYSDATETIME(),
                    CONSTRAINT UQ_api_eventos_android_request UNIQUE (request_id)
                );
            END
        ");

        $pdo->exec("
            IF OBJECT_ID('vendingm.api_errores_surtido', 'U') IS NULL
            BEGIN
                CREATE TABLE vendingm.api_errores_surtido (
                    id_error INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                    id_maquina INT NULL,
                    folio NVARCHAR(120) NULL,
                    numero_carril INT NULL,
                    codigo_barras NVARCHAR(80) NULL,
                    codigo_error NVARCHAR(80) NOT NULL,
                    mensaje NVARCHAR(500) NULL,
                    request_id NVARCHAR(120) NULL,
                    payload_json NVARCHAR(MAX) NULL,
                    fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_api_error_surtido_fecha DEFAULT SYSDATETIME()
                );
                CREATE INDEX IX_api_errores_surtido_fecha ON vendingm.api_errores_surtido (fecha_creacion);
            END
        ");

        $pdo->exec("
            IF OBJECT_ID('vendingm.api_telemetria_android', 'U') IS NULL
            BEGIN
                CREATE TABLE vendingm.api_telemetria_android (
                    id_telemetria INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                    id_maquina INT NULL,
                    numero_serie NVARCHAR(100) NULL,
                    app_version NVARCHAR(50) NULL,
                    battery_level DECIMAL(5,2) NULL,
                    network_type NVARCHAR(50) NULL,
                    signal_strength INT NULL,
                    payload_json NVARCHAR(MAX) NULL,
                    fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_api_telemetria_fecha DEFAULT SYSDATETIME()
                );
                CREATE INDEX IX_api_telemetria_fecha ON vendingm.api_telemetria_android (fecha_creacion);
            END
        ");

        self::asegurarEstatusReceta($pdo);
    }

    public static function eventoPrevio(PDO $pdo, string $requestId): ?array
    {
        if ($requestId === '') {
            return null;
        }
        $stmt = $pdo->prepare('SELECT response_json FROM vendingm.api_eventos_android WHERE request_id = :request_id');
        $stmt->execute(['request_id' => $requestId]);
        $json = $stmt->fetchColumn();
        if ($json === false) {
            return null;
        }
        $payload = json_decode((string) $json, true);
        if (!is_array($payload)) {
            return ['ok' => true, 'data' => ['request_id' => $requestId]];
        }
        $payload['meta']['duplicado'] = true;
        return $payload;
    }

    public static function guardarEvento(PDO $pdo, string $requestId, string $tipo, array $payload): void
    {
        if ($requestId === '') {
            return;
        }
        $stmt = $pdo->prepare(
            "INSERT INTO vendingm.api_eventos_android (request_id, tipo, response_json)
             VALUES (:request_id, :tipo, :response_json)"
        );
        $stmt->execute([
            'request_id' => $requestId,
            'tipo' => $tipo,
            'response_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public static function guardarEventoReemplazar(PDO $pdo, string $requestId, string $tipo, array $payload): void
    {
        if ($requestId === '') {
            return;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $upd = $pdo->prepare(
            "UPDATE vendingm.api_eventos_android
             SET tipo = :tipo, response_json = :response_json
             WHERE request_id = :request_id"
        );
        $upd->execute([
            'request_id' => $requestId,
            'tipo' => $tipo,
            'response_json' => $json,
        ]);

        if ($upd->rowCount() > 0) {
            return;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO vendingm.api_eventos_android (request_id, tipo, response_json)
             VALUES (:request_id, :tipo, :response_json)"
        );
        $stmt->execute([
            'request_id' => $requestId,
            'tipo' => $tipo,
            'response_json' => $json,
        ]);
    }

    private static function tokenRecibido(): string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', (string) $auth, $m)) {
            return trim($m[1]);
        }
        return trim((string) ($headers['X-Android-Token'] ?? $headers['x-android-token'] ?? ''));
    }

    private static function validarTokenGlobal(string $tokenRecibido): void
    {
        $tokenConfigurado = trim((string) ($_ENV['ANDROID_API_TOKEN'] ?? getenv('ANDROID_API_TOKEN') ?: ''));
        if ($tokenConfigurado === '') {
            self::responder(['ok' => false, 'error' => 'ANDROID_API_TOKEN no esta configurado en el servidor'], 503);
        }
        if (!hash_equals($tokenConfigurado, $tokenRecibido)) {
            self::responder(['ok' => false, 'error' => 'Token invalido'], 401);
        }
    }

    private static function actualizarPing(PDO $pdo, int $idMaquina): void
    {
        ConexionMaquina::asegurarColumnas($pdo);
        $stmt = $pdo->prepare(
            "UPDATE vendingm.api_tokens_maquina
             SET ultimo_uso = SYSDATETIME()
             WHERE id_maquina = :id AND revocado = 0"
        );
        $stmt->execute(['id' => $idMaquina]);

        $stmtMaquina = $pdo->prepare(
            "UPDATE m
             SET ultima_conexion = SYSDATETIME(),
                 fecha_actualizacion = SYSDATETIME()
             FROM vendingm.maquinas m
             CROSS APPLY (
                 SELECT CASE
                          WHEN base.rol_maquina = 2 AND base.id_maquina_padre IS NOT NULL
                              THEN base.id_maquina_padre
                          ELSE base.id_maquina
                        END AS id_unidad
                 FROM vendingm.maquinas base
                 WHERE base.id_maquina = :id_base
             ) unidad
             WHERE m.id_maquina = unidad.id_unidad
                OR m.id_maquina_padre = unidad.id_unidad"
        );
        $stmtMaquina->execute(['id_base' => $idMaquina]);
    }

    private static function asegurarEstatusReceta(PDO $pdo): void
    {
        $pdo->exec("
            IF OBJECT_ID('vendingm.estatus_receta', 'U') IS NOT NULL
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM vendingm.estatus_receta WHERE id_estatus = 3)
                    INSERT INTO vendingm.estatus_receta (id_estatus, nombre) VALUES (3, 'En proceso');
                IF NOT EXISTS (SELECT 1 FROM vendingm.estatus_receta WHERE id_estatus = 4)
                    INSERT INTO vendingm.estatus_receta (id_estatus, nombre) VALUES (4, 'Parcial');
                IF NOT EXISTS (SELECT 1 FROM vendingm.estatus_receta WHERE id_estatus = 5)
                    INSERT INTO vendingm.estatus_receta (id_estatus, nombre) VALUES (5, 'Fallida');
            END
        ");
    }
}
