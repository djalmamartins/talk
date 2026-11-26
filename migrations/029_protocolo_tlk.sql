-- Conversa com número próprio TLK (aprovado pelo Djalma em 26/11/2026): a versão anterior da migração 028 e o Talk
-- daquela manhã deram SAC + AAAAMMDD para a conversa. Esses viram TLK com a mesma data e sequência; o número SAC fica
-- em legacy_protocol (quando ainda não havia outro) e continua achando a conversa. O SAC… do chamado no ERP fica em
-- helpdesk_protocol e não muda. Não apaga nada.
UPDATE talk_conversations
   SET legacy_protocol = COALESCE(legacy_protocol, protocol),
       protocol = CONCAT('TLK', SUBSTRING(protocol, 4))
 WHERE protocol REGEXP '^SAC20[0-9]{10,11}$';
