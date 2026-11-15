-- S8 Horário de atendimento: configurações gerais (JSON) e feriados.
CREATE TABLE IF NOT EXISTS talk_settings (
  name VARCHAR(60) NOT NULL PRIMARY KEY,
  value MEDIUMTEXT NOT NULL,                         -- JSON
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS talk_holidays (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  day DATE NOT NULL,
  name VARCHAR(80) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_holiday_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- aviso de fora do horário: uma vez por conversa
ALTER TABLE talk_conversations ADD COLUMN off_hours_at DATETIME NULL;
