/* Migracion idempotente para eventos tecnicos de telemedicina MOSI. */
IF OBJECT_ID('vendingm.telemedicina_mosi_eventos', 'U') IS NULL
    THROW 50001, 'Primero ejecute las migraciones base de telemedicina MOSI.', 1;

IF COL_LENGTH('vendingm.telemedicina_mosi_eventos', 'request_id') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi_eventos ADD request_id NVARCHAR(120) NULL;

IF COL_LENGTH('vendingm.telemedicina_mosi_eventos', 'sesion_id') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi_eventos ADD sesion_id NVARCHAR(120) NULL;

IF COL_LENGTH('vendingm.telemedicina_mosi_eventos', 'tipo_evento') IS NULL
    ALTER TABLE vendingm.telemedicina_mosi_eventos ADD tipo_evento NVARCHAR(40) NULL;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('vendingm.telemedicina_mosi_eventos')
      AND name = 'UX_tm_evento_request'
)
    EXEC(N'CREATE UNIQUE INDEX UX_tm_evento_request
        ON vendingm.telemedicina_mosi_eventos(id_mosi, request_id)
        WHERE request_id IS NOT NULL');
