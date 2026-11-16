-- Protocolo no formato SAC + AAMMDD + sequência de 5 dígitos (ex.: SAC26111600004), pedido do Djalma em 16/11.
-- Os protocolos já emitidos passam para o novo formato; o número antigo fica guardado em
-- legacy_protocol e continua achando a conversa na busca. Não apaga nada.
ALTER TABLE talk_conversations ADD COLUMN legacy_protocol VARCHAR(20) NULL;

-- AAAAMMDD-NNNNN (até 0.19) → SAC + AAMMDD + NNNNN
UPDATE talk_conversations
   SET legacy_protocol = protocol,
       protocol = CONCAT('SAC', SUBSTRING(protocol, 3, 6), SUBSTRING(protocol, 10, 5))
 WHERE protocol REGEXP '^[0-9]{8}-[0-9]{5}$';

-- SAC + AAAAMMDD + NNNN (0.20) → SAC + AAMMDD + 0NNNN
UPDATE talk_conversations
   SET legacy_protocol = protocol,
       protocol = CONCAT('SAC', SUBSTRING(protocol, 6, 6), '0', SUBSTRING(protocol, 12, 4))
 WHERE protocol REGEXP '^SAC[0-9]{12}$';

CREATE INDEX ix_conv_legacy_protocol ON talk_conversations (legacy_protocol);
