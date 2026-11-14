-- Fila de atendimento: quando entrou na fila (rota da Jaqueline ou transferência para setor),
-- quando foi aceita pela primeira vez, prioridade manual e presença dos atendentes.
ALTER TABLE talk_conversations ADD COLUMN queued_at DATETIME NULL AFTER opened_at;
ALTER TABLE talk_conversations ADD COLUMN accepted_at DATETIME NULL AFTER queued_at;
ALTER TABLE talk_conversations ADD COLUMN priority ENUM('low','normal','high') NOT NULL DEFAULT 'normal' AFTER status;
ALTER TABLE talk_conversations ADD KEY ix_conv_queue (status, queued_at);
UPDATE talk_conversations SET queued_at = COALESCE(last_message_at, opened_at) WHERE status = 'pending' AND queued_at IS NULL;
ALTER TABLE talk_users ADD COLUMN last_seen_at DATETIME NULL;
