# Melhorias de arquitetura do Talk (revisão de 2026-11-20)

Status: **em andamento** — etapa 1 feita; etapa 2 com rede de segurança pronta (ver "Andamento").
Base analisada: `claude/project-thread-bhzf0c` em `a977898` (0.22.0).

## Resumo
O Talk está bem organizado nas camadas de baixo: `Domain/` (objetos de valor),
`Contracts/` (ERP trocável: `Http*`, `Sql*`, `Null*`), `Gateway/` (Evolution e Cloud API
atrás de uma fábrica), `Support/Db` com SQL parametrizado e 23 arquivos de teste.
Os problemas estão em **dois pontos de concentração**: o controlador frontal
`public/index.php` (634 linhas) e o `ConversationService` (863 linhas).
É ali que cada nova tela do ROADMAP fica mais cara.

## Achados (por prioridade)

### 1. `public/index.php` faz papel de roteador, controlador e repositório — ALTA
- Todas as rotas (páginas, API, mídia, webhooks, SSE, laboratório) num único
  arquivo com `if`/`preg_match`/`switch` em sequência; a ordem dos `if` decide qual rota vence.
- **13 consultas SQL diretas** (`$app['db']`) no controlador, por exemplo em
  `/api/me`, `GET /api/conversations/{id}` e todo o simulador `/lab/*`
  (inclusive um `UPDATE talk_messages` ali). Essas regras ficam fora dos testes de serviço.
- A assinatura do atendente (`explode(' (', $_SESSION['user_name'])[0]`) aparece 4 vezes.
- As 9 páginas repetem o mesmo bloco `$view(..., ['isLab', 'csrf', 'userName'])`.

**Proposta:** um roteador mínimo próprio (≈60 linhas, sem framework, coerente com o
"orgânico") que mapeia `método + padrão → callable`, e as rotas divididas por área em
`src/Http/Routes/{pages,conversations,team,channels,campaigns,lab}.php`. O `index.php`
fica só com sessão, CSRF, tratamento de erro e despacho. As consultas inline vão para
serviços existentes (`StaffRepository::directory()`, `InboxQuery::detail()`) e um novo
`Lab\Simulator`. A assinatura vira `StaffMember::signature()` ou um helper de sessão.

### 2. `ConversationService` acumula quatro responsabilidades — ALTA
Hoje ele cuida de: (a) entrada de mensagens e avaliação (`handleInbound`, `answerRating`,
`handleStatus`), (b) triagem da Jack, horário e roteamento (`triage`, `offHours`, `route`),
(c) ações do atendente (`accept`, `transfer`, `close`, `setStatus`, `reply`, `sendFile`…)
e (d) integração com o helpdesk do ERP (`openTicket`, `openTicketNow`, `safeHelpdesk`).

**Proposta:** separar em `InboundService` (a), `Triage` (b), `ConversationActions` (c) e
`TicketBridge` (d), com `lock()`, `authorize()`, `summary()` e `queueOutbound()` num
`ConversationStore` compartilhado. Fazer **sem mudar assinaturas públicas**: o
`ConversationService` vira uma fachada que delega, para os 18 testes de integração
continuarem valendo; depois os chamadores migram aos poucos.

### 3. Contêiner de dependências é um array com chaves em texto — MÉDIA
`bootstrap.php` devolve `['db' => …, 'team' => …]`; erro de digitação só aparece em
execução e o editor não ajuda. Metade das classes usa `use`, metade nome completo.
`TeamService` recebe `$_SERVER['REMOTE_ADDR']` na montagem (estado da requisição dentro do serviço).

**Proposta:** uma classe `App` com propriedades `readonly` tipadas (mesma montagem,
mesmo arquivo), e o IP passado por chamada em vez de no construtor.

### 4. Leitura do `.env` e configuração espalhadas — BAIXA
`$env('APP_ENV') === 'lab'` aparece em vários construtores. Um `Config` pequeno
(`isLab`, `appUrl`, limites da fila, Jack) centraliza valores padrão e validação na subida.

### 5. Front-end: `app.js` com 654 linhas — BAIXA
As outras telas já têm JS próprio. Vale extrair de `app.js` o que é comum (fetch com CSRF,
SSE, toasts) para `core.js` quando a próxima tela precisar, não antes.

## O que NÃO mudar
- Os contratos `ContactDirectory`/`Helpdesk` e a fábrica de gateways: já são o desenho certo.
- `Db` com SQL explícito: combina com a decisão de não usar framework/ORM.
- Isolamento por setor no `AccessPolicy`: só garantir que continue sendo chamado nos serviços, não no controlador.

## Ordem sugerida (cada etapa é um PR pequeno, com testes passando)
1. Tirar o SQL do `index.php` para serviços + helper de assinatura (menor risco, ganha teste).
2. Roteador mínimo e rotas por área (comportamento idêntico; conferir com os testes e um passeio no lab).
3. Dividir o `ConversationService` atrás da fachada.
4. Classe `App` tipada e `Config`.
5. `core.js` no front, junto com a próxima tela.

## Riscos e lacunas
- Esta revisão não rodou os testes de integração (o ambiente da revisão não tem MariaDB).
  Cada etapa precisa rodar `vendor/bin/phpunit` completo no lab antes do merge.
- Etapa 2 mexe em todas as URLs: um teste de fumaça das rotas principais deve vir junto.

## Andamento
- **2026-11-20 — etapa 1, parte A (feita):** SQL do `/api/me`, do contato da conversa e do
  simulador `/lab/*` saiu do `index.php`; assinatura num helper só. Restam no `index.php` 4 consultas
  pequenas (health do worker, e-mail do usuário na view, login e último evento do SSE), que vão
  para serviços na parte B.
- **2026-11-20 — etapa 1, parte B (feita):** as 4 consultas restantes foram para `OutboxWorker`,
  `LoginGuard`, `StaffRepository` e `EventBus`. `public/index.php` tem zero acesso direto ao banco.
  Próxima: etapa 2 (roteador mínimo e rotas por área), começando por um teste de fumaça das rotas.
- **2026-11-20 — etapa 2, preparo (feito):** teste de fumaça `RoutesSmokeTest` com retrato de 44 rotas
  (anônimo e supervisor, páginas, API, ações da conversa, simulador, 401/403/404). Conferido que
  renomear uma rota faz o teste falhar. Próxima: `src/Http/Router.php` e as rotas da API de conversas
  por primeiro, mantendo o retrato igual.
- **2026-11-21 — etapa 2, parte A (feita):** `src/Http/Router.php` e rotas de conversa em
  `src/Http/Routes/conversations.php`. Achado de segurança corrigido no caminho: ações por GET
  pulavam o CSRF (ver CHANGELOG). Próxima: mover as demais rotas da API (equipe, canais, envios,
  agendamentos, configurações) para arquivos por área, uma área por rodada.
- **2026-11-21 — etapa 2, parte B (feita):** rotas da equipe em `src/Http/Routes/team.php`; esta área
  não tinha ação por GET. Próxima: canais (`/api/channels`), que tem ações como `connect` e `logout`.
