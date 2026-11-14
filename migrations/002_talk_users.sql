-- Usuários do Talk (hospedagem separada do ERP). Futuro: login único com o ERP (SSO).
CREATE TABLE IF NOT EXISTS talk_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  erp_user_id INT UNSIGNED NULL,              -- usuário correspondente no ERP (para registrar quem fez o quê no chamado)
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
