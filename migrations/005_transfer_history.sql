-- Trocar atendente sem "manter histórico": o novo atendente vê a conversa a partir da nota de transferência.
-- Supervisor e gerente do setor continuam vendo tudo.
ALTER TABLE talk_conversations ADD COLUMN history_from_message_id BIGINT UNSIGNED NULL AFTER helpdesk_ticket_id;
