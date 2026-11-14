# ADR 0004 — Jack (Jaqueline): primeiro atendimento automático e controlado

- Status: **Aceita** (aprovada pelo Djalma em 14/11/2026; J1 implementada em modo sombra)
- Data: 14/11/2026

## Contexto

O Djalma quer uma "atendente" virtual, a **Jaqueline**, apelido **Jack**, que faça o primeiro
atendimento de toda conversa que entra na fila:

1. Lê a mensagem e **direciona em silêncio** para Financeiro, Administrativo (inclui cotação de
   preço/orçamento) ou Manutenção. Exemplo: "passei no corredor e tem água no chão" → Manutenção.
2. Quando a pessoa pede o **boleto**, identifica a pessoa pelo telefone no ERP (condomínio e
   unidade), busca o boleto em aberto e envia pelo WhatsApp a linha digitável/Pix e o link,
   **junto com a instrução de como pegar pelo aplicativo** ("mas estou te mandando aqui também").
3. **Lembrete de espera**: se a pessoa escreveu e ninguém respondeu, a cada 2 horas a Jack manda
   uma mensagem com o nome dela (vindo do ERP pelo telefone), como "Seu João, só um minutinho, a
   gente já está vendo e já vai te responder sobre a situação".
4. No começo **não precisa ser IA**; pode evoluir aos poucos.
5. Tem que ser **muito segura e controlada**: não pode responder o que não deve.

## Decisão

A Jack é um **motor de regras** dentro do Talk (não um chatbot livre), com quatro travas:

1. **Intenções fechadas.** Ela só reconhece uma lista fixa: `financeiro`, `administrativo`,
   `cotacao` (→ Administrativo), `manutencao` e `boleto`. Qualquer outra coisa ou dúvida →
   fica na fila geral para um humano (o supervisor ou quem pegar primeiro).
2. **Só textos aprovados.** Ela nunca escreve texto livre. Cada resposta é um modelo cadastrado e
   aprovado pelo supervisor (tabela `talk_jack_templates`), com campos preenchidos pelo sistema
   (nome, condomínio, unidade, vencimento, valor, linha digitável). Direcionar para setor **não
   gera mensagem** ao cliente.
3. **Limiar de confiança.** A classificação pontua palavras e expressões por setor
   (ex.: "água", "vazamento", "lâmpada", "portão" → Manutenção; "boleto", "2ª via", "pagamento",
   "taxa" → Financeiro; "orçamento", "cotação", "documento", "assembleia" → Administrativo).
   Só direciona se um setor ganhar com folga (configurável). Empate ou pontuação baixa = humano.
4. **Boleto só com identificação inequívoca.** A Jack só envia boleto se:
   - o telefone identificar **um único** cadastro ativo no ERP (vários condomínios ou unidades =
     passa para o Financeiro);
   - o vínculo for proprietário ou responsável financeiro (inquilino: decisão pendente);
   - o ERP confirmar telefone + morador na rota `GET /api/v1/talk/charges` (ver `docs/api-erp.md`);
   - houver no máximo 3 boletos em aberto e nenhum vencido há mais de 60 dias.
   Caso contrário, encaminha ao Financeiro com uma nota interna explicando o motivo.

Lembrete de espera (função própria, independente da classificação):

- Um job a cada 5 minutos procura conversas `pending`/`open` cuja **última mensagem é do cliente**
  e sem resposta humana há mais de `JACK_REMINDER_MINUTES` (padrão 120).
- Envia o modelo aprovado `lembrete_espera` com o primeiro nome do contato; sem nome
  identificado, usa a versão sem nome ("Olá! Só um minutinho...").
- **Horário comercial** configurável (padrão seg–sex 8h–18h, sáb 8h–12h); fora dele não envia, e
  o relógio de 2 h conta só dentro do horário.
- **Máximo de lembretes** por espera (padrão 2). Qualquer resposta humana zera a contagem;
  depois do máximo, a conversa ganha um alerta para o gerente do setor em vez de outro lembrete.
