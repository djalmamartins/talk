-- S6b Envio em massa: modelos aprovados da Meta, campanhas e destinatários.
CREATE TABLE IF NOT EXISTS talk_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  channel_id INT UNSIGNED NOT NULL,
  name VARCHAR(512) NOT NULL,                           -- nome do modelo na Meta (ex.: boleto_disponivel)
  language VARCHAR(15) NOT NULL DEFAULT 'pt_BR',
  category VARCHAR(30) NULL,                            -- UTILITY, MARKETING...
  body TEXT NOT NULL,                                   -- texto com {{1}}, {{2}}... (para a prévia)
  variables TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'APPROVED',       -- situação na Meta
  synced_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_template (channel_id, name, language),
  CONSTRAINT fk_template_channel FOREIGN KEY (channel_id) REFERENCES talk_channels (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS talk_campaigns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  channel_id INT UNSIGNED NOT NULL,
  template_id INT UNSIGNED NOT NULL,
  sector_id INT UNSIGNED NOT NULL,                      -- para onde vão as respostas
  var_map TEXT NULL,                                    -- JSON: variável → "first_name" | "name" | "col:N" | "fixed:texto"
  audience TEXT NULL,                                   -- JSON: {type: tag|list, tag_id?, rows?}
  status ENUM('draft','scheduled','running','paused','done','canceled') NOT NULL DEFAULT 'draft',
  send_at DATETIME NULL,
  per_minute SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  last_batch_at DATETIME NULL,
  KEY ix_campaign_status (status, send_at),
  CONSTRAINT fk_campaign_channel FOREIGN KEY (channel_id) REFERENCES talk_channels (id),
  CONSTRAINT fk_campaign_template FOREIGN KEY (template_id) REFERENCES talk_templates (id),
  CONSTRAINT fk_campaign_sector FOREIGN KEY (sector_id) REFERENCES talk_sectors (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS talk_campaign_recipients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT UNSIGNED NOT NULL,
  contact_id INT UNSIGNED NULL,
  phone VARCHAR(20) NOT NULL,
  name VARCHAR(150) NULL,
  params TEXT NULL,                                     -- JSON com os valores das variáveis
  status ENUM('queued','sent','delivered','read','failed','skipped') NOT NULL DEFAULT 'queued',
  skip_reason VARCHAR(60) NULL,
  provider_message_id VARCHAR(128) NULL,
  error VARCHAR(255) NULL,
  sent_at DATETIME NULL,
  replied_at DATETIME NULL,
  UNIQUE KEY uq_campaign_phone (campaign_id, phone),
  KEY ix_recipient_queue (campaign_id, status),
  KEY ix_recipient_provider (provider_message_id),
  KEY ix_recipient_contact (contact_id, sent_at),
  CONSTRAINT fk_recipient_campaign FOREIGN KEY (campaign_id) REFERENCES talk_campaigns (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- quem respondeu SAIR/PARAR não recebe mais envio em massa (LGPD)
ALTER TABLE talk_contacts ADD COLUMN opt_out_at DATETIME NULL;
