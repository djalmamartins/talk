-- Protocolo igual ao do SAC (escolha do Djalma em 26/11/2026, ADR 0637 do ERP): SAC + AAAAMMDD + sequência do dia
-- com 4 dígitos (5 depois de 9999). Assim o ERP adota o número da conversa quando o atendente abre o chamado e os
-- dois sistemas mostram o mesmo protocolo. Converte os números no formato aammdd + 5 dígitos (com ou sem SAC, antes ou
-- depois da migração 027); o número antigo fica em legacy_protocol quando ainda não havia outro. Não apaga nada.
UPDATE talk_conversations
   SET legacy_protocol = COALESCE(legacy_protocol, protocol),
       protocol = CONCAT('SAC20', SUBSTRING(RIGHT(protocol, 11), 1, 6),
                         IF(CAST(RIGHT(protocol, 5) AS UNSIGNED) < 10000, RIGHT(protocol, 4), RIGHT(protocol, 5)))
 WHERE protocol REGEXP '^(SAC)?[0-9]{11}$';