- O lembrete aparece na conversa como mensagem da Jack (marcada como automática) e não conta
  como "resposta" para o tempo de atendimento.

Mensagens com contexto, sem texto livre:

- Os modelos têm **variações por setor e por intenção** e campos preenchidos pela conversa:
  nome, condomínio, unidade, setor, protocolo, o assunto detectado ("vazamento", "boleto",
  "orçamento") e há quanto tempo a pessoa espera. Exemplo do lembrete na Manutenção:
  "Seu João, só um minutinho! A equipe de Manutenção já está vendo o vazamento no Residencial
  Jardins e já te responde. Protocolo 20261114-00001."
- A Jack escolhe a variação pelo contexto (setor atual, intenção, se já houve resposta antes,
  se é o 1º ou 2º lembrete), mas o texto continua sendo um dos aprovados. Assim a mensagem soa
  natural e ninguém recebe algo que não foi revisado.
- Na fase de IA (J4), o modelo pode ajudar a **escolher** a variação e o assunto detectado,
  nunca escrever a frase.

Controles operacionais:

- **Chave geral** (liga/desliga) e **modo sombra**: na primeira fase a Jack só *sugere* o setor
  e o boleto numa nota interna, sem agir; o supervisor acompanha os acertos e depois liga o modo
  automático por intenção (ex.: liga direcionamento antes de liga boleto).
- **Registro de cada decisão** (`talk_jack_decisions`): mensagem, intenção, pontuação, ação,
  modelo usado, e quem corrigiu. Painel de acertos para o supervisor.
- **Atendente sempre pode corrigir**: transferência manual vira exemplo de erro da Jack.
- **Saída para humano a qualquer momento**: se a pessoa escrever "atendente", "falar com
  alguém" ou responder de forma inesperada após o boleto, a conversa vai para um humano.
- **Uma vez por conversa**: o fluxo de boleto roda no máximo uma vez por conversa e o restante
  segue com o Financeiro.
- **LGPD**: nunca mostrar CPF, valores de outra unidade ou dados de terceiros; telefone
  mascarado nos logs.

Evolução para IA (fase posterior, só com aprovação): um modelo de linguagem pode **apenas
classificar** a mensagem dentro da mesma lista fechada de intenções, com a mesma trava de
confiança. Ele não escreve respostas; o texto continua vindo dos modelos aprovados.

## Fases

| Fase | Entrega | Depende de |
|---|---|---|
| J1 | Classificador por regras + direcionamento silencioso + modo sombra + registro das decisões + painel de acertos | Só o Talk |
| J2 | Modelos de resposta aprovados pelo supervisor + chave por intenção + **lembrete de espera** | J1 (nome vem do ERP quando disponível) |
| J3 | Boleto pelo WhatsApp (linha digitável, Pix copia e cola, link do app e instrução) | Rotas `talk/people` e `talk/charges` no ERP |
| J4 | (Opcional) classificação por IA na lista fechada | J1 a J3 estáveis e aprovação |

## Consequências

- Direcionamento funciona mesmo com o ERP fora do ar; o boleto não (vai para o Financeiro).
- Começa conservadora: no início mais conversas vão para humano, de propósito.
- Precisa de uma revisão semanal das decisões no modo sombra antes de ligar cada intenção.

## Atualização 14/11/2026 — presença discreta (pedido do Djalma)

- Para a equipe: a sugestão aparece como uma linha cinza pequena no meio da conversa
  ("Sugestão: Financeiro (boleto)"), sem foto, sem nome e sem o selo "automático". Os termos que
  levaram à sugestão ficam só no registro do supervisor (`talk_jack_decisions`).
- Para o cliente (a partir da J2): as mensagens saem assinadas como **Jaqueline**, no mesmo formato
  dos atendentes ("*Jaqueline:*"), sem citar robô, automação ou inteligência artificial.
- Limite de honestidade: se o cliente perguntar diretamente se está falando com um robô, a Jack não
  responde nem nega; a conversa vai na hora para um atendente humano.
