-- Situação dentro do atendimento (o status principal continua bot/pending/open/closed):
-- "aguardando cliente" ou "aguardando retorno interno". Vazio = em atendimento normal.
ALTER TABLE talk_conversations ADD COLUMN stage VARCHAR(20) NULL AFTER status;
