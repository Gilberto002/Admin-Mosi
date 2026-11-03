<?php

declare(strict_types=1);

namespace App\Telemedicina;

use PDO;
use RuntimeException;

final class MosiApi
{
    public static function asegurarTablas(PDO $pdo): void
    {
        Schema::ensure($pdo);
        $pdo->exec(<<<'SQL'
IF OBJECT_ID('vendingm.telemedicina_mosi_tokens_activacion', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.telemedicina_mosi_tokens_activacion (
        id_activacion BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        id_mosi INT NOT NULL,
        codigo_hash NVARCHAR(255) NOT NULL,
        usos_maximos INT NOT NULL CONSTRAINT DF_tm_act_usos_max DEFAULT 1,
        usos_actuales INT NOT NULL CONSTRAINT DF_tm_act_usos DEFAULT 0,
        fecha_expiracion DATETIME2 NOT NULL,
        revocado BIT NOT NULL CONSTRAINT DF_tm_act_revocado DEFAULT 0,
        creado_por INT NULL,
        fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_tm_act_creacion DEFAULT SYSDATETIME(),
        fecha_ultimo_uso DATETIME2 NULL,
        CONSTRAINT FK_tm_act_mosi FOREIGN KEY (id_mosi) REFERENCES vendingm.telemedicina_mosi(id_mosi) ON DELETE CASCADE
    );
    CREATE INDEX IX_tm_act_mosi_fecha ON vendingm.telemedicina_mosi_tokens_activacion(id_mosi, fecha_creacion DESC);
END;

IF OBJECT_ID('vendingm.telemedicina_mosi_api_tokens', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.telemedicina_mosi_api_tokens (
        id_token BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        id_mosi INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        device_id NVARCHAR(100) NULL,
        android_id NVARCHAR(100) NULL,
        package_name NVARCHAR(180) NULL,
        certificado_sha256 CHAR(64) NULL,
        revocado BIT NOT NULL CONSTRAINT DF_tm_token_revocado DEFAULT 0,
        ultimo_uso DATETIME2 NULL,
        fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_tm_token_creacion DEFAULT SYSDATETIME(),
        fecha_revocacion DATETIME2 NULL,
        CONSTRAINT UQ_tm_token_hash UNIQUE(token_hash),
        CONSTRAINT FK_tm_token_mosi FOREIGN KEY (id_mosi) REFERENCES vendingm.telemedicina_mosi(id_mosi) ON DELETE CASCADE
    );
    CREATE INDEX IX_tm_token_mosi ON vendingm.telemedicina_mosi_api_tokens(id_mosi, revocado, fecha_creacion DESC);
END;

IF OBJECT_ID('vendingm.telemedicina_mosi_telemetria', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.telemedicina_mosi_telemetria (
        id_telemetria BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        id_mosi INT NOT NULL,
        request_id NVARCHAR(120) NULL,
        version_code INT NULL,
        version_name NVARCHAR(60) NULL,
        tipo_conexion NVARCHAR(50) NULL,
        internet_disponible BIT NULL,
        wifi_conectado BIT NULL,
        ethernet_conectado BIT NULL,
        pendientes_sincronizacion INT NULL,
        errores_recientes INT NULL,
        ultima_sincronizacion DATETIME2 NULL,
        payload_json NVARCHAR(MAX) NULL,
        fecha_recepcion DATETIME2 NOT NULL CONSTRAINT DF_tm_tel_fecha DEFAULT SYSDATETIME(),
        CONSTRAINT FK_tm_tel_mosi FOREIGN KEY (id_mosi) REFERENCES vendingm.telemedicina_mosi(id_mosi) ON DELETE CASCADE
    );
    CREATE INDEX IX_tm_tel_mosi_fecha ON vendingm.telemedicina_mosi_telemetria(id_mosi, fecha_recepcion DESC);
    CREATE UNIQUE INDEX UX_tm_tel_request ON vendingm.telemedicina_mosi_telemetria(id_mosi, request_id) WHERE request_id IS NOT NULL;
END;

IF COL_LENGTH('vendingm.telemedicina_mosi', 'version_code') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD version_code INT NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'ultima_sincronizacion') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD ultima_sincronizacion DATETIME2 NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'device_owner') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD device_owner BIT NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'id_sucursal_clinica') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD id_sucursal_clinica INT NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'videoloop_host') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD videoloop_host NVARCHAR(100) NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'videoloop_port') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD videoloop_port INT NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'videoloop_email') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD videoloop_email NVARCHAR(180) NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'videoloop_clave_sha256') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD videoloop_clave_sha256 CHAR(64) NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'impresora_ip') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD impresora_ip NVARCHAR(100) NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'impresora_puerto') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD impresora_puerto INT NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'instalada_por') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD instalada_por INT NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'fecha_instalacion') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD fecha_instalacion DATETIME2 NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi_eventos', 'request_id') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi_eventos ADD request_id NVARCHAR(120) NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi_eventos', 'sesion_id') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi_eventos ADD sesion_id NVARCHAR(120) NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi_eventos', 'tipo_evento') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi_eventos ADD tipo_evento NVARCHAR(40) NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('vendingm.telemedicina_mosi_eventos') AND name='UX_tm_evento_request')
    EXEC(N'CREATE UNIQUE INDEX UX_tm_evento_request ON vendingm.telemedicina_mosi_eventos(id_mosi,request_id) WHERE request_id IS NOT NULL');
