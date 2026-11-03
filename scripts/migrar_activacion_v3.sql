SET XACT_ABORT ON;
BEGIN TRANSACTION;

IF COL_LENGTH('vendingm.maquinas', 'nombre_maquina') IS NULL
BEGIN
    ALTER TABLE vendingm.maquinas
        ADD nombre_maquina NVARCHAR(160) NULL;
END;

IF COL_LENGTH('vendingm.maquinas', 'id_sucursal_clinica') IS NULL
BEGIN
    ALTER TABLE vendingm.maquinas
        ADD id_sucursal_clinica INT NULL;
END;

IF COL_LENGTH('vendingm.maquinas', 'ultima_conexion') IS NULL
BEGIN
    ALTER TABLE vendingm.maquinas
        ADD ultima_conexion DATETIME2 NULL;
END;

IF COL_LENGTH('vendingm.maquinas', 'tipo_conexion') IS NULL
BEGIN
    ALTER TABLE vendingm.maquinas
        ADD tipo_conexion NVARCHAR(50) NULL;
END;

IF COL_LENGTH('vendingm.maquinas', 'version_app') IS NULL
BEGIN
    ALTER TABLE vendingm.maquinas
        ADD version_app NVARCHAR(50) NULL;
END;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('vendingm.maquinas')
      AND name = 'IX_maquinas_sucursal_clinica'
)
BEGIN
    CREATE INDEX IX_maquinas_sucursal_clinica
        ON vendingm.maquinas (id_sucursal_clinica);
END;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('vendingm.maquinas')
      AND name = 'IX_maquinas_ultima_conexion'
)
BEGIN
    CREATE INDEX IX_maquinas_ultima_conexion
        ON vendingm.maquinas (ultima_conexion);
END;

COMMIT TRANSACTION;
