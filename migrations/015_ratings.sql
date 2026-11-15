-- S7 Avaliação pós-atendimento: ao encerrar, o cliente recebe a pergunta "de 1 a 5".
CREATE TABLE IF NOT EXISTS talk_ratings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT UNSIGNED NOT NULL,
  contact_id INT UNSIGNED NOT NULL,
  sector_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,                         -- quem atendeu
  score TINYINT UNSIGNED NULL,                       -- 1 (ruim) a 5 (ótimo); NULL enquanto não respondeu
  comment VARCHAR(500) NULL,
  asked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  answered_at DATETIME NULL,
  UNIQUE KEY uq_rating_conv (conversation_id),
  KEY ix_rating_contact (contact_id, answered_at),
  KEY ix_rating_user (user_id, answered_at),
  CONSTRAINT fk_rating_conv FOREIGN KEY (conversation_id) REFERENCES talk_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- relatórios por período
ALTER TABLE talk_conversations ADD KEY ix_conv_opened (opened_at);
