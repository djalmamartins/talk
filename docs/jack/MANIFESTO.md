# Jack — Manifesto e especificação técnica

Documento mestre e vivo da Jack (Jaqueline) no Connect Talk. Consolida as definições das conversas
do Djalma (setembro a novembro de 2026) e confronta cada uma com o código do repositório em
16/11/2026 (versão 0.21.0, commit `bfcfb48`).

- **Regras em vigor:** a [ADR 0004](../decisions/0004-jack-primeiro-atendimento.md) continua sendo a
  regra de comportamento. Onde este manifesto descreve algo diferente da ADR, vale a ADR até uma nova
  decisão do Djalma ser registrada nela.
- **Legenda de situação:** **FEITO** = existe no código e tem teste; **PARCIAL** = existe uma parte;
  **PLANEJADO** = está no roadmap da Jack V1; **JACK COMPLETA** = evolução futura, fora da V1;
  **PENDENTE** = depende de decisão do Djalma ou da API do ERP.
- **Cuidado com a história:** a auditoria de 16/10/2026 descreveu uma Jack antiga, em Node
  (`server.js`, SQLite, OpenAI opcional, 69 testes de memória). Aquele código **não existe neste
  repositório**. Nada daquela versão conta como feito aqui sem estar no código PHP.

## 1. Visão e identidade

A Jack é a camada inteligente entre clientes, equipe e sistemas da Connect. A frase que resume o papel
dela continua valendo:

> A Jack recebe, entende, coleta, resume, escolhe a fila ou o atendente e entrega o contexto.
> A equipe resolve o que precisa de intervenção humana.

As regras de negócio do Talk decidem o que pode acontecer. A inteligência (regras hoje, IA depois)
interpreta a situação e ajuda a decidir como agir. A Jack nunca tem acesso direto ao banco: ela pede
operações a serviços do Talk, e o serviço valida cada uma.

Identidade (decidida em 14/11/2026, ADR 0004):

- Nome **Jaqueline**, apelido **Jack** (nunca "Jaque"). Mensagens assinadas `*Jack:*`, no mesmo formato
  dos atendentes.
- Discreta: sem selo "automático", sem foto, sem destacar robô ou IA para o cliente.
- Nunca nega o que é. Se perguntarem, responde com o texto aprovado: "Eu sou a inteligência artificial
  do time da Connect. Meu nome é Jaqueline, mas por aqui o time de TI me chama de Jack. Estou aqui só
  para agilizar o seu atendimento."

Duas etapas, para não tentar fazer tudo de uma vez:

| | Jack V1 (este repositório, até a v1.0 do Talk) | Jack Completa (depois) |
|---|---|---|
| Foco | Primeiro atendimento: triagem, fila, protocolo, contexto para a equipe | Inteligência operacional para vários sistemas Connect |
| Conversa | Só textos aprovados (ver seção 3) | Conversa natural, se aprovado |
| Operações | Chamado no Help Desk, boleto (quando o ERP tiver a rota), encaminhamento | Consultas e operações autorizadas no ERP e outros sistemas |
| Automações | Lembrete de espera, aviso fora do horário | Gatilhos, fluxos, agendamentos |
| Integrações | Contratos REST com o ERP (`docs/api-erp.md`) | Integrações adicionais |
| Desenvolvimento | Não é objetivo | Apoio técnico aos sistemas |

Regra herdada da instrução do Moves Talk V1 (27/09): **não construir o ERP dentro do Talk** e não
transformar a Jack V1 numa integração completa com o ERP.

## 2. Decisão: a Jack V1 só encaminha, calada

As definições de outubro descrevem uma Jack que **conversa** e faz perguntas progressivas:

> Cliente: Estou com um problema no portão. — Jack: Entendi! O portão não está abrindo ou está
> apresentando dificuldade para fechar?

Os pedidos mais recentes do Djalma vão no sentido contrário e já estão no código:

- 15/11 (0.18.0): "a Jack encaminha calada". Encaminhar para o setor **não manda mensagem** ao cliente.
- 16/11 (0.20.1): assunto claro entra na fila do setor **na hora**; só um "bom dia" não tem resposta.

