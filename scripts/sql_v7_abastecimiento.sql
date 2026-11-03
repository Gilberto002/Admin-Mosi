/*
  Modulo independiente de abastecimiento de medicamentos.
  Puede ejecutarse varias veces. La aplicacion tambien crea estas tablas de
  forma defensiva al abrir el modulo por primera vez.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRANSACTION;

IF OBJECT_ID('vendingm.abasto_regiones', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.abasto_regiones (
        id_region INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        clave NVARCHAR(30) NOT NULL UNIQUE,
        nombre NVARCHAR(120) NOT NULL,
        responsable NVARCHAR(120) NULL,
        telefono NVARCHAR(30) NULL,
        activo BIT NOT NULL DEFAULT 1,
        fecha_creacion DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
        fecha_actualizacion DATETIME2 NULL
    );
END;

IF OBJECT_ID('vendingm.abasto_choferes', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.abasto_choferes (
        id_chofer INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        id_region INT NULL REFERENCES vendingm.abasto_regiones(id_region),
        numero_empleado NVARCHAR(40) NULL,
        nombre NVARCHAR(160) NOT NULL,
        telefono NVARCHAR(30) NULL,
        licencia NVARCHAR(60) NULL,
        licencia_vigencia DATE NULL,
        vehiculo NVARCHAR(100) NULL,
        placas NVARCHAR(20) NULL,
        capacidad_carga DECIMAL(12,2) NULL,
        estatus NVARCHAR(20) NOT NULL DEFAULT 'disponible',
        notas NVARCHAR(500) NULL,
        activo BIT NOT NULL DEFAULT 1,
        fecha_creacion DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
        fecha_actualizacion DATETIME2 NULL
    );
    CREATE INDEX IX_abasto_chofer_region ON vendingm.abasto_choferes(id_region, activo, estatus);
END;

IF OBJECT_ID('vendingm.abasto_rutas', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.abasto_rutas (
        id_ruta INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        id_region INT NOT NULL REFERENCES vendingm.abasto_regiones(id_region),
        id_chofer INT NULL REFERENCES vendingm.abasto_choferes(id_chofer),
        clave NVARCHAR(30) NOT NULL UNIQUE,
        nombre NVARCHAR(140) NOT NULL,
        dias_operacion NVARCHAR(80) NULL,
        hora_salida TIME NULL,
        capacidad_piezas INT NULL,
        estatus NVARCHAR(20) NOT NULL DEFAULT 'activa',
        notas NVARCHAR(500) NULL,
        fecha_creacion DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
        fecha_actualizacion DATETIME2 NULL
    );
    CREATE INDEX IX_abasto_ruta_region ON vendingm.abasto_rutas(id_region, estatus);
END;

IF OBJECT_ID('vendingm.abasto_ruta_maquinas', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.abasto_ruta_maquinas (
        id_ruta INT NOT NULL REFERENCES vendingm.abasto_rutas(id_ruta),
        id_maquina INT NOT NULL REFERENCES vendingm.maquinas(id_maquina),
        orden_base INT NULL,
        ventana_desde TIME NULL,
        ventana_hasta TIME NULL,
        notas NVARCHAR(300) NULL,
        fecha_asignacion DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
        CONSTRAINT PK_abasto_ruta_maquinas PRIMARY KEY (id_ruta, id_maquina)
    );
    CREATE INDEX IX_abasto_rm_maquina ON vendingm.abasto_ruta_maquinas(id_maquina);
END;

IF OBJECT_ID('vendingm.abasto_jornadas', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.abasto_jornadas (
        id_jornada INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        id_ruta INT NOT NULL REFERENCES vendingm.abasto_rutas(id_ruta),
        id_chofer INT NULL REFERENCES vendingm.abasto_choferes(id_chofer),
        fecha_operacion DATE NOT NULL,
        estatus NVARCHAR(20) NOT NULL DEFAULT 'programada',
        fecha_inicio DATETIME2 NULL,
        fecha_fin DATETIME2 NULL,
        km_inicial DECIMAL(12,2) NULL,
        km_final DECIMAL(12,2) NULL,
        piezas_cargadas INT NULL,
        piezas_entregadas INT NULL,
        observaciones NVARCHAR(700) NULL,
        creado_por INT NULL,
        fecha_creacion DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
        fecha_actualizacion DATETIME2 NULL
    );
    CREATE INDEX IX_abasto_jornada_fecha ON vendingm.abasto_jornadas(fecha_operacion, estatus, id_ruta);
END;

IF OBJECT_ID('vendingm.abasto_paradas', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.abasto_paradas (
        id_parada INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        id_jornada INT NOT NULL REFERENCES vendingm.abasto_jornadas(id_jornada),
        id_maquina INT NOT NULL REFERENCES vendingm.maquinas(id_maquina),
        orden_visita INT NOT NULL,
        prioridad_snapshot NVARCHAR(20) NOT NULL,
        porcentaje_llenado_snapshot DECIMAL(6,2) NULL,
        piezas_sugeridas INT NULL,
        piezas_entregadas INT NULL,
        estatus NVARCHAR(20) NOT NULL DEFAULT 'pendiente',
        fecha_llegada DATETIME2 NULL,
        fecha_salida DATETIME2 NULL,
        incidencia NVARCHAR(300) NULL,
        observaciones NVARCHAR(500) NULL,
        fecha_actualizacion DATETIME2 NULL,
        CONSTRAINT UQ_abasto_parada_jornada_maquina UNIQUE(id_jornada, id_maquina)
    );
    CREATE INDEX IX_abasto_parada_estado ON vendingm.abasto_paradas(id_jornada, estatus, orden_visita);
END;

IF OBJECT_ID('vendingm.abasto_eventos', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.abasto_eventos (
        id_evento BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        entidad NVARCHAR(30) NOT NULL,
        id_entidad INT NOT NULL,
        tipo_evento NVARCHAR(50) NOT NULL,
        detalle NVARCHAR(1000) NULL,
        id_usuario INT NULL,
        fecha_evento DATETIME2 NOT NULL DEFAULT SYSDATETIME()
    );
    CREATE INDEX IX_abasto_evento_entidad ON vendingm.abasto_eventos(entidad, id_entidad, fecha_evento DESC);
END;

COMMIT TRANSACTION;
