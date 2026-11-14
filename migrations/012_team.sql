-- S5 Atendentes e setores: cadastro pela tela, troca de senha, limite de tentativas de login e registro de auditoria.
ALTER TABLE talk_users ADD COLUMN password_changed_at DATETIME NULL;
ALTER TABLE talk_users ADD COLUMN updated_at DATETIME NULL;

-- jack_key: qual setor a Jack reconhece (renomear o setor não quebra a triagem)
ALTER TABLE talk_sectors ADD COLUMN jack_key VARCHAR(30) NULL AFTER name;
ALTER TABLE talk_sectors ADD COLUMN color CHAR(7) NOT NULL DEFAULT '#12804A' AFTER jack_key;
ALTER TABLE talk_sectors ADD COLUMN description VARCHAR(255) NULL AFTER color;
ALTER TABLE talk_sectors ADD UNIQUE KEY uq_sector_jack (jack_key);
UPDATE talk_sectors SET jack_key = 'financeiro', color = '#12804A' WHERE name = 'Financeiro';
UPDATE talk_sectors SET jack_key = 'administrativo', color = '#2F6FB2' WHERE name = 'Administrativo';
UPDATE talk_sectors SET jack_key = 'manutencao', color = '#C97A12' WHERE name IN ('Manutenção', 'Manutencao');

CREATE TABLE IF NOT EXISTS talk_login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(150) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_login_email (email, created_at),
  KEY ix_login_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- quem mudou o quê no cadastro (sem senhas)
CREATE TABLE IF NOT EXISTS talk_audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  target_type VARCHAR(20) NOT NULL,
  target_id INT UNSIGNED NULL,
  details TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_audit_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