Hoje a Jack só fala com o cliente em três casos: quando perguntam se é robô (apresentação), quando a
pessoa segue perguntando algo fora do escopo depois disso (transfere), e fora do horário (aviso de quando
a equipe volta, se ligado).

**DECIDIDO em 16/11/2026 pelo Djalma: opção 1.** Registrado na ADR 0004. As opções avaliadas foram:

1. **Calada na V1** (como está): só encaminha; conversa fica para a Jack Completa.
2. **Uma pergunta de desempate com texto aprovado**: quando o assunto não está claro ou empata, ela
   pergunta uma vez, com uma frase cadastrada pelo supervisor ("O portão não abre ou não fecha?"), e
   encaminha com a resposta. Nunca escreve frase livre.
3. **Conversa livre com IA**: exige mudar a ADR 0004 (hoje proíbe texto livre), controle de custo,
   minimização de dados e o laboratório funcionando antes.

As opções 2 e 3 ficam para a Jack Completa.

## 3. Personalidade e comportamento

Quando a Jack fala (hoje nos três casos acima, e no que for aprovado):

- Natural, educada, acolhedora e objetiva, sem parecer robótica.
- Usa o primeiro nome da pessoa quando o ERP identifica com segurança.
- Pergunta só o que falta e nunca repete o que já foi respondido.
- Lê a conversa inteira, não só a última mensagem (já é assim: junta tudo o que a pessoa mandou antes
  de decidir).
- Entende erro de digitação, falta de acento e mensagens picadas (normalização e espera de 5 s).
- Não obriga a pessoa a preencher formulário antes de abrir o chamado: o protocolo nasce com o que houver.
- "Digita" antes de responder (20 s + tamanho do texto, até 90 s), como uma pessoa.

Trava da V1 (ADR 0004): **só textos aprovados**, em `src/Service/Texts.php` hoje e numa tabela de modelos
editável pelo supervisor no futuro. Áudio transcrito ainda não existe (PLANEJADO só com IA).

## 4. Regras de atendimento

1. Toda conversa nova entra na fila do setor **Geral**, à vista da equipe (desde a 0.23.0).
2. Número de WhatsApp dedicado a um setor: vai direto para ele, sem passar pelo Geral.
3. Depois de `JACK_GENERAL_SECONDS` (60 s), com a pessoa sem escrever há `JACK_WAIT_SECONDS` (5 s), a Jack
   lê tudo e distribui calada.
4. Se alguém aceita ou transfere no Geral antes, a Jack não mexe mais na conversa.
5. Sem o setor Geral, vale o fluxo antigo: assunto claro vai na hora; só "bom dia" espera até 30 s.
6. Não reconheceu, empatou ou só cumprimentou: vai para o setor padrão (Administrativo).
7. Toda conversa vira chamado com protocolo SAC (Help Desk no ERP quando a API estiver ligada).
8. Atendente pode transferir a qualquer momento; a transferência vira o gabarito de erro da Jack.
9. Com atendente humano na conversa, a Jack não responde mais.
10. Saída para humano a qualquer momento ("atendente", "falar com alguém").

## 5. Arquitetura de inteligência (adaptada ao PHP + MariaDB)

A proposta de outubro citava `server.js`, `db.js` e SQLite. O Talk agora é PHP 8.2 + MariaDB + JS sem
framework, então o desenho fica assim:

```
ENTRADAS        WhatsApp (QR Code e API oficial) · tela do atendente · eventos (worker, horário)
                        │  WebhookHandler → ConversationService
JACK            src/Jack/  Classifier (interpretação)  ·  Jack (decisão)  ·  [Context, Memory: planejados]
                        │  pede ações, nunca grava direto
PERMISSÕES      AccessPolicy · chave JACK_MODE · travas da ADR 0004 · confirmação de ações
                        │
EXECUÇÃO        ConversationService · ContactDirectory/Helpdesk (ERP por REST) · OutboxWorker · MariaDB
                        │
RESULTADO       fila do setor · protocolo · nota interna · mensagem aprovada · talk_jack_decisions
```

