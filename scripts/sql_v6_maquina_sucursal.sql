-- =====================================================================
-- Vinculo manual (opcional) entre una maquina de vendingm.maquinas y una
-- sucursal real de farmacia (cat_SucursalClinica). Lo asigna el admin a
-- mano desde el formulario de "Nueva/Editar maquina" -- no hay forma de
-- inferirlo automaticamente hoy (numero_serie y st_vendingMachine son
-- conceptos de sistemas distintos, sin relacion garantizada entre ellos).
--
-- Es independiente de id_SucursalOrigenVending (el campo que usan
-- Vencimientos/Estadisticas/TraspasoService, via migracion_traspaso_vending.sql):
-- ese vive dentro de cat_SucursalClinica (fila real <-> fila virtual) y no
-- sabe nada de vendingm.maquinas. Esta columna es solo para que el panel
-- pueda mostrar "esta maquina SI/NO tiene sucursal vinculada" -- no cablea
-- ningun flujo de traspaso/inventario todavia.
--
-- Ejecutar en SQL Server, en la base "erpanl". Es idempotente. Correr las
-- sentencias una por una (no como un solo batch).
-- =====================================================================

IF COL_LENGTH('vendingm.maquinas', 'id_sucursal_clinica') IS NULL
BEGIN
    ALTER TABLE vendingm.maquinas ADD id_sucursal_clinica INT NULL
        CONSTRAINT FK_maquinas_sucursal_clinica REFERENCES cat_SucursalClinica(id_SucursalClinica);
END

SELECT COL_LENGTH('vendingm.maquinas', 'id_sucursal_clinica') AS confirmacion;
