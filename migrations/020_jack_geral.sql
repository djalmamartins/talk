-- Setor Geral (pedido do Djalma em 16/11/2026): toda conversa nova entra na fila do Geral e,
-- depois de JACK_GENERAL_SECONDS (padrão 60 s), a Jack distribui para o setor certo.
-- jack_pending = 1 enquanto a conversa está no Geral esperando a Jack; zera quando ela distribui
-- ou quando um atendente aceita ou transfere antes.
ALTER TABLE talk_conversations ADD COLUMN jack_pending TINYINT(1) NOT NULL DEFAULT 0 AFTER status;
ALTER TABLE talk_conversations ADD KEY ix_conv_jack_pending (jack_pending, status);

-- instalações que já têm setores ganham o Geral; na primeira instalação quem cria é o bin/setup.php
INSERT INTO talk_sectors (name, jack_key, menu_key, color, description, keywords)
SELECT 'Geral', 'geral', '0', '#6B7280', 'Toda mensagem nova chega aqui; depois de 1 minuto a Jack distribui para o setor certo.', NULL
  FROM DUAL
 WHERE EXISTS (SELECT 1 FROM talk_sectors)
   AND NOT EXISTS (SELECT 1 FROM talk_sectors WHERE jack_key = 'geral' OR menu_key = '0');
