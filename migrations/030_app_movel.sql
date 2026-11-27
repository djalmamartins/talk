-- App do Talk para iPhone e Android (React Native, pedido do Djalma em 27/11/2026). docs/app-movel.md
-- O app não usa o cookie da sessão: cada aparelho recebe um token próprio (guardado só o hash), que pode ser revogado,
-- e o endereço de notificação (Expo push) daquele aparelho.
CREATE TABLE IF NOT EXISTS talk_app_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  device_name VARCHAR(100) NOT NULL DEFAULT '',
  platform VARCHAR(10) NOT NULL DEFAULT '',
  push_token VARCHAR(200) NULL,
  erp_checked_at INT UNSIGNED NOT NULL DEFAULT 0,  -- última vez que o ERP confirmou o acesso (como na sessão da web)
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  UNIQUE KEY uq_app_token (token_hash),
  KEY ix_app_token_user (user_id),
  KEY ix_app_token_push (push_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Código de uso único (2 minutos) que o navegador do login devolve ao app pelo endereço connecttalk://entrar.
CREATE TABLE IF NOT EXISTS talk_app_login_codes (
  code_hash CHAR(64) NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  state VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
