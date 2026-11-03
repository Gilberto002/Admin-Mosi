/* Integracion Medical Center -> vendingm. Seguro para ejecutar mas de una vez. */
SET ANSI_NULLS ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET ARITHABORT ON;
SET QUOTED_IDENTIFIER ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET NUMERIC_ROUNDABORT OFF;
SET XACT_ABORT ON;
BEGIN TRANSACTION;

IF COL_LENGTH('vendingm.recetas', 'codigo_receta') IS NULL
    EXEC(N'ALTER TABLE vendingm.recetas ADD codigo_receta NVARCHAR(10) NULL;');
IF COL_LENGTH('vendingm.recetas', 'tipo_receta') IS NULL
    EXEC(N'ALTER TABLE vendingm.recetas ADD tipo_receta NVARCHAR(30) NOT NULL
        CONSTRAINT DF_recetas_tipo_receta DEFAULT ''cliente'';');

IF COL_LENGTH('vendingm.productos', 'id_producto_almacen_origen') IS NULL
    EXEC(N'ALTER TABLE vendingm.productos ADD id_producto_almacen_origen INT NULL;');

IF COL_LENGTH('vendingm.recetas', 'origen') IS NULL
    EXEC(N'ALTER TABLE vendingm.recetas ADD origen NVARCHAR(30) NULL;');
IF COL_LENGTH('vendingm.recetas', 'id_receta_origen') IS NULL
    EXEC(N'ALTER TABLE vendingm.recetas ADD id_receta_origen BIGINT NULL;');
IF COL_LENGTH('vendingm.recetas', 'id_evento_origen') IS NULL
    EXEC(N'ALTER TABLE vendingm.recetas ADD id_evento_origen BIGINT NULL;');
IF COL_LENGTH('vendingm.recetas', 'id_usuario_web_origen') IS NULL
    EXEC(N'ALTER TABLE vendingm.recetas ADD id_usuario_web_origen BIGINT NULL;');
IF COL_LENGTH('vendingm.recetas', 'payload_hash') IS NULL
    EXEC(N'ALTER TABLE vendingm.recetas ADD payload_hash CHAR(64) NULL;');
IF COL_LENGTH('vendingm.recetas', 'fecha_expiracion') IS NULL
    EXEC(N'ALTER TABLE vendingm.recetas ADD fecha_expiracion DATETIME2 NULL;');

IF COL_LENGTH('vendingm.detalle_receta', 'id_maquina') IS NULL
    EXEC(N'ALTER TABLE vendingm.detalle_receta ADD id_maquina INT NULL;');

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'UX_recetas_origen_id_receta'
      AND object_id = OBJECT_ID('vendingm.recetas')
)
    EXEC(N'CREATE UNIQUE INDEX UX_recetas_origen_id_receta
    ON vendingm.recetas(origen, id_receta_origen)
    WHERE origen IS NOT NULL AND id_receta_origen IS NOT NULL;');

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'UX_productos_id_producto_almacen_origen'
      AND object_id = OBJECT_ID('vendingm.productos')
)
    EXEC(N'CREATE UNIQUE INDEX UX_productos_id_producto_almacen_origen
    ON vendingm.productos(id_producto_almacen_origen)
    WHERE id_producto_almacen_origen IS NOT NULL;');

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'UX_recetas_codigo_receta'
      AND object_id = OBJECT_ID('vendingm.recetas')
)
    EXEC(N'CREATE UNIQUE INDEX UX_recetas_codigo_receta
    ON vendingm.recetas(codigo_receta)
    WHERE codigo_receta IS NOT NULL;');

COMMIT TRANSACTION;

SELECT 'Base de datos' AS elemento, DB_NAME() AS resultado
UNION ALL SELECT 'recetas.fecha_expiracion', CASE WHEN COL_LENGTH('vendingm.recetas', 'fecha_expiracion') IS NULL THEN 'NO EXISTE' ELSE 'OK' END
UNION ALL SELECT 'recetas.origen', CASE WHEN COL_LENGTH('vendingm.recetas', 'origen') IS NULL THEN 'NO EXISTE' ELSE 'OK' END
UNION ALL SELECT 'recetas.payload_hash', CASE WHEN COL_LENGTH('vendingm.recetas', 'payload_hash') IS NULL THEN 'NO EXISTE' ELSE 'OK' END
UNION ALL SELECT 'detalle_receta.id_maquina', CASE WHEN COL_LENGTH('vendingm.detalle_receta', 'id_maquina') IS NULL THEN 'NO EXISTE' ELSE 'OK' END
UNION ALL SELECT 'productos.id_producto_almacen_origen', CASE WHEN COL_LENGTH('vendingm.productos', 'id_producto_almacen_origen') IS NULL THEN 'NO EXISTE' ELSE 'OK' END;
