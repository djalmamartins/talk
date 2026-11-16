# ADR 0004 — Jack (Jaqueline): primeiro atendimento automático e controlado

- Status: **Aceita** (aprovada pelo Djalma em 14/11/2026; triagem sem menu desde a 0.4.0)
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
  dos atendentes ("*Jack:*"), sem citar robô, automação ou inteligência artificial.
- Se o cliente perguntar se está falando com um robô ou IA (antes de um atendente assumir), ela
  responde com o texto aprovado pelo Djalma: "Eu sou a inteligência artificial do time da Connect.
  Meu nome é Jaqueline, mas por aqui o time de TI me chama de Jack. Estou aqui só para agilizar o
  seu atendimento." Uma vez por conversa.
- Se depois disso a pessoa seguir perguntando algo que ela não sabe, ela responde "Isso está fora do
  meu escopo de treinamento, então vou te transferir para o setor Administrativo. Se tiver mais
  alguma dúvida, eles podem esclarecer." e passa a conversa (Administrativo quando ainda não tem
  setor; se já tem, cita o setor atual). Opção do menu ou assunto claro seguem o fluxo normal.
- Funciona nos modos shadow e auto; com atendente humano na conversa ela não responde.

## Atualização 14/11/2026 — sem menu; a Jaqueline faz a triagem (pedido do Djalma)

- Acabou o menu "1, 2, 3". Toda conversa nova fica em triagem e a Jaqueline espera a pessoa parar de
  escrever (`JACK_WAIT_SECONDS`, padrão 60 s) antes de ler tudo o que ela mandou.
- Assunto claro (ex.: "o portão quebrou", "água no chão do corredor", "segunda via do boleto"): abre
  o chamado no setor certo e responde "Certo, João! Abri o chamado *20261114-00012* e já encaminhei
  para a equipe de *Manutenção*. Já vamos te atender."
- Só cumprimentou ("oi", "bom dia") e parou: pergunta uma vez "Oi, João! Como posso te ajudar?" e
  espera o assunto.
- Assunto que ela não reconhece, dois setores empatados, ou passou `JACK_MAX_WAIT_SECONDS` (padrão
  10 min): vai para o Administrativo com a mesma mensagem de chamado aberto.
- O modo sombra deixou de existir (sem menu não há quem decida no lugar dela). `JACK_MODE=off` manda
  tudo direto para o setor padrão, sem mensagem.
- A taxa de acertos continua em `/api/jack/stats`: conta como erro quando um atendente transfere a
  conversa para outro setor.

## Atualização 16/11/2026 — fila instantânea e encaminhamento calado (pedidos do Djalma)

Substitui o que vem acima onde for diferente:
- Encaminhar para o setor **não manda mensagem ao cliente** (desde 0.18.0). Só um "bom dia" não tem
  resposta: ela espera o assunto. Continua indo o aviso de fora do horário, quando ligado.
- **Assunto claro já na mensagem** ("estamos sem água no bloco B"): a conversa entra na fila do setor
  **na hora**, sem esperar a pessoa parar de escrever.
- Assunto ainda não claro: espera `JACK_WAIT_SECONDS` (padrão **5 s**, era 60 s) sem mensagem nova e
  lê tudo; não reconheceu → Administrativo. Só cumprimentou: espera até `JACK_MAX_WAIT_SECONDS`
  (padrão **30 s**, era 10 min) e então vai para o Administrativo.
- No laboratório (sem worker), a tela aberta faz a triagem e os envios a cada ~2 s pelo canal de
  tempo real.

## Atualização 16/11/2026 — Jack V1 continua calada (decisão do Djalma)

O manifesto (`docs/jack/MANIFESTO.md`) trouxe definições antigas de uma Jack que conversa e faz
perguntas. O Djalma decidiu: **na Jack V1 ela só encaminha, calada**, como está desde a 0.18.0.
Pergunta de desempate e conversa natural ficam para a Jack Completa. Continuam valendo só os três
casos em que ela fala: apresentação quando perguntam se é robô, fora do escopo e aviso fora do horário.

## Atualização 16/11/2026 — setor Geral e Jack como atendente (pedidos do Djalma)

Substitui a "fila instantânea" da 0.20.1 quando o setor Geral existe:
- **Toda conversa nova entra na fila do setor Geral**, à vista de quem atende o Geral (e do supervisor).
- Depois de `JACK_GENERAL_SECONDS` (padrão **60 s**), com a pessoa sem escrever há `JACK_WAIT_SECONDS`
  (ou no dobro do tempo, de qualquer jeito), a Jack lê tudo e **distribui calada** para Financeiro,
  Administrativo ou Manutenção. Assunto desconhecido ou só "bom dia" → Administrativo, como antes.
- Se um atendente **aceita ou transfere** a conversa no Geral antes disso, a Jack não mexe mais nela; o
  chamado abre nesse momento.
- A espera no relatório conta desde a chegada no Geral.
- Número de WhatsApp dedicado a um setor não passa pelo Geral. Sem o setor Geral (ou `JACK_MODE=off`)
  vale o fluxo anterior.

Feito na 0.24.0 (etapa JV1.8): a Jack **cadastrada como atendente** ("Jaqueline (Jack)", `talk_users.is_bot = 1`)
no setor Geral, sem login, autora das mensagens dela. Não recebe transferência, não aparece como online
e não é editada nem desativada pela tela; liga e desliga com `JACK_MODE`. É o primeiro passo para o boleto (J3/JV1.9): quando pedirem
a 2ª via, ela avisa que vai olhar, busca o boleto no ERP, envia linha digitável/Pix e link e lembra
que dá para achar pelo aplicativo, sempre com textos aprovados e as travas da seção "Boleto só com
identificação inequívoca".
