-- Sugestões para a Jack melhorar (pedido do Djalma em 22/11/2026, tela Serviço da Jack).
-- Quem acompanha o atendimento dela diz o que ela devia ter entendido, respondido ou feito.
-- Viram exemplos (gabarito) para ajustar as regras hoje e para a IA depois.
CREATE TABLE IF NOT EXISTS talk_jack_feedback (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT UNSIGNED NULL,
  author_user_id INT UNSIGNED NOT NULL,
  kind ENUM('ideia','resposta','procedimento','outro') NOT NULL DEFAULT 'outro',
  expected_sector_id INT UNSIGNED NULL,                 -- setor que seria o certo, quando a sugestão é sobre isso
  body VARCHAR(1000) NOT NULL,
  status ENUM('nova','aplicada','descartada') NOT NULL DEFAULT 'nova',
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_jack_feedback_status (status, id),
  KEY ix_jack_feedback_conv (conversation_id),
  CONSTRAINT fk_jack_feedback_conv FOREIGN KEY (conversation_id) REFERENCES talk_conversations (id) ON DELETE SET NULL,
  CONSTRAINT fk_jack_feedback_author FOREIGN KEY (author_user_id) REFERENCES talk_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
