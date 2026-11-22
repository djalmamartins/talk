# Jack com IA — o que precisa e em que ordem

Pedido do Djalma em 22/11/2026: "vamos partir para ela se transformar numa IA". Este documento é o plano.
Nada aqui está ligado ainda: hoje a Jack funciona por regras (`src/Jack/Classifier.php`) e só manda textos
aprovados (ADR 0004).

## 1. Decisão (Djalma)

Até onde a IA vai na primeira etapa:

| Opção | O que a IA faz | ADR 0004 |
|---|---|---|
| **A. Entende** (recomendada) | Lê a mensagem e escolhe o assunto e o setor numa lista fechada. As respostas continuam sendo os textos aprovados. | Já prevista (fase J4) |
| B. Entende e escreve com revisão | Também redige a resposta, que fica como sugestão na conversa até um atendente aprovar. | Precisa revisar |
| C. Conversa sozinha | Responde o cliente direto, com texto próprio. | Precisa mudar, com limite de custo e regras de LGPD |

**Decidido em 22/11/2026: opção A** (registrado na ADR 0004). Ela ataca o que mais erra hoje (entender o assunto) sem o risco de a Jack
dizer algo errado ao cliente. B e C podem vir depois, em cima do mesmo adaptador.

## 2. O que o Djalma precisa providenciar

1. **Conta na Anthropic (Claude Console)** e uma **chave de API** só para o Talk. A chave vai no `.env`
   do Pi (`JACK_AI_KEY`), nunca no Git.
2. **Limite de gasto mensal** configurado na própria conta (ex.: US$ 10/mês para começar).
3. **Modelo**: para só classificar, o mais barato serve (Claude Haiku 5.5, US$ 0,10 por milhão de tokens de
   entrada e US$ 0,50 de saída; uma mensagem de cliente custa uma fração de centavo). Na opção A a IA não
   escreve, então não precisa de modelo maior.
4. **OK de LGPD** (falta): a mensagem do cliente passa a ser enviada a um serviço externo. Proposta: enviar só o
   texto da mensagem, sem nome, telefone, CPF ou unidade (números com cara de CPF/telefone são mascarados
   antes), e citar isso na política de privacidade da Connect.
5. **Internet de saída no Pi** para `api.anthropic.com` (o túnel da Cloudflare não atrapalha).

## 3. O que eu faço no código (opção A)

1. **JV1.2 antes de tudo: cenários fixos de triagem** (testes com frases reais e o setor certo). É a régua
   para comparar regras e IA. As sugestões da tela Serviço da Jack (`talk_jack_feedback`, com "setor certo")
   e as transferências feitas pela equipe viram esses cenários.
2. **Adaptador** `src/Jack/AiClassifier.php`, com a mesma saída do `Classifier` (intenção, setor, confiança).
   A IA só pode responder uma das intenções da lista; a resposta é validada por código (saída estruturada).
   Se a IA falhar, demorar mais de 3 s ou passar do limite do dia, a Jack volta para as regras.
3. **Modo sombra** (1 a 2 semanas): as regras continuam decidindo; a IA só registra o que teria feito
   (`talk_jack_decisions`, `mode = shadow`, com o modelo usado). A tela Serviço da Jack mostra lado a lado
   "regras disseram X, IA diria Y" e o Painel mostra a taxa de acerto de cada uma.
4. **Ligar** (`JACK_AI=on`) quando a IA acertar mais que as regras nos cenários e na sombra.
5. Limites: chamadas por dia e por conversa, registro de custo por dia na tela.

## 4. Como as sugestões ajudam

Cada sugestão na tela Serviço da Jack vira um exemplo:

- "Setor certo" preenchido → cenário de teste (frase → setor).
- "A resposta dela" → revisão de um texto aprovado (`Service\Texts`).
- "O que ela fez" → ajuste de regra de procedimento.

O supervisor marca a sugestão como **aplicada** quando ela entra no código, ou **descartada**.

## 5. Ordem

| Etapa | Entrega | Depende de |
|---|---|---|
| IA0 | Sugestões na tela Serviço da Jack | feito (1.2.0) |
| IA1 | Cenários fixos (JV1.2): 44 frases em `src/Jack/Scenarios.php`, placar em `php bin/jack-cenarios.php` (inclui as sugestões com setor certo). Regras hoje: 36 de 44 (82%) | feito (1.2.0) |
| IA2 | Adaptador da IA em modo sombra | chave, limite de gasto, OK de LGPD |
| IA3 | IA decidindo o setor, regras como reserva | IA2 melhor que as regras |
| IA4 | (se escolhido) rascunho de resposta com aprovação do atendente | decisão B ou C e revisão da ADR 0004 |

## 6. Onde as regras erram hoje (cenários de 22/11/2026)

As regras ficam sem certeza em 8 das 44 frases, todas com uma palavra só de peso 2 (abaixo do mínimo 3):
"paguei ontem e ainda aparece em aberto", "declaração de quitação de débitos", "vou fazer mudança",
"atualizar meu cadastro", "regimento interno", "falar com o síndico", "quanto custa a administração" e
"recebi uma advertência". Hoje essas conversas vão para o Administrativo (setor padrão), então 3 delas
(as de Financeiro e a da administração por cotação) caem no setor errado. É o primeiro alvo da IA em sombra.
