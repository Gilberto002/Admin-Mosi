<?php

declare(strict_types=1);

namespace App\Telemedicina;

use PDO;

final class Schema
{
    public const MINUTOS_ONLINE = 10;

    public static function ensure(PDO $pdo): void
    {
        $completo = (bool) $pdo->query(<<<'SQL'
SELECT CASE WHEN
    OBJECT_ID('vendingm.telemedicina_grupos', 'U') IS NOT NULL AND
    OBJECT_ID('vendingm.telemedicina_mosi', 'U') IS NOT NULL AND
    OBJECT_ID('vendingm.telemedicina_mosi_maquinas', 'U') IS NOT NULL AND
    OBJECT_ID('vendingm.telemedicina_personal', 'U') IS NOT NULL AND
    OBJECT_ID('vendingm.telemedicina_personal_mosi', 'U') IS NOT NULL AND
    OBJECT_ID('vendingm.telemedicina_mosi_eventos', 'U') IS NOT NULL AND
    COL_LENGTH('vendingm.telemedicina_mosi', 'internet_disponible') IS NOT NULL AND
    COL_LENGTH('vendingm.telemedicina_mosi', 'sensores_json') IS NOT NULL AND
    COL_LENGTH('vendingm.telemedicina_mosi', 'pendientes_sincronizacion') IS NOT NULL AND
    COL_LENGTH('vendingm.telemedicina_mosi', 'id_sucursal_externa') IS NOT NULL AND
    COL_LENGTH('vendingm.telemedicina_mosi', 'sucursal_nombre') IS NOT NULL AND
    COL_LENGTH('vendingm.telemedicina_mosi', 'identificador_dispositivo') IS NOT NULL AND
    COL_LENGTH('vendingm.telemedicina_mosi', 'version_code') IS NOT NULL AND
    COL_LENGTH('vendingm.telemedicina_mosi', 'ultima_sincronizacion') IS NOT NULL AND
    COL_LENGTH('vendingm.telemedicina_mosi_eventos', 'request_id') IS NOT NULL AND
    COL_LENGTH('vendingm.telemedicina_mosi_eventos', 'sesion_id') IS NOT NULL AND
    COL_LENGTH('vendingm.telemedicina_mosi_eventos', 'tipo_evento') IS NOT NULL
THEN 1 ELSE 0 END
SQL)->fetchColumn();
        if ($completo) {
            return;
        }

        $pdo->exec("EXEC sp_getapplock @Resource = 'vendingm_telemedicina_schema', @LockMode = 'Exclusive', @LockOwner = 'Session', @LockTimeout = 15000");
        try {
            $pdo->exec(<<<'SQL'
IF OBJECT_ID('vendingm.telemedicina_grupos', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.telemedicina_grupos (
        id_grupo INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        clave NVARCHAR(30) NOT NULL,
        nombre NVARCHAR(120) NOT NULL,
        descripcion NVARCHAR(400) NULL,
        responsable NVARCHAR(120) NULL,
        activo BIT NOT NULL CONSTRAINT DF_tm_grupo_activo DEFAULT 1,
        fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_tm_grupo_creacion DEFAULT SYSDATETIME(),
        fecha_actualizacion DATETIME2 NULL,
        CONSTRAINT UQ_tm_grupo_clave UNIQUE (clave)
    );
END;

IF OBJECT_ID('vendingm.telemedicina_mosi', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.telemedicina_mosi (
        id_mosi INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        id_grupo INT NULL,
        numero_serie NVARCHAR(100) NOT NULL,
        nombre NVARCHAR(140) NOT NULL,
        modelo NVARCHAR(100) NULL,
        fabricante NVARCHAR(100) NULL,
        ubicacion NVARCHAR(180) NULL,
        consultorio NVARCHAR(100) NULL,
        ip_local NVARCHAR(64) NULL,
        mac_address NVARCHAR(32) NULL,
        version_software NVARCHAR(60) NULL,
        tipo_conexion NVARCHAR(50) NULL,
        ultima_conexion DATETIME2 NULL,
        estatus NVARCHAR(20) NOT NULL CONSTRAINT DF_tm_mosi_estatus DEFAULT 'activo',
        notas NVARCHAR(700) NULL,
        fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_tm_mosi_creacion DEFAULT SYSDATETIME(),
        fecha_actualizacion DATETIME2 NULL,
        CONSTRAINT UQ_tm_mosi_serie UNIQUE (numero_serie),
        CONSTRAINT FK_tm_mosi_grupo FOREIGN KEY (id_grupo) REFERENCES vendingm.telemedicina_grupos(id_grupo),
        CONSTRAINT CK_tm_mosi_estatus CHECK (estatus IN ('activo', 'mantenimiento', 'baja'))
    );
    CREATE INDEX IX_tm_mosi_grupo ON vendingm.telemedicina_mosi(id_grupo, estatus);
    CREATE INDEX IX_tm_mosi_conexion ON vendingm.telemedicina_mosi(ultima_conexion);
END;

IF OBJECT_ID('vendingm.telemedicina_mosi_maquinas', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.telemedicina_mosi_maquinas (
        id_mosi INT NOT NULL,
        id_maquina INT NOT NULL,
        fecha_vinculacion DATETIME2 NOT NULL CONSTRAINT DF_tm_mm_fecha DEFAULT SYSDATETIME(),
        vinculado_por INT NULL,
        CONSTRAINT PK_tm_mosi_maquinas PRIMARY KEY (id_mosi, id_maquina),
        CONSTRAINT FK_tm_mm_mosi FOREIGN KEY (id_mosi) REFERENCES vendingm.telemedicina_mosi(id_mosi) ON DELETE CASCADE,
        CONSTRAINT FK_tm_mm_maquina FOREIGN KEY (id_maquina) REFERENCES vendingm.maquinas(id_maquina)
    );
    CREATE INDEX IX_tm_mm_maquina ON vendingm.telemedicina_mosi_maquinas(id_maquina);
END;

IF COL_LENGTH('vendingm.telemedicina_mosi', 'identificador_dispositivo') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD identificador_dispositivo NVARCHAR(100) NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'internet_disponible') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD internet_disponible BIT NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'wifi_conectado') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD wifi_conectado BIT NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'ethernet_conectado') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD ethernet_conectado BIT NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'nombre_red') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD nombre_red NVARCHAR(120) NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'sensores_json') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD sensores_json NVARCHAR(MAX) NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'pendientes_sincronizacion') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD pendientes_sincronizacion INT NOT NULL CONSTRAINT DF_tm_mosi_pendientes DEFAULT 0;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'errores_recientes') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD errores_recientes INT NOT NULL CONSTRAINT DF_tm_mosi_errores DEFAULT 0;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'ultima_medicion') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD ultima_medicion DATETIME2 NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'actualizacion_disponible') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD actualizacion_disponible BIT NOT NULL CONSTRAINT DF_tm_mosi_actualizacion DEFAULT 0;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'version_disponible') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD version_disponible NVARCHAR(60) NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'id_cliente_externo') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD id_cliente_externo NVARCHAR(100) NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'cliente_nombre') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD cliente_nombre NVARCHAR(180) NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'id_sucursal_externa') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD id_sucursal_externa NVARCHAR(100) NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'sucursal_nombre') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD sucursal_nombre NVARCHAR(180) NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'version_code') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD version_code INT NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'ultima_sincronizacion') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD ultima_sincronizacion DATETIME2 NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'device_owner') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD device_owner BIT NULL;

