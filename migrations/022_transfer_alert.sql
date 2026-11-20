-- Aviso de transferência (pedido do Djalma em 16/11/2026): quem recebe uma conversa transferida vê no sino.
-- transfer_pending = 1 até alguém do setor aceitar, ou até o atendente que recebeu abrir a conversa.
ALTER TABLE talk_conversations ADD COLUMN transfer_pending TINYINT(1) NOT NULL DEFAULT 0 AFTER jack_pending;
ALTER TABLE talk_conversations ADD COLUMN transferred_by INT UNSIGNED NULL AFTER transfer_pending;
