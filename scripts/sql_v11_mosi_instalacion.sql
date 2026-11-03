-- Configuración inicial MOSI mediante usuario instalador.
-- El backend también aplica estas columnas automáticamente al invocar las APIs.
IF COL_LENGTH('vendingm.telemedicina_mosi', 'id_sucursal_clinica') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD id_sucursal_clinica INT NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'videoloop_host') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD videoloop_host NVARCHAR(100) NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'videoloop_port') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD videoloop_port INT NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'videoloop_email') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD videoloop_email NVARCHAR(180) NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'videoloop_clave_sha256') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD videoloop_clave_sha256 CHAR(64) NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'impresora_ip') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD impresora_ip NVARCHAR(100) NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'impresora_puerto') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD impresora_puerto INT NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'instalada_por') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD instalada_por INT NULL;
IF COL_LENGTH('vendingm.telemedicina_mosi', 'fecha_instalacion') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi ADD fecha_instalacion DATETIME2 NULL;