IF OBJECT_ID('vendingm.telemedicina_mosi_eventos', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.telemedicina_mosi_eventos (
        id_evento BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        id_mosi INT NOT NULL,
        nivel NVARCHAR(12) NOT NULL CONSTRAINT DF_tm_evento_nivel DEFAULT 'info',
        codigo NVARCHAR(80) NULL,
        mensaje NVARCHAR(500) NOT NULL,
        payload_json NVARCHAR(MAX) NULL,
        fecha_evento DATETIME2 NOT NULL CONSTRAINT DF_tm_evento_fecha DEFAULT SYSDATETIME(),
        CONSTRAINT FK_tm_evento_mosi FOREIGN KEY (id_mosi) REFERENCES vendingm.telemedicina_mosi(id_mosi) ON DELETE CASCADE,
        CONSTRAINT CK_tm_evento_nivel CHECK (nivel IN ('info', 'warning', 'error'))
    );
    CREATE INDEX IX_tm_evento_mosi_fecha ON vendingm.telemedicina_mosi_eventos(id_mosi, fecha_evento DESC);
END;
IF COL_LENGTH('vendingm.telemedicina_mosi_eventos', 'request_id') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi_eventos ADD request_id NVARCHAR(120) NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi_eventos', 'sesion_id') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi_eventos ADD sesion_id NVARCHAR(120) NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi_eventos', 'tipo_evento') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi_eventos ADD tipo_evento NVARCHAR(40) NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('vendingm.telemedicina_mosi_eventos') AND name='UX_tm_evento_request')
    EXEC(N'CREATE UNIQUE INDEX UX_tm_evento_request ON vendingm.telemedicina_mosi_eventos(id_mosi,request_id) WHERE request_id IS NOT NULL');

