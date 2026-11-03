-- =====================================================================
-- Solicitudes de pedido automáticas por bajo stock (máquinas nuevas, sin
-- sucursal real vinculada -- no depende de cat_SucursalClinica/erpanl).
-- Ejecutar en SQL Server, en la base "erpanl". Es idempotente.
--
-- NOTA: este script es opcional. App\Vending\SolicitudPedidoGenerador
-- (src/Vending/SolicitudPedidoGenerador.php) auto-crea estas mismas tablas
-- la primera vez que se dispara (mismo patrón que
-- App\Dispensacion\SalidaRegistrador::asegurarTablas()), para no depender
-- de que alguien recuerde correr un script a mano -- que es justo lo que
-- dejó rota la integración con cat_SucursalClinica
-- (system/adminVending/sql/migracion_traspaso_vending.sql, nunca ejecutado).
-- Correr este script explícito sirve para inspeccionar el esquema antes de
-- que la app lo cree sola, o para ambientes donde el usuario de conexión de
-- la app no tenga permiso de CREATE TABLE.
-- =====================================================================

IF OBJECT_ID('vendingm.estatus_solicitud_pedido', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.estatus_solicitud_pedido (
        id_estatus TINYINT NOT NULL PRIMARY KEY,
        nombre NVARCHAR(40) NOT NULL
    );
END;

IF NOT EXISTS (SELECT 1 FROM vendingm.estatus_solicitud_pedido WHERE id_estatus = 1)
    INSERT INTO vendingm.estatus_solicitud_pedido (id_estatus, nombre) VALUES (1, 'Pendiente');
IF NOT EXISTS (SELECT 1 FROM vendingm.estatus_solicitud_pedido WHERE id_estatus = 2)
    INSERT INTO vendingm.estatus_solicitud_pedido (id_estatus, nombre) VALUES (2, 'Enviado');
IF NOT EXISTS (SELECT 1 FROM vendingm.estatus_solicitud_pedido WHERE id_estatus = 3)
    INSERT INTO vendingm.estatus_solicitud_pedido (id_estatus, nombre) VALUES (3, 'Entregado');
IF NOT EXISTS (SELECT 1 FROM vendingm.estatus_solicitud_pedido WHERE id_estatus = 4)
    INSERT INTO vendingm.estatus_solicitud_pedido (id_estatus, nombre) VALUES (4, 'Cancelado');

IF OBJECT_ID('vendingm.solicitudes_pedido', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.solicitudes_pedido (
        id_solicitud INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_solicitudes_pedido PRIMARY KEY,
        id_maquina INT NOT NULL CONSTRAINT FK_solicitudes_maquina REFERENCES vendingm.maquinas(id_maquina),
        id_carril INT NOT NULL CONSTRAINT FK_solicitudes_carril REFERENCES vendingm.carriles(id_carril),
        id_producto INT NULL CONSTRAINT FK_solicitudes_producto REFERENCES vendingm.productos(id_producto),
        cantidad_detectada INT NOT NULL,
        id_estatus TINYINT NOT NULL CONSTRAINT DF_solicitudes_estatus DEFAULT 1
            CONSTRAINT FK_solicitudes_estatus REFERENCES vendingm.estatus_solicitud_pedido(id_estatus),
        fecha_creacion DATETIME2(0) NOT NULL CONSTRAINT DF_solicitudes_fecha_creacion DEFAULT SYSDATETIME(),
        fecha_resuelta DATETIME2(0) NULL,
        resuelto_por INT NULL CONSTRAINT FK_solicitudes_resuelto_por REFERENCES vendingm.usuarios(id_usuario),
        notas NVARCHAR(500) NULL
    );
    CREATE INDEX IX_solicitudes_pedido_estatus ON vendingm.solicitudes_pedido (id_estatus, fecha_creacion);
END;

-- ---------------------------------------------------------------------
-- Verificación rápida
-- ---------------------------------------------------------------------
-- SELECT * FROM vendingm.estatus_solicitud_pedido;
-- SELECT TOP 20 * FROM vendingm.solicitudes_pedido ORDER BY id_solicitud DESC;