Onde mora cada coisa:

| Proposta de outubro | No Talk PHP | Situação |
|---|---|---|
| `server.js` com prompt e regras | `src/Jack/Jack.php`, `src/Jack/Classifier.php` | FEITO (regras, sem prompt) |
| Textos | `src/Service/Texts.php` | FEITO; tabela editável PLANEJADA |
| `db.js` / SQLite | MariaDB, migrations em `migrations/` (`003_jack.sql`) | FEITO |
| `data/` | tabelas `talk_jack_*` | PARCIAL |
| `.agents/skills/jack/SKILL.md` + `references/` | `docs/jack/` (este manifesto) e, quando houver IA, `docs/jack/referencias/` | PLANEJADO |

A Skill orienta o agente; não concentra implementação. Quando chegar a IA, as referências previstas
(atendimento, operações, automações, integrações, desenvolvimento, segurança) viram arquivos em
`docs/jack/referencias/` e o texto enviado ao modelo é montado a partir deles por código, com versão.
Uma pasta `.agents/skills/jack/` para Claude Code/Codex só faz sentido para apoio ao desenvolvimento
(Jack Completa).

## 6. Memória e conhecimento

Níveis definidos: conversa atual, histórico do cliente, preferências relevantes, conhecimento
institucional e contexto operacional. Memória **não** é guardar tudo para sempre: selecionar o que
importa, respeitar permissões e permitir correção.

