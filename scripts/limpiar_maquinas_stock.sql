/*
  Limpia maquinas y stock de vendingm.

  Por defecto NO borra nada: deja @confirmar = 0 para ver la vista previa.
  Para ejecutar el borrado cambia @confirmar a 1.

  Borra datos operativos ligados a maquinas:
  - maquinas
  - carriles
  - lotes / stock actual
  - recetas y detalle
  - salidas, solicitudes, planeacion, distribucion, mantenimientos
  - tokens/eventos Android y asignaciones de usuarios a maquinas

  Conserva catalogos y usuarios:
  - productos
  - categorias
  - usuarios
  - estatus / tipos
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

-- Permite volver a ejecutar el script en la misma conexion despues de una
-- vista previa o de un intento anterior.
DROP TABLE IF EXISTS #Recetas;
DROP TABLE IF EXISTS #Lotes;
DROP TABLE IF EXISTS #Carriles;
DROP TABLE IF EXISTS #Maquinas;

DECLARE @confirmar BIT = 0;
DECLARE @id_maquina_desde INT = 28;
DECLARE @id_maquina_hasta INT = 33;

IF OBJECT_ID('vendingm.maquinas', 'U') IS NULL
BEGIN
    THROW 50001, 'No existe vendingm.maquinas en esta base.', 1;
END;

CREATE TABLE #Maquinas (id_maquina INT NOT NULL PRIMARY KEY);
CREATE TABLE #Carriles (id_carril INT NOT NULL PRIMARY KEY);
CREATE TABLE #Lotes (id_lote INT NOT NULL PRIMARY KEY);
CREATE TABLE #Recetas (id_receta INT NOT NULL PRIMARY KEY);

INSERT INTO #Maquinas (id_maquina)
SELECT id_maquina
FROM vendingm.maquinas
WHERE id_maquina BETWEEN @id_maquina_desde AND @id_maquina_hasta;

IF OBJECT_ID('vendingm.carriles', 'U') IS NOT NULL
BEGIN
    INSERT INTO #Carriles (id_carril)
    SELECT ca.id_carril 
    FROM vendingm.carriles ca
    INNER JOIN #Maquinas m ON m.id_maquina = ca.id_maquina;
END;

IF OBJECT_ID('vendingm.lotes', 'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('vendingm.lotes', 'id_carril') IS NOT NULL
    BEGIN
        EXEC sys.sp_executesql N'
            INSERT INTO #Lotes (id_lote)
            SELECT l.id_lote
            FROM vendingm.lotes l
            INNER JOIN #Carriles c ON c.id_carril = l.id_carril;';
    END;
END;

IF OBJECT_ID('vendingm.recetas', 'U') IS NOT NULL
BEGIN
    INSERT INTO #Recetas (id_receta)
    SELECT r.id_receta
    FROM vendingm.recetas r
    INNER JOIN #Maquinas m ON m.id_maquina = r.id_maquina;
END;

IF OBJECT_ID('vendingm.detalle_receta', 'U') IS NOT NULL
   AND COL_LENGTH('vendingm.detalle_receta', 'id_maquina') IS NOT NULL
BEGIN
    EXEC sys.sp_executesql N'
        INSERT INTO #Recetas (id_receta)
        SELECT DISTINCT dr.id_receta
        FROM vendingm.detalle_receta dr
        INNER JOIN #Maquinas m ON m.id_maquina = dr.id_maquina
        WHERE NOT EXISTS (SELECT 1 FROM #Recetas r WHERE r.id_receta = dr.id_receta);';
END;

SELECT 'VISTA_PREVIA' AS etapa, 'maquinas' AS tabla, COUNT(*) AS filas FROM #Maquinas
UNION ALL SELECT 'VISTA_PREVIA', 'carriles', COUNT(*) FROM #Carriles
UNION ALL SELECT 'VISTA_PREVIA', 'lotes', COUNT(*) FROM #Lotes
UNION ALL SELECT 'VISTA_PREVIA', 'recetas', COUNT(*) FROM #Recetas;

SELECT TOP 200
    m.id_maquina,
    maq.numero_serie,
    maq.rol_maquina,
    maq.id_maquina_padre,
    maq.id_estatus,
    COUNT(c.id_carril) AS carriles,
    SUM(ISNULL(ca.cantidad_actual, 0)) AS stock_actual
FROM #Maquinas m
INNER JOIN vendingm.maquinas maq ON maq.id_maquina = m.id_maquina
LEFT JOIN #Carriles c ON c.id_carril IN (
    SELECT ca2.id_carril FROM vendingm.carriles ca2 WHERE ca2.id_maquina = m.id_maquina
)
LEFT JOIN vendingm.carriles ca ON ca.id_carril = c.id_carril
GROUP BY m.id_maquina, maq.numero_serie, maq.rol_maquina, maq.id_maquina_padre, maq.id_estatus
ORDER BY m.id_maquina;

IF @confirmar = 0
BEGIN
    PRINT 'Vista previa solamente. Cambia @confirmar = 1 para borrar maquinas y stock.';
    DROP TABLE IF EXISTS #Recetas;
    DROP TABLE IF EXISTS #Lotes;
    DROP TABLE IF EXISTS #Carriles;
    DROP TABLE IF EXISTS #Maquinas;
    RETURN;
END;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID('vendingm.api_tokens_maquina', 'U') IS NOT NULL
       AND COL_LENGTH('vendingm.api_tokens_maquina', 'id_maquina') IS NOT NULL
        EXEC sys.sp_executesql N'DELETE t FROM vendingm.api_tokens_maquina t INNER JOIN #Maquinas m ON m.id_maquina = t.id_maquina;';

    IF OBJECT_ID('vendingm.api_telemetria_android', 'U') IS NOT NULL
       AND COL_LENGTH('vendingm.api_telemetria_android', 'id_maquina') IS NOT NULL
        EXEC sys.sp_executesql N'DELETE t FROM vendingm.api_telemetria_android t INNER JOIN #Maquinas m ON m.id_maquina = t.id_maquina;';

    IF OBJECT_ID('vendingm.api_errores_surtido', 'U') IS NOT NULL
       AND COL_LENGTH('vendingm.api_errores_surtido', 'id_maquina') IS NOT NULL
        EXEC sys.sp_executesql N'DELETE e FROM vendingm.api_errores_surtido e INNER JOIN #Maquinas m ON m.id_maquina = e.id_maquina;';

    IF OBJECT_ID('vendingm.api_eventos_android', 'U') IS NOT NULL
       AND COL_LENGTH('vendingm.api_eventos_android', 'id_maquina') IS NOT NULL
        EXEC sys.sp_executesql N'DELETE e FROM vendingm.api_eventos_android e INNER JOIN #Maquinas m ON m.id_maquina = e.id_maquina;';

    IF OBJECT_ID('vendingm.usuarios_maquinas', 'U') IS NOT NULL
       AND COL_LENGTH('vendingm.usuarios_maquinas', 'id_maquina') IS NOT NULL
        EXEC sys.sp_executesql N'DELETE um FROM vendingm.usuarios_maquinas um INNER JOIN #Maquinas m ON m.id_maquina = um.id_maquina;';

    IF OBJECT_ID('vendingm.telemedicina_mosi_maquinas', 'U') IS NOT NULL
       AND COL_LENGTH('vendingm.telemedicina_mosi_maquinas', 'id_maquina') IS NOT NULL
        EXEC sys.sp_executesql N'DELETE mm FROM vendingm.telemedicina_mosi_maquinas mm INNER JOIN #Maquinas m ON m.id_maquina = mm.id_maquina;';

    IF OBJECT_ID('vendingm.abasto_paradas', 'U') IS NOT NULL
       AND COL_LENGTH('vendingm.abasto_paradas', 'id_maquina') IS NOT NULL
        EXEC sys.sp_executesql N'DELETE ap FROM vendingm.abasto_paradas ap INNER JOIN #Maquinas m ON m.id_maquina = ap.id_maquina;';

    IF OBJECT_ID('vendingm.abasto_ruta_maquinas', 'U') IS NOT NULL
       AND COL_LENGTH('vendingm.abasto_ruta_maquinas', 'id_maquina') IS NOT NULL
        EXEC sys.sp_executesql N'DELETE arm FROM vendingm.abasto_ruta_maquinas arm INNER JOIN #Maquinas m ON m.id_maquina = arm.id_maquina;';

    IF OBJECT_ID('vendingm.maquina_branding', 'U') IS NOT NULL
       AND COL_LENGTH('vendingm.maquina_branding', 'id_maquina') IS NOT NULL
        EXEC sys.sp_executesql N'DELETE mb FROM vendingm.maquina_branding mb INNER JOIN #Maquinas m ON m.id_maquina = mb.id_maquina;';

    IF OBJECT_ID('vendingm.distribucion_viajes', 'U') IS NOT NULL
       AND COL_LENGTH('vendingm.distribucion_viajes', 'id_maquina') IS NOT NULL
        EXEC sys.sp_executesql N'DELETE dv FROM vendingm.distribucion_viajes dv INNER JOIN #Maquinas m ON m.id_maquina = dv.id_maquina;';

    IF OBJECT_ID('vendingm.mantenimientos_maquina', 'U') IS NOT NULL
       AND COL_LENGTH('vendingm.mantenimientos_maquina', 'id_maquina') IS NOT NULL
        EXEC sys.sp_executesql N'DELETE mm FROM vendingm.mantenimientos_maquina mm INNER JOIN #Maquinas m ON m.id_maquina = mm.id_maquina;';

    IF OBJECT_ID('vendingm.planeacion_reabastecimiento', 'U') IS NOT NULL
       AND COL_LENGTH('vendingm.planeacion_reabastecimiento', 'id_maquina') IS NOT NULL
        EXEC sys.sp_executesql N'DELETE pr FROM vendingm.planeacion_reabastecimiento pr INNER JOIN #Maquinas m ON m.id_maquina = pr.id_maquina;';

    IF OBJECT_ID('vendingm.solicitudes_pedido', 'U') IS NOT NULL
    BEGIN
        IF COL_LENGTH('vendingm.solicitudes_pedido', 'id_carril') IS NOT NULL
            EXEC sys.sp_executesql N'DELETE sp FROM vendingm.solicitudes_pedido sp INNER JOIN #Carriles c ON c.id_carril = sp.id_carril;';

        IF COL_LENGTH('vendingm.solicitudes_pedido', 'id_maquina') IS NOT NULL
            EXEC sys.sp_executesql N'DELETE sp FROM vendingm.solicitudes_pedido sp INNER JOIN #Maquinas m ON m.id_maquina = sp.id_maquina;';
    END;

    IF OBJECT_ID('vendingm.movimientos_salida', 'U') IS NOT NULL
    BEGIN
        IF COL_LENGTH('vendingm.movimientos_salida', 'id_lote') IS NOT NULL
            EXEC sys.sp_executesql N'DELETE ms FROM vendingm.movimientos_salida ms INNER JOIN #Lotes l ON l.id_lote = ms.id_lote;';

        IF COL_LENGTH('vendingm.movimientos_salida', 'id_carril') IS NOT NULL
            EXEC sys.sp_executesql N'DELETE ms FROM vendingm.movimientos_salida ms INNER JOIN #Carriles c ON c.id_carril = ms.id_carril;';

        IF COL_LENGTH('vendingm.movimientos_salida', 'id_maquina') IS NOT NULL
            EXEC sys.sp_executesql N'DELETE ms FROM vendingm.movimientos_salida ms INNER JOIN #Maquinas m ON m.id_maquina = ms.id_maquina;';
    END;

    IF OBJECT_ID('vendingm.detalle_receta', 'U') IS NOT NULL
        DELETE dr FROM vendingm.detalle_receta dr INNER JOIN #Recetas r ON r.id_receta = dr.id_receta;

    IF OBJECT_ID('vendingm.recetas', 'U') IS NOT NULL
        DELETE r FROM vendingm.recetas r INNER JOIN #Recetas rr ON rr.id_receta = r.id_receta;

    IF OBJECT_ID('vendingm.carriles', 'U') IS NOT NULL
       AND COL_LENGTH('vendingm.carriles', 'id_lote_actual') IS NOT NULL
        EXEC sys.sp_executesql N'
            UPDATE ca
            SET id_lote_actual = NULL,
                cantidad_actual = 0,
                id_producto = NULL,
                fecha_actualizacion = SYSDATETIME()
            FROM vendingm.carriles ca
            INNER JOIN #Carriles c ON c.id_carril = ca.id_carril;';

    IF OBJECT_ID('vendingm.lotes', 'U') IS NOT NULL
        DELETE l FROM vendingm.lotes l INNER JOIN #Lotes ll ON ll.id_lote = l.id_lote;

    IF OBJECT_ID('vendingm.carriles', 'U') IS NOT NULL
        DELETE ca FROM vendingm.carriles ca INNER JOIN #Carriles c ON c.id_carril = ca.id_carril;

    DELETE maq
    FROM vendingm.maquinas maq
    INNER JOIN #Maquinas m ON m.id_maquina = maq.id_maquina;

    SELECT 'DESPUES' AS etapa, 'maquinas' AS tabla, COUNT(*) AS filas FROM vendingm.maquinas
    UNION ALL SELECT 'DESPUES', 'carriles', CASE WHEN OBJECT_ID('vendingm.carriles', 'U') IS NULL THEN 0 ELSE (SELECT COUNT(*) FROM vendingm.carriles) END
    UNION ALL SELECT 'DESPUES', 'lotes', CASE WHEN OBJECT_ID('vendingm.lotes', 'U') IS NULL THEN 0 ELSE (SELECT COUNT(*) FROM vendingm.lotes) END;

    COMMIT TRANSACTION;

    DROP TABLE IF EXISTS #Recetas;
    DROP TABLE IF EXISTS #Lotes;
    DROP TABLE IF EXISTS #Carriles;
    DROP TABLE IF EXISTS #Maquinas;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;

    DROP TABLE IF EXISTS #Recetas;
    DROP TABLE IF EXISTS #Lotes;
    DROP TABLE IF EXISTS #Carriles;
    DROP TABLE IF EXISTS #Maquinas;

    THROW;
END CATCH;