SQL);
    }

    public static function crearCodigoActivacion(PDO $pdo, int $idMosi, int $creadoPor, int $horas = 24): array
    {
        self::asegurarTablas($pdo);
        $horas = max(1, min(168, $horas));
        $stmt = $pdo->prepare("SELECT numero_serie FROM vendingm.telemedicina_mosi WHERE id_mosi=:id AND estatus<>'baja'");
        $stmt->execute(['id' => $idMosi]);
        $serie = $stmt->fetchColumn();
        if ($serie === false) {
            throw new RuntimeException('La MOSI no existe o esta dada de baja.');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE vendingm.telemedicina_mosi_tokens_activacion SET revocado=1 WHERE id_mosi=:id AND revocado=0 AND usos_actuales<usos_maximos")
                ->execute(['id' => $idMosi]);
            $secreto = self::base64Url(random_bytes(24));
            $insert = $pdo->prepare(
                "INSERT INTO vendingm.telemedicina_mosi_tokens_activacion
                 (id_mosi,codigo_hash,fecha_expiracion,creado_por)
                 OUTPUT INSERTED.id_activacion, CONVERT(NVARCHAR(33),INSERTED.fecha_expiracion,126)
                 VALUES (:id,:hash,DATEADD(hour,CAST(:horas AS INT),SYSDATETIME()),:creado_por)"
            );
            $insert->bindValue('id', $idMosi, PDO::PARAM_INT);
            $insert->bindValue('hash', password_hash($secreto, PASSWORD_DEFAULT));
            $insert->bindValue('horas', $horas, PDO::PARAM_INT);
            $insert->bindValue('creado_por', $creadoPor, PDO::PARAM_INT);
            $insert->execute();
            $row = $insert->fetch(PDO::FETCH_NUM);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return [
            'codigo_activacion' => 'MOSI-ACT-' . (int) $row[0] . '.' . $secreto,
            'numero_serie' => (string) $serie,
            'expira_en' => $row[1],
        ];
    }

    public static function activar(PDO $pdo, string $codigo, string $serie, array $dispositivo): array
    {
        self::asegurarTablas($pdo);
        if (!preg_match('/^MOSI-ACT-(\d+)\.([A-Za-z0-9_-]{20,})$/', trim($codigo), $m)) {
            throw new RuntimeException('Codigo de activacion invalido.');
        }
        $serie = strtoupper(trim($serie));
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT a.id_activacion,a.id_mosi,a.codigo_hash,m.numero_serie,m.nombre
                 FROM vendingm.telemedicina_mosi_tokens_activacion a WITH (UPDLOCK,ROWLOCK)
                 INNER JOIN vendingm.telemedicina_mosi m ON m.id_mosi=a.id_mosi
                 WHERE a.id_activacion=:id AND a.revocado=0 AND a.usos_actuales<a.usos_maximos
                   AND a.fecha_expiracion>SYSDATETIME() AND m.estatus<>'baja'"
            );
            $stmt->execute(['id' => (int) $m[1]]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !password_verify($m[2], (string) $row['codigo_hash']) || strtoupper(trim((string) $row['numero_serie'])) !== $serie) {
                throw new RuntimeException('Codigo invalido, vencido o asignado a otra MOSI.');
            }

            $idMosi = (int) $row['id_mosi'];
            $token = 'MLMOSI_' . self::base64Url(random_bytes(32));
            $pdo->prepare("UPDATE vendingm.telemedicina_mosi_api_tokens SET revocado=1,fecha_revocacion=SYSDATETIME() WHERE id_mosi=:id AND revocado=0")
                ->execute(['id' => $idMosi]);
            $insert = $pdo->prepare(
                "INSERT INTO vendingm.telemedicina_mosi_api_tokens
                 (id_mosi,token_hash,device_id,android_id,package_name,certificado_sha256)
                 VALUES (:id,:hash,:device_id,:android_id,:package_name,:certificado)"
            );
            $insert->execute([
                'id' => $idMosi,
                'hash' => hash('sha256', $token),
                'device_id' => self::texto($dispositivo['device_id'] ?? null, 100),
                'android_id' => self::texto($dispositivo['android_id'] ?? null, 100),
                'package_name' => self::texto($dispositivo['package_name'] ?? null, 180),
                'certificado' => self::sha256OrNull($dispositivo['certificado_sha256'] ?? null),
            ]);
            $pdo->prepare("UPDATE vendingm.telemedicina_mosi_tokens_activacion SET usos_actuales=usos_actuales+1,fecha_ultimo_uso=SYSDATETIME() WHERE id_activacion=:id")
                ->execute(['id' => (int) $row['id_activacion']]);
            $pdo->prepare("UPDATE vendingm.telemedicina_mosi SET identificador_dispositivo=COALESCE(:device_id,identificador_dispositivo),fecha_actualizacion=SYSDATETIME() WHERE id_mosi=:id")
                ->execute(['device_id' => self::texto($dispositivo['device_id'] ?? null, 100), 'id' => $idMosi]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return ['token' => $token, 'id_mosi' => $idMosi, 'numero_serie' => (string) $row['numero_serie'], 'nombre' => (string) $row['nombre']];
    }

    /** Activa y configura una cabina después de autenticar a un instalador autorizado. */
    public static function activarPorInstalador(
        PDO $pdo,
        string $serie,
        int $idSucursal,
        int $idInstalador,
        array $dispositivo,
        array $configuracion
    ): array {
        self::asegurarTablas($pdo);
        $serie = strtoupper(trim($serie));
        if ($serie === '' || $idSucursal <= 0 || $idInstalador <= 0) {
            throw new RuntimeException('Serie, sucursal e instalador son obligatorios.');
        }

        $host = self::texto($configuracion['videoloop_host'] ?? null, 100);
        $clave = trim((string) ($configuracion['videoloop_clave'] ?? ''));
        $email = self::texto($configuracion['videoloop_email'] ?? null, 180);
        $impresoraIp = self::texto($configuracion['impresora_ip'] ?? null, 100);
        $clienteId = self::texto($configuracion['id_cliente'] ?? null, 100);
        $clienteNombre = self::texto($configuracion['cliente_nombre'] ?? null, 200);
        $videoloopPort = (int) ($configuracion['videoloop_port'] ?? 8080);
        $impresoraPuerto = (int) ($configuracion['impresora_puerto'] ?? 631);
        if ($host === null || $clave === '') throw new RuntimeException('La IP y la clave de VideoLoop son obligatorias.');
        if ($clienteId === null || $clienteNombre === null) {
            throw new RuntimeException('El cliente y la sucursal son obligatorios.');
        }
        if ($videoloopPort < 1 || $videoloopPort > 65535 || $impresoraPuerto < 1 || $impresoraPuerto > 65535) {
            throw new RuntimeException('Puerto de VideoLoop o impresora invalido.');
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El correo de VideoLoop no es valido.');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT id_mosi,numero_serie,nombre FROM vendingm.telemedicina_mosi WITH (UPDLOCK,ROWLOCK)
                 WHERE UPPER(numero_serie)=:serie AND estatus<>'baja'"
            );
            $stmt->execute(['serie' => $serie]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('La MOSI no esta preregistrada o esta dada de baja.');

            $sucursal = $pdo->prepare(
                'SELECT st_Nombre,id_cliente FROM fernandoruiz.cat_SucursalClinica WHERE id_SucursalClinica=:id AND i_Activo=1'
            );
            $sucursal->execute(['id' => $idSucursal]);
            $filaSucursal = $sucursal->fetch(PDO::FETCH_ASSOC);
            $nombreSucursal = trim((string) ($filaSucursal['st_Nombre'] ?? ''));
            if ($nombreSucursal === '') throw new RuntimeException('La sucursal seleccionada no esta activa.');
            $clienteSucursal = trim((string) ($filaSucursal['id_cliente'] ?? ''));
            if ($clienteSucursal !== '' && $clienteSucursal !== $clienteId) {
                throw new RuntimeException('La sucursal seleccionada no pertenece al cliente indicado.');
            }

            $idMosi = (int) $row['id_mosi'];
            $token = 'MLMOSI_' . self::base64Url(random_bytes(32));
            $pdo->prepare("UPDATE vendingm.telemedicina_mosi_api_tokens SET revocado=1,fecha_revocacion=SYSDATETIME() WHERE id_mosi=:id AND revocado=0")
                ->execute(['id' => $idMosi]);
            $pdo->prepare(
                "INSERT INTO vendingm.telemedicina_mosi_api_tokens
                 (id_mosi,token_hash,device_id,android_id,package_name,certificado_sha256)
                 VALUES (:id,:hash,:device_id,:android_id,:package_name,:certificado)"
            )->execute([
                'id' => $idMosi,
                'hash' => hash('sha256', $token),
                'device_id' => self::texto($dispositivo['device_id'] ?? null, 100),
                'android_id' => self::texto($dispositivo['android_id'] ?? null, 100),
                'package_name' => self::texto($dispositivo['package_name'] ?? null, 180),
                'certificado' => self::sha256OrNull($dispositivo['certificado_sha256'] ?? null),
            ]);
            $pdo->prepare(
                "UPDATE vendingm.telemedicina_mosi SET
                    id_cliente_externo=:cliente_id, cliente_nombre=:cliente_nombre,
                    id_sucursal_clinica=:id_sucursal, id_sucursal_externa=:sucursal_texto,
                    sucursal_nombre=:sucursal_nombre, videoloop_host=:vl_host,
                    videoloop_port=:vl_port, videoloop_email=:vl_email,
                    videoloop_clave_sha256=:vl_hash, impresora_ip=:printer_ip,
                    impresora_puerto=:printer_port, instalada_por=:instalador,
                    fecha_instalacion=SYSDATETIME(), identificador_dispositivo=:device_id,
                    fecha_actualizacion=SYSDATETIME()
                 WHERE id_mosi=:id"
            )->execute([
                'cliente_id' => $clienteId,
                'cliente_nombre' => $clienteNombre,
                'id_sucursal' => $idSucursal,
                'sucursal_texto' => (string) $idSucursal,
                'sucursal_nombre' => $nombreSucursal,
                'vl_host' => $host,
                'vl_port' => $videoloopPort,
                'vl_email' => $email,
                'vl_hash' => hash('sha256', $clave),
                'printer_ip' => $impresoraIp,
                'printer_port' => $impresoraPuerto,
                'instalador' => $idInstalador,
                'device_id' => self::texto($dispositivo['device_id'] ?? null, 100),
                'id' => $idMosi,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return [
            'token' => $token,
            'id_mosi' => $idMosi,
            'numero_serie' => (string) $row['numero_serie'],
            'nombre' => (string) $row['nombre'],
            'cliente' => ['id' => $clienteId, 'nombre' => $clienteNombre],
            'sucursal' => ['id' => $idSucursal, 'nombre' => $nombreSucursal],
        ];
    }

    public static function autenticar(PDO $pdo, ?string $serie = null): array
    {
        self::asegurarTablas($pdo);
        $token = self::tokenPeticion();
        if ($token === '' || !str_starts_with($token, 'MLMOSI_')) {
            throw new RuntimeException('Token MOSI requerido.');
        }
        $sql = "SELECT t.id_token,t.id_mosi,m.numero_serie,m.nombre,m.estatus
                FROM vendingm.telemedicina_mosi_api_tokens t
                INNER JOIN vendingm.telemedicina_mosi m ON m.id_mosi=t.id_mosi
                WHERE t.token_hash=:hash AND t.revocado=0 AND m.estatus<>'baja'";
        $params = ['hash' => hash('sha256', $token)];
        if ($serie !== null && trim($serie) !== '') {
            $sql .= ' AND UPPER(m.numero_serie)=:serie';
            $params['serie'] = strtoupper(trim($serie));
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Token MOSI invalido o revocado.');
        $pdo->prepare('UPDATE vendingm.telemedicina_mosi_api_tokens SET ultimo_uso=SYSDATETIME() WHERE id_token=:id')->execute(['id' => $row['id_token']]);
        return $row;
    }

    public static function tokenPeticion(): string
    {
        $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $m)) return trim($m[1]);
        return trim((string) ($_SERVER['HTTP_X_MOSI_TOKEN'] ?? ''));
    }

    private static function texto(mixed $valor, int $maximo): ?string
    {
        $texto = trim((string) ($valor ?? ''));
        if ($texto === '') return null;
        return function_exists('mb_substr') ? mb_substr($texto, 0, $maximo) : substr($texto, 0, $maximo);
    }

    private static function sha256OrNull(mixed $valor): ?string
    {
        $texto = strtolower(preg_replace('/[^a-fA-F0-9]/', '', (string) ($valor ?? '')));
        return strlen($texto) === 64 ? $texto : null;
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