| Nível | No Talk hoje | Situação |
|---|---|---|
| Conversa atual | mensagens da conversa lidas juntas na triagem | FEITO |
| Histórico do cliente | protocolos anteriores da pessoa na lateral (0.20.0), notas do contato | PARCIAL (a Jack não usa) |
| Identidade confiável | telefone → pessoa no ERP (`/api/v1/talk/people`); só age se houver um cadastro | PARCIAL (depende do PR erp#538) |
| Preferências | — | JACK COMPLETA |
| Conhecimento institucional (convenção, regimento) | — | JACK COMPLETA |
| Retenção 180 dias / referências de 30 dias | — | PLANEJADO junto com a memória; regra de LGPD a definir |
| Isolamento entre clientes | por contato e conversa | FEITO no modelo de dados |

## 7. Motor de decisões

| Parte | Hoje | Situação |
|---|---|---|
| Intenções fechadas | `boleto`, `financeiro`, `cotacao`, `administrativo`, `manutencao` | FEITO |
| Interpretação | 116 termos e expressões com peso, normalização sem acento | FEITO |
| Confiança | pontuação mínima 3 e folga 2× sobre o segundo setor | FEITO |
| Pergunta de desempate | — | JACK COMPLETA (decisão de 16/11) |
| Urgência | coluna `priority` existe (fila), a Jack não marca | PLANEJADO |
| Registro de cada decisão | `talk_jack_decisions` (intenção, pontos, termos, setor sugerido e final) | FEITO |
| Taxa de acertos | `GET /api/jack/stats` e seção "Acertos da Jack" no Painel (supervisor e gerentes) | FEITO (0.22.0) |

## 8. Ferramentas e operações

| Ferramenta | Situação |
|---|---|
| Encaminhar para setor/fila | FEITO |
| Abrir chamado com protocolo | FEITO no Talk; chamado no Help Desk do ERP no PR talk#1 + erp#538 (drafts) |
| Verificar chamado duplicado / reabrir existente | PLANEJADO |
| Resumo do problema para o atendente | PLANEJADO (nota interna com os termos e o assunto) |
| Boleto pelo WhatsApp | PENDENTE da rota `GET /api/v1/talk/charges` no ERP |
| Transferir com contexto | FEITO para humanos; a Jack não escreve resumo ainda |
| Consultas e operações no ERP | JACK COMPLETA |

## 9. Automações

| Automação | Situação |
|---|---|
| Aviso fora do horário (com feriados) | FEITO (0.15.0) |
| Espera não conta fora do horário | FEITO (0.21.0) |
| Lembrete de espera a cada 2 h, no máximo 2, depois alerta ao gerente | PLANEJADO (J2 da ADR 0004) |
| Gatilhos e fluxos configuráveis | JACK COMPLETA |

## 10. Integrações

- Só por API REST com o ERP (ADR 0003, `docs/api-erp.md`), token `ctk_` de um usuário do ERP.
- Pessoas e chamados: PR erp#538 (ERP) e talk#1 (Talk), ainda drafts.
- Boletos: rota pendente no ERP.
- IA: nenhum provedor no código. Quando houver, entra por um adaptador (`src/Jack/` + `src/Http`), com
  chave fora do Git e desligável.

## 11. Segurança e permissões

| Ação da Jack | Regra |
|---|---|
| Consultar informações | Conforme permissões do token do ERP e do condomínio da pessoa |
| Analisar dados | Conforme permissões; só o mínimo necessário |
| Abrir chamado | Permitido (autorização operacional do Talk) |
| Enviar mensagem ao cliente | Só textos aprovados; mensagem nova exige aprovação explícita do Djalma |
| Alterar dados | Exige autorização explícita |
| Modificar código | Exige autorização explícita (Jack Completa) |

Também valem:

- **Sem "tenant" nem administradoras.** O texto de outubro fala em isolamento por administradora/tenant;
  isso não se aplica: a Connect é a única administradora (ADR-002 do ERP) e o Talk é de uma empresa só.
  O isolamento é por **condomínio** (do ERP) e por **setor** (no Talk).
- Registro de toda decisão e ação (`talk_jack_decisions`; ações futuras também).
- Lista fechada de ferramentas; a IA nunca chama SQL nem endpoints soltos.
- Limite de custo e de uso da IA (por dia e por conversa) antes de ligar.
- Proteção contra instruções maliciosas nas mensagens: o texto do cliente é dado, nunca instrução; a
  saída da IA é só uma intenção da lista fechada, validada por código.
- Minimização: telefone mascarado nos logs; nada de CPF, valores de outra unidade ou dados de terceiros
  para a IA ou para o cliente.
- Simulação separada de produção: laboratório sem envio real.

## 12. Laboratório de testes

Definição de 17/10: 200 cenários sorteados, clientes simulados por IA, convenção e regimento por
condomínio, quatro operadores simulados (entre eles Ana e Rafael), memória entre interações, supervisão
e avaliador independente. Objetivo: medir se a Jack sabe **quando não responder, quando perguntar,
quando abrir chamado e quando encaminhar**. Sempre isolado, sem mensagem real.

No Talk hoje: `LabGateway` (não envia nada), testes do classificador e da Jack (`tests/Unit/JackClassifierTest.php`,
`tests/Integration/JackTest.php`). Plano: começar com um **conjunto fixo de cenários** em arquivo (texto
→ setor esperado), rodado nos testes, crescendo com os erros reais vindos das transferências. Clientes
simulados por IA e avaliador ficam para quando houver IA (J4).

## 13. Plano de construção da Jack V1

Também em `ROADMAP.md`. Cada etapa é uma entrega pequena, com testes, CHANGELOG e versão.

| Etapa | Entrega | Depende de |
|---|---|---|
| JV1.0 | Este manifesto; decisão da seção 2 registrada na ADR 0004 (feito: calada) | Djalma |
| JV1.1 | Painel de acertos na tela (supervisor): taxa por setor, últimos erros, termos que levaram ao erro | só o Talk |
| JV1.2 | Cenários fixos de triagem (laboratório mínimo, 100+ frases reais anonimizadas) rodando nos testes | JV1.1 |
| JV1.3 | Resumo para o atendente: nota interna com assunto detectado, termos e protocolos anteriores da pessoa | só o Talk |
| JV1.4 | Urgência: termos de risco (gás, curto, elevador parado com gente, alagamento) marcam prioridade alta e avisam o gerente | só o Talk |
| JV1.5 | Chamado duplicado: mesma pessoa e mesmo setor com chamado aberto → junta na conversa existente | PR talk#1/erp#538 |
| JV1.6 | Lembrete de espera (2 h, máx. 2, alerta ao gerente) com modelos aprovados editáveis | só o Talk |
| JV1.7 | Fora da V1: pergunta de desempate vai para a Jack Completa | decisão de 16/11 |
| JV1.8 | Boleto pelo WhatsApp com as travas da ADR 0004 | rota `talk/charges` no ERP |
| JV1.9 | (Opcional) IA só para classificar na lista fechada, com custo limitado e minimização | JV1.2 estável + aprovação |

Jack Completa (sem data): memória com retenção 180/30 dias, conhecimento institucional, automações
configuráveis, operações no ERP, laboratório com clientes simulados e avaliador, apoio ao desenvolvimento.

## 14. Métricas, homologação e produção

Métricas: taxa de acerto por setor (gabarito = setor final), conversas que caíram no padrão por falta
de assunto, tempo até a fila, lembretes enviados, boletos enviados e devolvidos ao Financeiro.

Para ligar cada etapa em produção:

1. Testes passando em MariaDB e em MySQL 8.
2. Cenários fixos com acerto mínimo combinado com o Djalma (sugestão: 90% e nenhum erro de urgência).
3. Uma semana no laboratório ou com a chave desligada, revisando as decisões.
4. Aprovação explícita do Djalma para qualquer texto novo enviado ao cliente.
5. Chave para desligar sem deploy (`JACK_MODE=off` hoje; por função depois).

## Tabela consolidada: implementado, planejado e falta

| Definição | Situação | Onde / próximo passo |
|---|---|---|
| Identidade Jaqueline/Jack, assinatura, apresentação | FEITO | `Texts.php`, `Jack::converse()` |
| Triagem sem menu, lendo a conversa toda | FEITO | `ConversationService::triage()` |
| Fila instantânea com assunto claro | FEITO, só sem o setor Geral | `Jack::isClear()` |
| Encaminhar calada | FEITO | ADR 0004, 0.18.0 |
| Fora do escopo → transfere | FEITO | `Jack::converse()` |
| Aviso fora do horário | FEITO | `BusinessHours` |
| Registro de decisões e taxa de acertos | FEITO | Painel, JV1.1 (0.22.0) |
| Identificação pelo telefone no ERP | PARCIAL | PR erp#538/talk#1 |
| Chamado no Help Desk com protocolo | PARCIAL | PR erp#538/talk#1 |
| Conversa progressiva / perguntas | JACK COMPLETA | decisão de 16/11 (seção 2) |
| Urgência | PLANEJADO | JV1.4 |
| Resumo para o atendente | PLANEJADO | JV1.3 |
| Chamado duplicado / reabrir | PLANEJADO | JV1.5 |
| Lembrete de espera | PLANEJADO | JV1.6 |
| Setor Geral (tudo chega lá, Jack distribui em 1 min) | FEITO (0.23.0) | ADR 0004 |
| Jack como atendente na Equipe | PLANEJADO | JV1.8 |
| Boleto pelo WhatsApp | PENDENTE (ERP) | JV1.9 |
| Laboratório | PARCIAL (LabGateway e testes) | JV1.2; versão com IA na Jack Completa |
| IA (OpenAI ou outra), prompt modular | Não existe | JV1.10, só classificação |
| Controle de custo e auditoria da IA | Não existe | junto com JV1.10 |
| Áudio transcrito | Não existe | depois da IA |
| Memória 180/30 dias, preferências, conhecimento institucional | Não existe | Jack Completa |
| Automações configuráveis, operações no ERP, apoio ao desenvolvimento | Não existe | Jack Completa |
| Configuração por administradora | Não se aplica | Connect é administradora única |

Observação de limpeza: `Texts::jackAsk()` e `Texts::jackRouted()` ficaram sem uso desde a 0.18.0
(a Jack parou de perguntar e de avisar o encaminhamento). Com a decisão da seção 2, podem sair numa próxima limpeza.
