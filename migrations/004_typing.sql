-- "Digitando…" antes das mensagens da Jaqueline: o worker primeiro mostra que ela está digitando
-- por typing_seconds e só depois envia o texto.
ALTER TABLE talk_outbox ADD COLUMN typing_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER to_phone;