IF OBJECT_ID('vendingm.telemedicina_personal', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.telemedicina_personal (
        id_personal INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        id_usuario INT NULL,
        id_medico_legacy INT NULL,
        grupo_legacy INT NULL,
        tipo_profesional NVARCHAR(20) NOT NULL,
        nombre NVARCHAR(100) NOT NULL,
        apellido NVARCHAR(160) NULL,
        email NVARCHAR(180) NULL,
        telefono NVARCHAR(30) NULL,
        cedula_profesional NVARCHAR(60) NULL,
        especialidad NVARCHAR(120) NULL,
        sucursal_legacy NVARCHAR(180) NULL,
        vendings_legacy NVARCHAR(1000) NULL,
        estado_operativo NVARCHAR(20) NOT NULL CONSTRAINT DF_tm_personal_estado DEFAULT 'desconectado',
        sesion_activa BIT NOT NULL CONSTRAINT DF_tm_personal_sesion DEFAULT 0,
        hora_inicio TIME NULL,
        hora_fin TIME NULL,
        activo BIT NOT NULL CONSTRAINT DF_tm_personal_activo DEFAULT 1,
        fecha_sincronizacion DATETIME2 NULL,
        fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_tm_personal_creacion DEFAULT SYSDATETIME(),
        fecha_actualizacion DATETIME2 NULL,
        CONSTRAINT FK_tm_personal_usuario FOREIGN KEY (id_usuario) REFERENCES vendingm.usuarios(id_usuario),
        CONSTRAINT CK_tm_personal_tipo CHECK (tipo_profesional IN ('medico', 'psicologo', 'nutriologo')),
        CONSTRAINT CK_tm_personal_estado CHECK (estado_operativo IN ('disponible', 'en_consulta', 'desconectado'))
    );
    CREATE UNIQUE INDEX UX_tm_personal_usuario ON vendingm.telemedicina_personal(id_usuario) WHERE id_usuario IS NOT NULL;
    CREATE UNIQUE INDEX UX_tm_personal_legacy ON vendingm.telemedicina_personal(id_medico_legacy) WHERE id_medico_legacy IS NOT NULL;
    CREATE INDEX IX_tm_personal_tipo ON vendingm.telemedicina_personal(tipo_profesional, activo, estado_operativo);
END;

IF OBJECT_ID('vendingm.telemedicina_personal_mosi', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.telemedicina_personal_mosi (
        id_personal INT NOT NULL,
        id_mosi INT NOT NULL,
        fecha_asignacion DATETIME2 NOT NULL CONSTRAINT DF_tm_pm_fecha DEFAULT SYSDATETIME(),
        asignado_por INT NULL,
        CONSTRAINT PK_tm_personal_mosi PRIMARY KEY (id_personal, id_mosi),
        CONSTRAINT FK_tm_pm_personal FOREIGN KEY (id_personal) REFERENCES vendingm.telemedicina_personal(id_personal) ON DELETE CASCADE,
        CONSTRAINT FK_tm_pm_mosi FOREIGN KEY (id_mosi) REFERENCES vendingm.telemedicina_mosi(id_mosi) ON DELETE CASCADE
    );
    CREATE INDEX IX_tm_pm_mosi ON vendingm.telemedicina_personal_mosi(id_mosi);
END;
SQL);
        } finally {
            $pdo->exec("EXEC sp_releaseapplock @Resource = 'vendingm_telemedicina_schema', @LockOwner = 'Session'");
        }
    }
}
