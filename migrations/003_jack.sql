-- Jack (Jaqueline), primeiro atendimento por regras (ADR 0004, fase J1).
-- Cada mensagem do cliente lida pela Jack antes de um humano assumir gera uma decisão.
-- final_sector_id = setor onde a conversa realmente ficou (menu, Jack ou transferência humana):
-- é o gabarito para medir os acertos no modo sombra.
CREATE TABLE IF NOT EXISTS talk_jack_decisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT UNSIGNED NOT NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  mode ENUM('shadow','auto') NOT NULL,
  intent VARCHAR(30) NULL,
  suggested_sector_id INT UNSIGNED NULL,
  confident TINYINT(1) NOT NULL DEFAULT 0,
  scores VARCHAR(255) NOT NULL DEFAULT '{}',
  matched VARCHAR(255) NULL,
  action ENUM('none','suggested','routed') NOT NULL DEFAULT 'none',
  final_sector_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_jack_conversation (conversation_id, id),
  KEY idx_jack_created (created_at),
  CONSTRAINT fk_jack_conversation FOREIGN KEY (conversation_id) REFERENCES talk_conversations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
