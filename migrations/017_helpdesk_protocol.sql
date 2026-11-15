-- Número do chamado no Help Desk do ERP (ex.: CH2611153FA2): é o que o cliente vê nas mensagens.
-- Sem Help Desk ligado fica vazio e vale o protocolo do Talk.
ALTER TABLE talk_conversations ADD COLUMN helpdesk_protocol VARCHAR(40) NULL;
