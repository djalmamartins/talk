-- Número da conversa no formato novo (26/11/2026). Primeiro saiu como SAC + AAAAMMDD; no mesmo dia o Djalma aprovou o
-- modelo final: a conversa tem o número próprio TLK + AAAAMMDD + sequência do dia (4 dígitos, 5 depois de 9999), e o
-- SAC… é só do chamado que nasce no ERP pelo botão Abrir chamado (ADR 0637 do ERP). Converte os números no formato
-- aammdd + 5 dígitos (com ou sem SAC, antes ou depois da migração 027); o antigo fica em legacy_protocol quando ainda
-- não havia outro. Quem já rodou a versão SAC desta migração é corrigido pela 029. Não apaga nada.
UPDATE talk_conversations
   SET legacy_protocol = COALESCE(legacy_protocol, protocol),
       protocol = CONCAT('TLK20', SUBSTRING(RIGHT(protocol, 11), 1, 6),
                         IF(CAST(RIGHT(protocol, 5) AS UNSIGNED) < 10000, RIGHT(protocol, 4), RIGHT(protocol, 5)))
 WHERE protocol REGEXP '^(SAC)?[0-9]{11}$';
