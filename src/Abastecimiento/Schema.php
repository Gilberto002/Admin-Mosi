<?php

declare(strict_types=1);

namespace App\Abastecimiento;

use PDO;

/**
 * Esquema aislado para la operacion de resurtido de medicamentos.
 * No comparte tablas ni efectos laterales con distribucion_viajes, ya que
 * aquel modulo representa el traslado fisico/instalacion de una maquina.
 */
final class Schema
{
    public static function ensure(PDO $pdo): void
    {
        $completo = (bool) $pdo->query(<<<'SQL'
SELECT CASE WHEN
    OBJECT_ID('vendingm.abasto_regiones', 'U') IS NOT NULL AND
    OBJECT_ID('vendingm.abasto_choferes', 'U') IS NOT NULL AND
    OBJECT_ID('vendingm.abasto_rutas', 'U') IS NOT NULL AND
    OBJECT_ID('vendingm.abasto_ruta_maquinas', 'U') IS NOT NULL AND
    OBJECT_ID('vendingm.abasto_jornadas', 'U') IS NOT NULL AND
    OBJECT_ID('vendingm.abasto_paradas', 'U') IS NOT NULL AND
    OBJECT_ID('vendingm.abasto_eventos', 'U') IS NOT NULL
THEN 1 ELSE 0 END
SQL)->fetchColumn();
        if ($completo) {
            return;
        }

        $pdo->exec("EXEC sp_getapplock @Resource = 'vendingm_abasto_schema', @LockMode = 'Exclusive', @LockOwner = 'Session', @LockTimeout = 15000");
        try {
            $pdo->exec(<<<'SQL'
IF OBJECT_ID('vendingm.abasto_regiones', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.abasto_regiones (
        id_region INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        clave NVARCHAR(30) NOT NULL,
        nombre NVARCHAR(120) NOT NULL,
        responsable NVARCHAR(120) NULL,
        telefono NVARCHAR(30) NULL,
        activo BIT NOT NULL CONSTRAINT DF_abasto_region_activo DEFAULT 1,
        fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_abasto_region_creacion DEFAULT SYSDATETIME(),
        fecha_actualizacion DATETIME2 NULL,
        CONSTRAINT UQ_abasto_region_clave UNIQUE (clave)
    );
END;

IF OBJECT_ID('vendingm.abasto_choferes', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.abasto_choferes (
        id_chofer INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        id_region INT NULL,
        numero_empleado NVARCHAR(40) NULL,
        nombre NVARCHAR(160) NOT NULL,
        telefono NVARCHAR(30) NULL,
        licencia NVARCHAR(60) NULL,
        licencia_vigencia DATE NULL,
        vehiculo NVARCHAR(100) NULL,
        placas NVARCHAR(20) NULL,
        capacidad_carga DECIMAL(12,2) NULL,
        estatus NVARCHAR(20) NOT NULL CONSTRAINT DF_abasto_chofer_estatus DEFAULT 'disponible',
        notas NVARCHAR(500) NULL,
        activo BIT NOT NULL CONSTRAINT DF_abasto_chofer_activo DEFAULT 1,
        fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_abasto_chofer_creacion DEFAULT SYSDATETIME(),
        fecha_actualizacion DATETIME2 NULL,
        CONSTRAINT FK_abasto_chofer_region FOREIGN KEY (id_region) REFERENCES vendingm.abasto_regiones(id_region)
    );
    CREATE INDEX IX_abasto_chofer_region ON vendingm.abasto_choferes(id_region, activo, estatus);
END;

IF OBJECT_ID('vendingm.abasto_rutas', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.abasto_rutas (
        id_ruta INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        id_region INT NOT NULL,
        id_chofer INT NULL,
        clave NVARCHAR(30) NOT NULL,
        nombre NVARCHAR(140) NOT NULL,
        dias_operacion NVARCHAR(80) NULL,
        hora_salida TIME NULL,
        capacidad_piezas INT NULL,
        estatus NVARCHAR(20) NOT NULL CONSTRAINT DF_abasto_ruta_estatus DEFAULT 'activa',
        notas NVARCHAR(500) NULL,
        fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_abasto_ruta_creacion DEFAULT SYSDATETIME(),
        fecha_actualizacion DATETIME2 NULL,
        CONSTRAINT UQ_abasto_ruta_clave UNIQUE (clave),
        CONSTRAINT FK_abasto_ruta_region FOREIGN KEY (id_region) REFERENCES vendingm.abasto_regiones(id_region),
        CONSTRAINT FK_abasto_ruta_chofer FOREIGN KEY (id_chofer) REFERENCES vendingm.abasto_choferes(id_chofer)
    );
    CREATE INDEX IX_abasto_ruta_region ON vendingm.abasto_rutas(id_region, estatus);
END;

IF OBJECT_ID('vendingm.abasto_ruta_maquinas', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.abasto_ruta_maquinas (
        id_ruta INT NOT NULL,
        id_maquina INT NOT NULL,
        orden_base INT NULL,
        ventana_desde TIME NULL,
        ventana_hasta TIME NULL,
        notas NVARCHAR(300) NULL,
        fecha_asignacion DATETIME2 NOT NULL CONSTRAINT DF_abasto_rm_asignacion DEFAULT SYSDATETIME(),
        CONSTRAINT PK_abasto_ruta_maquinas PRIMARY KEY (id_ruta, id_maquina),
        CONSTRAINT FK_abasto_rm_ruta FOREIGN KEY (id_ruta) REFERENCES vendingm.abasto_rutas(id_ruta),
        CONSTRAINT FK_abasto_rm_maquina FOREIGN KEY (id_maquina) REFERENCES vendingm.maquinas(id_maquina)
    );
    CREATE INDEX IX_abasto_rm_maquina ON vendingm.abasto_ruta_maquinas(id_maquina);
END;

IF OBJECT_ID('vendingm.abasto_jornadas', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.abasto_jornadas (
        id_jornada INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        id_ruta INT NOT NULL,
        id_chofer INT NULL,
        fecha_operacion DATE NOT NULL,
        estatus NVARCHAR(20) NOT NULL CONSTRAINT DF_abasto_jornada_estatus DEFAULT 'programada',
        fecha_inicio DATETIME2 NULL,
        fecha_fin DATETIME2 NULL,
        km_inicial DECIMAL(12,2) NULL,
        km_final DECIMAL(12,2) NULL,
        piezas_cargadas INT NULL,
        piezas_entregadas INT NULL,
        observaciones NVARCHAR(700) NULL,
        creado_por INT NULL,
        fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_abasto_jornada_creacion DEFAULT SYSDATETIME(),
        fecha_actualizacion DATETIME2 NULL,
        CONSTRAINT FK_abasto_jornada_ruta FOREIGN KEY (id_ruta) REFERENCES vendingm.abasto_rutas(id_ruta),
        CONSTRAINT FK_abasto_jornada_chofer FOREIGN KEY (id_chofer) REFERENCES vendingm.abasto_choferes(id_chofer)
    );
    CREATE INDEX IX_abasto_jornada_fecha ON vendingm.abasto_jornadas(fecha_operacion, estatus, id_ruta);
END;

IF OBJECT_ID('vendingm.abasto_paradas', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.abasto_paradas (
        id_parada INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        id_jornada INT NOT NULL,
        id_maquina INT NOT NULL,
        orden_visita INT NOT NULL,
        prioridad_snapshot NVARCHAR(20) NOT NULL,
        porcentaje_llenado_snapshot DECIMAL(6,2) NULL,
        piezas_sugeridas INT NULL,
        piezas_entregadas INT NULL,
        estatus NVARCHAR(20) NOT NULL CONSTRAINT DF_abasto_parada_estatus DEFAULT 'pendiente',
        fecha_llegada DATETIME2 NULL,
        fecha_salida DATETIME2 NULL,
        incidencia NVARCHAR(300) NULL,
        observaciones NVARCHAR(500) NULL,
        fecha_actualizacion DATETIME2 NULL,
        CONSTRAINT UQ_abasto_parada_jornada_maquina UNIQUE (id_jornada, id_maquina),
        CONSTRAINT FK_abasto_parada_jornada FOREIGN KEY (id_jornada) REFERENCES vendingm.abasto_jornadas(id_jornada),
        CONSTRAINT FK_abasto_parada_maquina FOREIGN KEY (id_maquina) REFERENCES vendingm.maquinas(id_maquina)
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
        fecha_evento DATETIME2 NOT NULL CONSTRAINT DF_abasto_evento_fecha DEFAULT SYSDATETIME()
    );
    CREATE INDEX IX_abasto_evento_entidad ON vendingm.abasto_eventos(entidad, id_entidad, fecha_evento DESC);
END;
SQL);
        } finally {
            $pdo->exec("EXEC sp_releaseapplock @Resource = 'vendingm_abasto_schema', @LockOwner = 'Session'");
        }
    }
}
