-- Conexões WhatsApp: setor responsável por número (as conversas daquele número vão direto para ele),
-- número padrão, estado da conexão (QR Code) e quem mexeu.
ALTER TABLE talk_channels ADD COLUMN sector_id INT UNSIGNED NULL AFTER purpose;
ALTER TABLE talk_channels ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER sector_id;
ALTER TABLE talk_channels ADD COLUMN connection_state ENUM('connected','connecting','disconnected','unknown') NOT NULL DEFAULT 'unknown' AFTER status;
ALTER TABLE talk_channels ADD COLUMN state_checked_at DATETIME NULL AFTER connection_state;
ALTER TABLE talk_channels ADD COLUMN last_error VARCHAR(255) NULL AFTER state_checked_at;
ALTER TABLE talk_channels ADD CONSTRAINT fk_channel_sector FOREIGN KEY (sector_id) REFERENCES talk_sectors (id) ON DELETE SET NULL;
UPDATE talk_channels SET is_default = 1 WHERE id = (SELECT id FROM (SELECT MIN(id) id FROM talk_channels WHERE status = 'active') x);
