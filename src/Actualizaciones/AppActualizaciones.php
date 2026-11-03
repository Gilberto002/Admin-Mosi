<?php
declare(strict_types=1);

namespace App\Actualizaciones;

use PDO;

final class AppActualizaciones
{
    public const CANAL_CLIENTES = 'cliente';
    public const CANAL_MOSI = 'mosi';

    public static function canalServidor(?string $canal = null): string
    {
        return self::normalizarCanal($canal);
    }

    public const EVENTO_CONSULTA = 'consulta';
    public const EVENTO_DESCARGA = 'descarga';
    public const EVENTO_INSTALACION = 'instalacion';
    public const EVENTO_ERROR = 'error';

    public const COMANDO_LIMPIAR_APKS_DISPOSITIVO = 'limpiar_apks_dispositivo';

    public static function asegurarTablas(PDO $pdo): void
    {
        $pdo->exec("
            IF OBJECT_ID('vendingm.app_actualizaciones', 'U') IS NULL
            BEGIN
                CREATE TABLE vendingm.app_actualizaciones (
                    id_actualizacion INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                    version_code INT NOT NULL,
                    version_name NVARCHAR(50) NOT NULL,
                    apk_url NVARCHAR(500) NOT NULL,
                    apk_archivo NVARCHAR(255) NULL,
                    sha256 CHAR(64) NOT NULL,
                    obligatoria BIT NOT NULL CONSTRAINT DF_app_actualizaciones_obligatoria DEFAULT 0,
                    activa BIT NOT NULL CONSTRAINT DF_app_actualizaciones_activa DEFAULT 1,
                    fecha_publicacion DATETIME2 NULL,
                    notas NVARCHAR(MAX) NULL,
                    fecha DATETIME2 NOT NULL CONSTRAINT DF_app_actualizaciones_fecha DEFAULT SYSDATETIME(),
                    fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_app_actualizaciones_creacion DEFAULT SYSDATETIME(),
                    fecha_actualizacion DATETIME2 NULL
                );
                CREATE INDEX IX_app_actualizaciones_activa_version
                    ON vendingm.app_actualizaciones (activa, version_code DESC, fecha DESC);
            END
        ");

        $pdo->exec("
            IF COL_LENGTH('vendingm.app_actualizaciones', 'fecha_publicacion') IS NULL
                ALTER TABLE vendingm.app_actualizaciones ADD fecha_publicacion DATETIME2 NULL;
        ");

        $pdo->exec("
            IF COL_LENGTH('vendingm.app_actualizaciones', 'apk_archivo') IS NULL
                ALTER TABLE vendingm.app_actualizaciones ADD apk_archivo NVARCHAR(255) NULL;
        ");

        $pdo->exec("
            IF COL_LENGTH('vendingm.app_actualizaciones', 'canal_app') IS NULL
                ALTER TABLE vendingm.app_actualizaciones ADD canal_app NVARCHAR(20) NOT NULL
                    CONSTRAINT DF_app_actualizaciones_canal DEFAULT 'cliente';
        ");

        $pdo->exec("
            IF OBJECT_ID('vendingm.app_actualizaciones_eventos', 'U') IS NULL
            BEGIN
                CREATE TABLE vendingm.app_actualizaciones_eventos (
                    id_evento INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                    id_actualizacion INT NULL,
                    id_maquina INT NULL,
                    numero_serie NVARCHAR(100) NULL,
                    tipo_evento NVARCHAR(30) NOT NULL,
                    version_code_actual INT NULL,
                    version_name_actual NVARCHAR(50) NULL,
                    version_code_objetivo INT NULL,
                    codigo_error NVARCHAR(120) NULL,
                    mensaje NVARCHAR(800) NULL,
                    request_id NVARCHAR(120) NULL,
                    payload_json NVARCHAR(MAX) NULL,
                    respuesta_json NVARCHAR(MAX) NULL,
                    fecha DATETIME2 NOT NULL CONSTRAINT DF_app_actualizaciones_eventos_fecha DEFAULT SYSDATETIME()
                );
                CREATE INDEX IX_app_actualizaciones_eventos_fecha
                    ON vendingm.app_actualizaciones_eventos (fecha DESC);
                CREATE INDEX IX_app_actualizaciones_eventos_maquina
                    ON vendingm.app_actualizaciones_eventos (id_maquina, fecha DESC);
            END
        ");

        $pdo->exec("
            IF COL_LENGTH('vendingm.app_actualizaciones_eventos', 'respuesta_json') IS NULL
                ALTER TABLE vendingm.app_actualizaciones_eventos ADD respuesta_json NVARCHAR(MAX) NULL;
        ");

        $pdo->exec("
            IF COL_LENGTH('vendingm.app_actualizaciones_eventos', 'canal_app') IS NULL
                ALTER TABLE vendingm.app_actualizaciones_eventos ADD canal_app NVARCHAR(20) NULL;
        ");
        $pdo->exec("
            IF COL_LENGTH('vendingm.app_actualizaciones_eventos', 'id_mosi') IS NULL
                ALTER TABLE vendingm.app_actualizaciones_eventos ADD id_mosi INT NULL;
            IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('vendingm.app_actualizaciones_eventos') AND name='IX_app_actualizaciones_eventos_mosi')
                CREATE INDEX IX_app_actualizaciones_eventos_mosi ON vendingm.app_actualizaciones_eventos(id_mosi, fecha DESC);
        ");

        self::asegurarTablaComandos($pdo);
    }

    public static function asegurarTablaComandos(PDO $pdo): void
    {
        $pdo->exec("
            IF OBJECT_ID('vendingm.app_comandos_android', 'U') IS NULL
            BEGIN
                CREATE TABLE vendingm.app_comandos_android (
                    id_comando INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                    id_maquina INT NOT NULL,
                    tipo_comando NVARCHAR(60) NOT NULL,
                    payload_json NVARCHAR(MAX) NULL,
                    estado NVARCHAR(20) NOT NULL CONSTRAINT DF_app_comandos_android_estado DEFAULT 'pendiente',
                    creado_por NVARCHAR(180) NULL,
                    fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_app_comandos_android_fecha DEFAULT SYSDATETIME(),
                    fecha_entregado DATETIME2 NULL,
                    fecha_resuelto DATETIME2 NULL,
                    resultado_json NVARCHAR(MAX) NULL
                );
                CREATE INDEX IX_app_comandos_android_maquina_estado
                    ON vendingm.app_comandos_android (id_maquina, estado, fecha_creacion DESC);
                CREATE INDEX IX_app_comandos_android_tipo_estado
                    ON vendingm.app_comandos_android (tipo_comando, estado, fecha_creacion DESC);
            END
        ");
    }

    public static function crearComandoTodasMaquinas(PDO $pdo, string $tipoComando, ?array $payload = null, ?string $creadoPor = null): int
    {
        self::asegurarTablaComandos($pdo);
        $payloadJson = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare(
            "SET NOCOUNT ON;
             DECLARE @insertados TABLE (id_comando INT);
             INSERT INTO vendingm.app_comandos_android (id_maquina, tipo_comando, payload_json, creado_por)
             OUTPUT INSERTED.id_comando INTO @insertados
             SELECT m.id_maquina, :tipo_comando, :payload_json, :creado_por
             FROM vendingm.maquinas m
             WHERE m.id_maquina IS NOT NULL
               AND NOT EXISTS (
                    SELECT 1
                    FROM vendingm.app_comandos_android c
                    WHERE c.id_maquina = m.id_maquina
                      AND c.tipo_comando = :tipo_comando_dup
                      AND c.estado = 'pendiente'
               );
             SELECT COUNT(1) FROM @insertados;"
        );
        $stmt->execute([
            'tipo_comando' => self::texto($tipoComando, 60),
            'tipo_comando_dup' => self::texto($tipoComando, 60),
            'payload_json' => $payloadJson,
            'creado_por' => self::texto($creadoPor, 180),
        ]);

        return (int) $stmt->fetchColumn();
    }

    public static function comandosPendientes(PDO $pdo, int $idMaquina): array
    {
        self::asegurarTablaComandos($pdo);
        $stmt = $pdo->prepare(
            "SELECT TOP 20 id_comando, tipo_comando, payload_json, fecha_creacion
             FROM vendingm.app_comandos_android
             WHERE id_maquina = :id_maquina
               AND estado = 'pendiente'
             ORDER BY fecha_creacion ASC, id_comando ASC"
        );
        $stmt->execute(['id_maquina' => $idMaquina]);
        $comandos = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payload = null;
            if (!empty($row['payload_json'])) {
                $payload = json_decode((string) $row['payload_json'], true);
                if (!is_array($payload)) {
                    $payload = null;
                }
            }
            $comandos[] = [
                'id_comando' => (int) $row['id_comando'],
                'tipo_comando' => (string) $row['tipo_comando'],
                'payload' => $payload,
                'fecha_creacion' => $row['fecha_creacion'],
            ];
        }

        if ($comandos) {
            $ids = implode(',', array_map(static fn(array $c): string => (string) (int) $c['id_comando'], $comandos));
            $pdo->exec("UPDATE vendingm.app_comandos_android SET fecha_entregado = COALESCE(fecha_entregado, SYSDATETIME()) WHERE id_comando IN ({$ids})");
        }

        return $comandos;
    }

    public static function resolverComando(PDO $pdo, int $idMaquina, int $idComando, string $estado, ?array $resultado = null): bool
    {
        self::asegurarTablaComandos($pdo);
        $estado = in_array($estado, ['completado', 'error', 'cancelado'], true) ? $estado : 'completado';
        $stmt = $pdo->prepare(
            "UPDATE vendingm.app_comandos_android
             SET estado = :estado,
                 fecha_resuelto = SYSDATETIME(),
                 resultado_json = :resultado_json
             WHERE id_comando = :id_comando
               AND id_maquina = :id_maquina
               AND estado = 'pendiente'"
        );
        $stmt->execute([
            'estado' => $estado,
            'resultado_json' => $resultado === null ? null : json_encode($resultado, JSON_UNESCAPED_UNICODE),
            'id_comando' => $idComando,
            'id_maquina' => $idMaquina,
        ]);

        return $stmt->rowCount() > 0;
    }

    public static function actualizacionPara(PDO $pdo, int $versionCodeActual, string $canal = self::CANAL_CLIENTES): ?array
    {
        self::asegurarTablas($pdo);
        $canal = self::normalizarCanal($canal);
        $stmt = $pdo->prepare(
            "SELECT TOP 1 id_actualizacion, canal_app, version_code, version_name, apk_url, apk_archivo, sha256,
                    obligatoria, activa, fecha_publicacion, notas, fecha
             FROM vendingm.app_actualizaciones
             WHERE activa = 1
               AND canal_app = :canal_app
               AND (fecha_publicacion IS NULL OR fecha_publicacion <= SYSDATETIME())
               AND version_code > :version_code_actual
             ORDER BY version_code DESC, fecha DESC"
        );
        $stmt->execute(['version_code_actual' => $versionCodeActual, 'canal_app' => $canal]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::normalizarActualizacion($row) : null;
    }

    public static function ultimaActiva(PDO $pdo, string $canal = self::CANAL_CLIENTES): ?array
    {
        self::asegurarTablas($pdo);
        $canal = self::normalizarCanal($canal);
        $stmt = $pdo->prepare(
            "SELECT TOP 1 id_actualizacion, canal_app, version_code, version_name, apk_url, apk_archivo, sha256,
                    obligatoria, activa, fecha_publicacion, notas, fecha
             FROM vendingm.app_actualizaciones
             WHERE activa = 1
               AND canal_app = :canal_app
               AND (fecha_publicacion IS NULL OR fecha_publicacion <= SYSDATETIME())
             ORDER BY version_code DESC, fecha DESC"
        );
        $stmt->execute(['canal_app' => $canal]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::normalizarActualizacion($row) : null;
    }

    public static function registrarEvento(PDO $pdo, array $datos): int
    {
        self::asegurarTablas($pdo);
        $stmt = $pdo->prepare(
            "INSERT INTO vendingm.app_actualizaciones_eventos
             (id_actualizacion, canal_app, id_maquina, id_mosi, numero_serie, tipo_evento, version_code_actual,
              version_name_actual, version_code_objetivo, codigo_error, mensaje, request_id, payload_json, respuesta_json)
             OUTPUT INSERTED.id_evento
             VALUES (:id_actualizacion, :canal_app, :id_maquina, :id_mosi, :numero_serie, :tipo_evento, :version_code_actual,
                     :version_name_actual, :version_code_objetivo, :codigo_error, :mensaje, :request_id, :payload_json, :respuesta_json)"
        );
        $stmt->execute([
            'id_actualizacion' => $datos['id_actualizacion'] ?? null,
            'canal_app' => self::normalizarCanal($datos['canal_app'] ?? null),
            'id_maquina' => $datos['id_maquina'] ?? null,
            'id_mosi' => $datos['id_mosi'] ?? null,
            'numero_serie' => self::texto($datos['numero_serie'] ?? null, 100),
            'tipo_evento' => self::texto($datos['tipo_evento'] ?? self::EVENTO_CONSULTA, 30),
            'version_code_actual' => $datos['version_code_actual'] ?? null,
            'version_name_actual' => self::texto($datos['version_name_actual'] ?? null, 50),
            'version_code_objetivo' => $datos['version_code_objetivo'] ?? null,
            'codigo_error' => self::texto($datos['codigo_error'] ?? null, 120),
            'mensaje' => self::texto($datos['mensaje'] ?? null, 800),
            'request_id' => self::texto($datos['request_id'] ?? null, 120),
            'payload_json' => isset($datos['payload_json'])
                ? (string) $datos['payload_json']
                : json_encode($datos, JSON_UNESCAPED_UNICODE),
            'respuesta_json' => isset($datos['respuesta_json']) ? (string) $datos['respuesta_json'] : null,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public static function normalizarActualizacion(array $row): array
    {
        $row['id_actualizacion'] = (int) $row['id_actualizacion'];
        $row['canal_app'] = self::normalizarCanal($row['canal_app'] ?? null);
        $row['version_code'] = (int) $row['version_code'];
        $row['obligatoria'] = (bool) $row['obligatoria'];
        $row['activa'] = (bool) $row['activa'];
        if (!empty($row['id_actualizacion']) && !empty($_SERVER['HTTP_HOST'])) {
            $row['apk_url'] = self::urlDescarga((int) $row['id_actualizacion'], $row['canal_app']);
        }
        return $row;
    }

    public static function normalizarCanal(?string $canal): string
    {
        $canal = strtolower(trim((string) $canal));
        return $canal === self::CANAL_MOSI ? self::CANAL_MOSI : self::CANAL_CLIENTES;
    }

    public static function urlDescarga(int $idActualizacion, string $canal = self::CANAL_CLIENTES): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api')), '/');
        $carpetaEntrada = strtolower((string) basename($base));
        if (in_array($carpetaEntrada, ['api', 'android_clientes'], true)) {
            $base = rtrim(str_replace('\\', '/', dirname($base)), '/');
        }

        return ($https ? 'https://' : 'http://') . $host . $base
            . '/api/app_actualizacion_descargar.php?id_actualizacion=' . $idActualizacion
            . '&canal_app=' . rawurlencode(self::normalizarCanal($canal));
    }

    private static function texto(mixed $valor, int $maximo): ?string
    {
        $texto = trim((string) ($valor ?? ''));
        if ($texto === '') {
            return null;
        }
        return substr($texto, 0, $maximo);
    }
}
