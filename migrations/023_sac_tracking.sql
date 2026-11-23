-- Chamado no SAC do ERP (pedido do Djalma em 22/11/2026): o link onde o cliente acompanha o chamado.
-- A Jack manda esse link junto com o número quando abre o chamado, e o usa quando o cliente pergunta o status.
ALTER TABLE talk_conversations ADD COLUMN helpdesk_url VARCHAR(500) NULL AFTER helpdesk_protocol;
-- protocolo do SAC é procurado quando o cliente manda o número no WhatsApp
ALTER TABLE talk_conversations ADD KEY ix_conv_helpdesk_protocol (helpdesk_protocol);
