/* MOSI App: activacion individual, trazabilidad tecnica y canal de actualizaciones.
   Seguro para ejecutar varias veces en SQL Server. El backend tambien lo aplica
   automaticamente mediante App\Telemedicina\MosiApi::asegurarTablas(). */

IF COL_LENGTH('vendingm.telemedicina_mosi', 'version_code') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD version_code INT NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'ultima_sincronizacion') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD ultima_sincronizacion DATETIME2 NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'device_owner') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD device_owner BIT NULL;

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
        CONSTRAINT FK_tm_act_mosi FOREIGN KEY(id_mosi) REFERENCES vendingm.telemedicina_mosi(id_mosi) ON DELETE CASCADE
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
        CONSTRAINT FK_tm_token_mosi FOREIGN KEY(id_mosi) REFERENCES vendingm.telemedicina_mosi(id_mosi) ON DELETE CASCADE
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
        CONSTRAINT FK_tm_tel_mosi FOREIGN KEY(id_mosi) REFERENCES vendingm.telemedicina_mosi(id_mosi) ON DELETE CASCADE
    );
    CREATE INDEX IX_tm_tel_mosi_fecha ON vendingm.telemedicina_mosi_telemetria(id_mosi, fecha_recepcion DESC);
    CREATE UNIQUE INDEX UX_tm_tel_request ON vendingm.telemedicina_mosi_telemetria(id_mosi, request_id) WHERE request_id IS NOT NULL;
END;

IF COL_LENGTH('vendingm.app_actualizaciones_eventos', 'id_mosi') IS NULL
    ALTER TABLE vendingm.app_actualizaciones_eventos ADD id_mosi INT NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('vendingm.app_actualizaciones_eventos') AND name='IX_app_actualizaciones_eventos_mosi')
    CREATE INDEX IX_app_actualizaciones_eventos_mosi ON vendingm.app_actualizaciones_eventos(id_mosi, fecha DESC);
