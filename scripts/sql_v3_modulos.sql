-- Ajustes de esquema para incorporar los módulos productos / vencimientos /
-- estadisticas (portados desde system/adminVending_v3). Ejecutar en SQL Server,
-- en la base "erpanl". Es idempotente: se puede correr varias veces sin error.
--
-- vencimientos y estadisticas NO tocan esquema nuevo: leen/escriben las tablas
-- generales de farmacia que ya existen (cat_TipoProducto, INVENTARIO_Global,
-- cat_SucursalClinica, cat_ProductosAlmacenMaster, tbl_ProductoSalida,
-- tbl_ProductoIngreso, Cat_TipoMovimientoCiclico). Solo productos necesita
-- ajustes, sobre vendingm.productos.

IF COL_LENGTH('vendingm.productos', 'activo') IS NULL
BEGIN
    ALTER TABLE vendingm.productos ADD activo BIT NOT NULL CONSTRAINT DF_productos_activo DEFAULT 1;
END;

IF COL_LENGTH('vendingm.productos', 'sustancia_activa') IS NULL
BEGIN
    ALTER TABLE vendingm.productos ADD sustancia_activa NVARCHAR(200) NULL;
END;

IF COL_LENGTH('vendingm.productos', 'fecha_creacion') IS NULL
BEGIN
    ALTER TABLE vendingm.productos ADD fecha_creacion DATETIME2(0) NOT NULL CONSTRAINT DF_productos_fecha_creacion DEFAULT SYSDATETIME();
END;

-- UNIQUE real de codigo_barras (api/producto_guardar.php ya valida con un SELECT
-- antes de insertar, pero eso es una condición de carrera bajo altas
-- concurrentes; este UNIQUE es la garantía definitiva, igual que se hizo con
-- numero_serie/codigo_interno de vendingm.maquinas en sql_v2_ajustes.sql).
--
-- IMPORTANTE: si ya existen duplicados en la tabla, este ALTER fallará.
-- Antes de correrlo revisa:
--   SELECT codigo_barras, COUNT(*) FROM vendingm.productos GROUP BY codigo_barras HAVING COUNT(*) > 1;
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes WHERE name = 'UQ_productos_codigo_barras' AND object_id = OBJECT_ID('vendingm.productos')
)
BEGIN
    ALTER TABLE vendingm.productos ADD CONSTRAINT UQ_productos_codigo_barras UNIQUE (codigo_barras);
END;
