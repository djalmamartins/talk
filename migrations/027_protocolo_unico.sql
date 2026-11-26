-- Protocolo único com o SAC (pedido do Djalma em 22/11/2026, ADR 0637 do ERP): o número fica sem "SAC" na frente e
-- o chamado aberto pelo botão guarda quem abriu (esse chamado continua no SAC depois que a conversa termina).
ALTER TABLE talk_conversations ADD COLUMN helpdesk_opened_by INT UNSIGNED NULL AFTER helpdesk_url;
UPDATE talk_conversations SET protocol = SUBSTRING(protocol, 4) WHERE protocol REGEXP '^SAC[0-9]{11}$';
