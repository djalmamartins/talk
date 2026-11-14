-- Etiquetas: ficam no contato (seguem a pessoa em todas as conversas) e aparecem na lista.
CREATE TABLE IF NOT EXISTS talk_tags (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) NOT NULL,
  color CHAR(7) NOT NULL DEFAULT '#12804A',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tag_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS talk_contact_tags (
  contact_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (contact_id, tag_id),
  KEY idx_contact_tags_tag (tag_id),
  CONSTRAINT fk_ct_contact FOREIGN KEY (contact_id) REFERENCES talk_contacts (id) ON DELETE CASCADE,
  CONSTRAINT fk_ct_tag FOREIGN KEY (tag_id) REFERENCES talk_tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notas do contato (o cliente não vê): valem para todas as conversas da pessoa.
CREATE TABLE IF NOT EXISTS talk_contact_notes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  contact_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_contact_notes (contact_id, id),
  CONSTRAINT fk_cn_contact FOREIGN KEY (contact_id) REFERENCES talk_contacts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
