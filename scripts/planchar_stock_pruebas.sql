/*
  Normaliza stock de medicamentos de prueba.

  Objetivo:
  - Cada carril con producto queda con stock 50.
  - El lote actual de cada carril queda con cantidad 50.
  - Todos los lotes asociados a esos carriles caducan el proximo ano.
  - Si un carril tiene producto pero no tiene lote actual, crea un lote de prueba.

  Ejecutar contra la base configurada de la app:
    sqlcmd -S SERVIDOR,1433 -d erpanl -U USUARIO -P PASSWORD -C -i scripts\planchar_stock_pruebas.sql
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

DECLARE @stockObjetivo INT = 50;
DECLARE @fechaCaducidad DATE = DATEFROMPARTS(YEAR(GETDATE()) + 1, 12, 31);
DECLARE @usuario NVARCHAR(120) = N'planchar-stock-pruebas';
DECLARE @idReabastecimiento INT = NULL;

BEGIN TRY
    BEGIN TRANSACTION;

    SELECT
        'ANTES' AS etapa,
        COUNT(*) AS carriles_con_producto,
        SUM(CASE WHEN ca.id_lote_actual IS NULL THEN 1 ELSE 0 END) AS carriles_sin_lote_actual,
        SUM(ISNULL(ca.cantidad_actual, 0)) AS stock_total_carriles,
        MIN(l.fecha_caducidad) AS primera_caducidad,
        MAX(l.fecha_caducidad) AS ultima_caducidad
    FROM vendingm.carriles ca
    LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
    WHERE ca.id_producto IS NOT NULL;

    IF OBJECT_ID('vendingm.reabastecimientos', 'U') IS NOT NULL
    BEGIN
        INSERT INTO vendingm.reabastecimientos (nombre_archivo_origen, usuario_operador)
        VALUES (N'planchar_stock_pruebas.sql', @usuario);

        SET @idReabastecimiento = CONVERT(INT, SCOPE_IDENTITY());
    END;

    DECLARE @creados TABLE (
        id_carril INT NOT NULL PRIMARY KEY,
        id_lote INT NOT NULL
    );

    INSERT INTO vendingm.lotes
        (id_carril, id_producto, numero_lote, fecha_caducidad, cantidad_inicial, cantidad_actual, id_reabastecimiento)
    OUTPUT INSERTED.id_carril, INSERTED.id_lote INTO @creados (id_carril, id_lote)
    SELECT
        ca.id_carril,
        ca.id_producto,
        CONCAT(N'PRUEBA-', ca.id_carril),
        @fechaCaducidad,
        @stockObjetivo,
        @stockObjetivo,
        @idReabastecimiento
    FROM vendingm.carriles ca
    WHERE ca.id_producto IS NOT NULL
      AND ca.id_lote_actual IS NULL;

    UPDATE ca
    SET ca.id_lote_actual = c.id_lote,
        ca.fecha_actualizacion = SYSDATETIME()
    FROM vendingm.carriles ca
    INNER JOIN @creados c ON c.id_carril = ca.id_carril;

    UPDATE l
    SET l.fecha_caducidad = @fechaCaducidad,
        l.cantidad_inicial = @stockObjetivo,
        l.cantidad_actual = CASE
            WHEN l.id_lote = ca.id_lote_actual THEN @stockObjetivo
            ELSE 0
        END
    FROM vendingm.lotes l
    INNER JOIN vendingm.carriles ca ON ca.id_carril = l.id_carril
    WHERE ca.id_producto IS NOT NULL;

    UPDATE ca
    SET ca.cantidad_actual = @stockObjetivo,
        ca.fecha_actualizacion = SYSDATETIME()
    FROM vendingm.carriles ca
    WHERE ca.id_producto IS NOT NULL;

    SELECT
        'DESPUES' AS etapa,
        COUNT(*) AS carriles_con_producto,
        SUM(CASE WHEN ca.id_lote_actual IS NULL THEN 1 ELSE 0 END) AS carriles_sin_lote_actual,
        SUM(ISNULL(ca.cantidad_actual, 0)) AS stock_total_carriles,
        MIN(l.fecha_caducidad) AS primera_caducidad,
        MAX(l.fecha_caducidad) AS ultima_caducidad
    FROM vendingm.carriles ca
    LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
    WHERE ca.id_producto IS NOT NULL;

    SELECT TOP 100
        p.id_producto,
        p.codigo_barras,
        p.nombre,
        COUNT(ca.id_carril) AS carriles,
        SUM(ISNULL(ca.cantidad_actual, 0)) AS stock_total
    FROM vendingm.productos p
    INNER JOIN vendingm.carriles ca ON ca.id_producto = p.id_producto
    GROUP BY p.id_producto, p.codigo_barras, p.nombre
    ORDER BY p.nombre;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;

    THROW;
END CATCH;
