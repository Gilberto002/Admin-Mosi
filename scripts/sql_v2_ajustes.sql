-- Ajustes de esquema para adminVending_v2. Ejecutar en SQL Server, en la
-- misma base de datos usada por el proyecto (vendingm), antes de publicar
-- el v2. Es idempotente: se puede correr varias veces sin error.

-- 1) Token Android por máquina (reemplaza el token único global).
--    Se guarda solo el hash SHA-256; el token en texto plano se muestra una
--    sola vez en el panel al generarlo (api/maquina_token_regenerar.php).
IF COL_LENGTH('vendingm.maquinas', 'token_android_hash') IS NULL
BEGIN
    ALTER TABLE vendingm.maquinas ADD token_android_hash NVARCHAR(128) NULL;
END;

IF COL_LENGTH('vendingm.maquinas', 'token_android_generado') IS NULL
BEGIN
    ALTER TABLE vendingm.maquinas ADD token_android_generado DATETIME2(0) NULL;
END;

-- 2) UNIQUE real a nivel de base de datos para numero_serie / codigo_interno.
--    api/maquina_guardar.php ya valida esto con un SELECT antes de insertar,
--    pero eso es una condición de carrera bajo altas concurrentes; el
--    UNIQUE de aquí es la garantía definitiva (el endpoint atrapa la
--    violación 23000 y devuelve el mismo mensaje 409 amigable).
--
--    IMPORTANTE: si ya existen duplicados en la tabla, este ALTER fallará.
--    Antes de correrlo en un ambiente con datos reales, revisa:
--      SELECT numero_serie, COUNT(*) FROM vendingm.maquinas GROUP BY numero_serie HAVING COUNT(*) > 1;
--      SELECT codigo_interno, COUNT(*) FROM vendingm.maquinas GROUP BY codigo_interno HAVING COUNT(*) > 1;
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes WHERE name = 'UQ_maquinas_numero_serie' AND object_id = OBJECT_ID('vendingm.maquinas')
)
BEGIN
    ALTER TABLE vendingm.maquinas ADD CONSTRAINT UQ_maquinas_numero_serie UNIQUE (numero_serie);
END;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes WHERE name = 'UQ_maquinas_codigo_interno' AND object_id = OBJECT_ID('vendingm.maquinas')
)
BEGIN
    ALTER TABLE vendingm.maquinas ADD CONSTRAINT UQ_maquinas_codigo_interno UNIQUE (codigo_interno);
END;
