-- Contatos: bloquear (mensagens da pessoa são descartadas e contadas; nenhuma conversa nova é aberta).
ALTER TABLE talk_contacts ADD COLUMN blocked_at DATETIME NULL;
ALTER TABLE talk_contacts ADD COLUMN blocked_by INT UNSIGNED NULL;
ALTER TABLE talk_contacts ADD COLUMN block_reason VARCHAR(200) NULL;
ALTER TABLE talk_contacts ADD COLUMN blocked_messages INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE talk_contacts ADD KEY ix_contact_name (name);
