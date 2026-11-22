-- Carga de teste da Jack (pedido do Djalma em 22/11/2026, bin/jack-carga.php): mensagens fictícias de
-- clientes fictícios (números com DDD 00, que não existe) para ver se ela encaminha certo. Nada sai do Talk:
-- o WhatsApp de verdade e o ERP recusam esses números. "reteste_de": a mesma frase, de outro cliente,
-- mandada de novo porque ela errou.
CREATE TABLE IF NOT EXISTS talk_jack_carga (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rodada VARCHAR(40) NOT NULL,
  conversation_id INT UNSIGNED NULL,
  phone VARCHAR(20) NOT NULL,
  frase VARCHAR(1000) NOT NULL,
  mensagens TINYINT UNSIGNED NOT NULL DEFAULT 1,
  setor_esperado VARCHAR(20) NOT NULL,             -- financeiro | administrativo | manutencao | padrao
  setor_jack VARCHAR(20) NULL,                     -- para onde a Jack mandou com certeza (padrao = sem certeza)
  setor_final VARCHAR(20) NULL,                    -- setor onde a conversa ficou
  exato TINYINT(1) NULL,
  certo TINYINT(1) NULL,                           -- a conversa chegou ao setor certo
  reteste_de INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_jack_carga_rodada (rodada, id),
  KEY ix_jack_carga_reteste (reteste_de),
  CONSTRAINT fk_jack_carga_conv FOREIGN KEY (conversation_id) REFERENCES talk_conversations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
