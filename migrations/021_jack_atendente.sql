-- Jack (Jaqueline) cadastrada como atendente (pedido do Djalma em 16/11/2026, etapa JV1.8).
-- is_bot = 1: não faz login (sem senha válida), não recebe transferência, não aparece como online
-- e não pode ser editada nem desativada pela tela. Ligar/desligar a Jack continua sendo JACK_MODE.
ALTER TABLE talk_users ADD COLUMN is_bot TINYINT(1) NOT NULL DEFAULT 0 AFTER active;

INSERT INTO talk_users (name, email, password_hash, active, is_bot)
SELECT 'Jaqueline (Jack)', 'jack@talk.connect', '!sem-login', 1, 1 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM talk_users WHERE is_bot = 1 OR email = 'jack@talk.connect');

-- ela trabalha no setor Geral (na primeira instalação, o bin/setup.php faz essa ligação)
INSERT INTO talk_staff (user_id, sector_id, role)
SELECT u.id, s.id, 'agent' FROM talk_users u JOIN talk_sectors s ON s.jack_key = 'geral'
 WHERE u.is_bot = 1
   AND NOT EXISTS (SELECT 1 FROM talk_staff st WHERE st.user_id = u.id AND st.sector_id = s.id);
