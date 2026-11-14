-- S6 Agendamentos: mensagem marcada para um dia e hora (o worker manda na hora certa).
CREATE TABLE IF NOT EXISTS talk_schedules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contact_id INT UNSIGNED NOT NULL,
  channel_id INT UNSIGNED NOT NULL,
  sector_id INT UNSIGNED NOT NULL,
  origin_conversation_id INT UNSIGNED NULL,          -- conversa de onde foi agendada (se houver)
  body TEXT NOT NULL,
  sign TINYINT(1) NOT NULL DEFAULT 1,                -- assinar com o nome de quem agendou
  send_at DATETIME NOT NULL,
  status ENUM('scheduled','sending','sent','canceled','failed') NOT NULL DEFAULT 'scheduled',
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  canceled_by INT UNSIGNED NULL,
  sent_at DATETIME NULL,
  sent_conversation_id INT UNSIGNED NULL,
  sent_message_id BIGINT UNSIGNED NULL,
  error VARCHAR(255) NULL,
  KEY ix_schedule_due (status, send_at),
  KEY ix_schedule_contact (contact_id),
  CONSTRAINT fk_schedule_contact FOREIGN KEY (contact_id) REFERENCES talk_contacts (id),
  CONSTRAINT fk_schedule_channel FOREIGN KEY (channel_id) REFERENCES talk_channels (id),
  CONSTRAINT fk_schedule_sector FOREIGN KEY (sector_id) REFERENCES talk_sectors (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
