-- =====================================================================
-- Migracion: motivo de "Correccion de Resurtido Vending" (id 19).
-- Ejecutar manualmente (SSMS) en el ambiente correspondiente, en la base
-- "erpanl". Es idempotente: se puede correr varias veces sin error.
--
-- Para que
-- ---------------------------------------------------------------------
-- El traspaso normal sucursal->vending (motivo 16 salida / 17 entrada,
-- ver system/adminVending/sql/migracion_traspaso_vending.sql) solo cubre
-- la direccion "surtir la maquina". El sistema legado NUNCA registra el
-- movimiento contrario: si un operador vacia un carril o baja la
-- cantidad, el inventario ya descontado de la sucursal real queda
-- huerfano (nunca se le acredita de vuelta a nadie). php8/adminVending_v2
-- corrige esto: "vaciar"/"bajar cantidad" en api/resurtir_carril.php
-- registra un traspaso real de vuelta a la sucursal de origen, usando
-- este nuevo motivo -- NO se reutiliza 16/17 para la direccion inversa
-- porque esos catalogos ya tienen un texto fijo ("Traspaso a Vending" /
-- "Recepcion de Vending") que en Kardex/estadisticas.php quedaria mal
-- etiquetado si se usara al reves.
--
-- Mismo patron que el motivo 18 (devolucion por vencimiento, ver
-- migracion_devolucion_vending.sql): un solo id_TipoMovimiento con dos
-- filas de catalogo, una por direccion.
-- =====================================================================

IF NOT EXISTS (SELECT 1 FROM Cat_TipoMovimientoCiclico WHERE id_TipoMovimiento = 19 AND id_EntradaSalida = 2)
BEGIN
    INSERT INTO Cat_TipoMovimientoCiclico (id_EntradaSalida, id_TipoMovimiento, st_TipoMovimientoCiclico, dt_FechaRegistro)
    VALUES (2, 19, 'Correccion de Resurtido Vending', GETDATE());
END

IF NOT EXISTS (SELECT 1 FROM Cat_TipoMovimientoCiclico WHERE id_TipoMovimiento = 19 AND id_EntradaSalida = 1)
BEGIN
    INSERT INTO Cat_TipoMovimientoCiclico (id_EntradaSalida, id_TipoMovimiento, st_TipoMovimientoCiclico, dt_FechaRegistro)
    VALUES (1, 19, 'Recepcion de Correccion de Resurtido', GETDATE());
END

-- ---------------------------------------------------------------------
-- Verificacion rapida
-- ---------------------------------------------------------------------
-- SELECT * FROM Cat_TipoMovimientoCiclico WHERE id_TipoMovimiento = 19;
