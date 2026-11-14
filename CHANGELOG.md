# Changelog

## [0.3.0] — 2026-11-14
### Adicionado
- Jack, fase J1 (ADR 0004): classificador por regras (Financeiro, boleto, Administrativo, cotação,
  Manutenção), modo `JACK_MODE` off/shadow/auto (padrão shadow), nota interna com a sugestão e
  avatar, registro em `talk_jack_decisions` e acertos em `GET /api/jack/stats` (supervisor e gerentes).
- Telas de referência em `docs/referencias/` e plano em `docs/telas.md`; paleta verde e dourado.
### Corrigido
- Quem transfere para outro setor não vê mais o aviso "Sem acesso a esta conversa".

## [0.2.0] — 2026-11-14
### Adicionado
- Aplicação web: login, caixa de entrada (Fila, Meus, Em atendimento, Encerrados), conversa,
  aceitar/responder/transferir com nota/encerrar, busca, tempo real por SSE.
- Simulador de cliente e canal "lab" para testar sem WhatsApp.
- Instalação do laboratório `talk.connect.lab` no XAMPP compartilhado (`tools/local/install-lab.sh`).
- Integração com o ERP por API REST (`ErpApi`, `HttpContactDirectory`, `HttpHelpdesk`) e
  especificação da API para a equipe do ERP (`docs/api-erp.md`).
- Usuários próprios do Talk (`talk_users`).
- ADR 0004 (proposta): Jack, primeiro atendimento automático por regras, com lembrete de espera.
### Alterado
- Contrato da API do ERP alinhado ao repositório `connect-erp`: rotas `/api/v1/talk/*`,
  campos em inglês, resposta `{"data"}`, áreas do Help Desk, idempotência por `talk_protocol`
  e nova rota de boletos em aberto.
- ADR 0003: Talk em hospedagem separada do ERP. Falha do ERP não para o atendimento.

## [0.1.0] — 2026-11-14
### Adicionado
- Fase 1 do núcleo: migrations MariaDB, canais QR Code (Evolution) e oficial (Cloud API),
  protocolo por conversa, menu e roteamento por setor, identificação pelo telefone,
  permissões por setor/gerente/supervisor, transferência com nota, busca, SSE, worker de envio.
- 30 testes automatizados (unitários + integração com MariaDB 11.4).
