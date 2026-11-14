# Changelog

## [0.4.1] — 2026-11-14
### Adicionado
- "Digitando…" antes de cada mensagem da Jaqueline (`JACK_TYPING_SECONDS`, padrão 20 s + 1 s a cada
  8 letras, até 90 s): o worker mostra a digitação (Evolution `sendPresence`; API oficial
  `typing_indicator`) e só depois envia. Migration `004_typing.sql`.
- Simulador do laboratório mostra "digitando…" e só exibe ao cliente o que já foi enviado.

## [0.4.0] — 2026-11-14
### Alterado
- Sem menu de números: a Jaqueline espera a pessoa terminar de escrever, entende o assunto, abre o
  chamado no setor certo e avisa com o protocolo. Só "oi" → ela pergunta como pode ajudar. Assunto
  não reconhecido → Administrativo. Triagem roda no `bin/talk-worker` (a cada 5 s).
- Novas variáveis: `JACK_WAIT_SECONDS` (60) e `JACK_MAX_WAIT_SECONDS` (600); `JACK_MODE` = auto | off.

## [0.3.2] — 2026-11-14
### Corrigido
- Compatível com MySQL 8 (o banco do XAMPP do Mac): `last_value` é palavra reservada no MySQL 8 e
  derrubava a criação das tabelas. Testes passam em MariaDB 11.4 e MySQL 8.0.
- `bin/migrate.php` roda comando por comando, mostra o erro e recria tabelas que faltam mesmo se a
  migration já constar como aplicada.

## [0.3.1] — 2026-11-14
### Adicionado
- Jaqueline se apresenta quando perguntam se é robô/IA e passa adiante o que está fora do escopo
  (textos aprovados em `Texts`).
### Alterado
- Novo layout no padrão da tela de referência da Fila: menu lateral cinza, logo dourada da
  Connect Condomínios (provisória, em SVG), cabeçalho da página e painéis em cartões; login escuro.
- Sugestão da Jack aparece como uma linha discreta, sem foto nem selo "automático".

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
