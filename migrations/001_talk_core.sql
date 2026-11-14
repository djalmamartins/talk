-- Connect Talk — núcleo (MariaDB 10.6+ / MySQL 8)
-- Todas as tabelas com prefixo talk_. Usuários vêm do ERP (user_id = id do usuário no ERP).

CREATE TABLE IF NOT EXISTS talk_channels (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  kind ENUM('qrcode','official','lab') NOT NULL,        -- qrcode = Evolution API · official = Cloud API · lab = simulado
  purpose ENUM('attendance','bulk','both') NOT NULL DEFAULT 'attendance',
  phone VARCHAR(20) NULL,
  config_encrypted TEXT NOT NULL,                        -- JSON cifrado (tokens, ids, URLs)
  status ENUM('active','disconnected','disabled') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS talk_sectors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,                             -- Financeiro, Administrativo, Manutenção
  menu_key VARCHAR(5) NOT NULL,                          -- opção do menu inicial: 1, 2, 3...
  keywords TEXT NULL,                                    -- palavras que sugerem o setor (separadas por vírgula)
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_sector_menu (menu_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quem atende o quê. supervisor: sector_id NULL e vê tudo.
CREATE TABLE IF NOT EXISTS talk_staff (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  sector_id INT UNSIGNED NULL,
  role ENUM('agent','manager','supervisor') NOT NULL DEFAULT 'agent',
  UNIQUE KEY uq_staff (user_id, sector_id),
  CONSTRAINT fk_staff_sector FOREIGN KEY (sector_id) REFERENCES talk_sectors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS talk_contacts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(20) NOT NULL,                            -- só dígitos, com DDI (5531999998888)
  name VARCHAR(150) NULL,
  erp_person_id INT UNSIGNED NULL,                       -- pessoa/morador encontrado no ERP
  erp_snapshot TEXT NULL,                                -- JSON: condomínio, unidade... no momento da identificação
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contact_phone (phone),
  KEY ix_contact_person (erp_person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sequência diária para protocolo AAAAMMDD-NNNNN
CREATE TABLE IF NOT EXISTS talk_protocol_seq (
  day DATE PRIMARY KEY,
  last_value INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

-- Conversa = atendimento = chamado (protocolo)
CREATE TABLE IF NOT EXISTS talk_conversations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  protocol VARCHAR(20) NOT NULL,
  channel_id INT UNSIGNED NOT NULL,
  contact_id INT UNSIGNED NOT NULL,
  sector_id INT UNSIGNED NULL,                           -- NULL enquanto está no menu (status bot)
  assigned_user_id INT UNSIGNED NULL,
  status ENUM('bot','pending','open','closed') NOT NULL DEFAULT 'bot',
  subject VARCHAR(200) NULL,
  helpdesk_ticket_id INT UNSIGNED NULL,                  -- chamado no helpdesk do ERP
  unread INT UNSIGNED NOT NULL DEFAULT 0,
  last_message_at DATETIME NULL,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  closed_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_conv_protocol (protocol),
  KEY ix_conv_inbox (status, sector_id, last_message_at),
  KEY ix_conv_contact (contact_id, channel_id, status),
  KEY ix_conv_assigned (assigned_user_id, status),
  CONSTRAINT fk_conv_channel FOREIGN KEY (channel_id) REFERENCES talk_channels(id),
  CONSTRAINT fk_conv_contact FOREIGN KEY (contact_id) REFERENCES talk_contacts(id),
  CONSTRAINT fk_conv_sector FOREIGN KEY (sector_id) REFERENCES talk_sectors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS talk_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT UNSIGNED NOT NULL,
  direction ENUM('in','out','internal') NOT NULL,        -- internal = nota interna (ex.: transferência)
  author_user_id INT UNSIGNED NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'text',
  body MEDIUMTEXT NULL,
  media_path VARCHAR(255) NULL,
  media_mime VARCHAR(100) NULL,
  provider_message_id VARCHAR(128) NULL,
  ack ENUM('pending','sent','delivered','read','failed','received') NOT NULL DEFAULT 'pending',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_msg_provider (provider_message_id),
  KEY ix_msg_conv (conversation_id, created_at),
  FULLTEXT KEY ft_msg_body (body),
  CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES talk_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fila de envio (processada pelo worker)
CREATE TABLE IF NOT EXISTS talk_outbox (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id BIGINT UNSIGNED NOT NULL,
  channel_id INT UNSIGNED NOT NULL,
  to_phone VARCHAR(20) NOT NULL,
  status ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_outbox_ready (status, next_attempt_at),
  CONSTRAINT fk_outbox_msg FOREIGN KEY (message_id) REFERENCES talk_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eventos de tempo real (lidos pelo endpoint SSE) e trilha de auditoria
CREATE TABLE IF NOT EXISTS talk_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(50) NOT NULL,
  conversation_id INT UNSIGNED NULL,
  sector_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,                             -- quem causou
  payload TEXT NOT NULL,                                 -- JSON
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_events_conv (conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
