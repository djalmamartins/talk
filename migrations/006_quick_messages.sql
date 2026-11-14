-- Mensagens rápidas (respostas prontas): o atendente digita "/" no campo de resposta e escolhe.
-- sector_id NULL = disponível para todos os setores. Atalho único no sistema inteiro.
CREATE TABLE IF NOT EXISTS talk_quick_messages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shortcut VARCHAR(40) NOT NULL,
  body TEXT NOT NULL,
  sector_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_quick_shortcut (shortcut),
  KEY idx_quick_sector (sector_id),
  CONSTRAINT fk_quick_sector FOREIGN KEY (sector_id) REFERENCES talk_sectors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
