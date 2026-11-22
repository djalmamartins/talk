# Jack com IA — o que precisa e em que ordem

Pedido do Djalma em 22/11/2026: "vamos partir para ela se transformar numa IA". Este documento é o plano.
Por padrão a IA fica desligada (`JACK_AI=off`): a Jack funciona por regras (`src/Jack/Classifier.php`) e só manda textos
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

## 2. Onde a IA roda (decisão de 22/11/2026)

O Djalma escolheu **IA local**: o modelo roda na própria máquina, sem serviço externo, sem chave e sem custo
por mensagem. Modelo escolhido: **Gemma 3 1B** (arquivo de ~1 GB). Qwen3 1.7B e Qwen3 4B ficam só para
comparar, se o Gemma 3 1B não passar das regras. A API da Anthropic fica como reserva para o futuro, pelo
mesmo adaptador.

- **Primeiro no Mac** (pedido do Djalma): Ollama no Mac, `tools/local/jack-ia-lab.sh`.
- **Depois no Raspberry Pi**: serviço `jack-ia` (llama-server, do llama.cpp) no `deploy/docker-compose.yml`,
  desligado por padrão. O ideal é Pi 5 de 8 GB com cooler. O tempo medido no Mac é bem menor que no Pi;
  o que vale para ligar é o tempo medido no Pi.

Como nada sai da máquina, não precisa de OK de envio externo. Mesmo assim, o Talk mascara e-mails e números
longos (CPF, telefone) antes de mandar o texto ao modelo.

### Teste no Mac (passo a passo)

1. `brew install ollama` (ou o aplicativo do site ollama.com).
2. Na pasta do Talk: `tools/local/jack-ia-lab.sh`. Ele inicia o Ollama, baixa o `gemma3:1b`, mostra o
   placar regras x IA nas frases fixas (com o tempo) e pergunta se pode ligar o modo de teste no `.env`
   (`JACK_AI=shadow`).
3. Mande mensagens pelo WhatsApp do lab ou pelo simulador de cliente
   (`docker exec dev-apache php /var/www/html/talk/bin/simular.php "o elevador parou"`) e abra **Serviço da Jack**: cada atendimento mostra "IA em teste" (o que
   ela diria e se bate com onde a conversa ficou) e o topo mostra o placar do período.
4. Para comparar outro modelo: `MODEL=qwen3:1.7b tools/local/jack-ia-lab.sh`.

### No Raspberry Pi (depois do Mac)

1. Baixar o Gemma 3 1B em formato `.gguf` (Q4_K_M ou a versão QAT, ~1 GB; no Hugging Face é preciso aceitar
   a licença do Gemma) e salvar como `deploy/ia/modelo.gguf`.
2. No `deploy/.env`: `COMPOSE_PROFILES=tunnel,ia` e `JACK_AI=shadow`. `docker compose up -d`.
3. Rodar o placar lá dentro para medir o tempo real (as frases ficam fora da imagem, então copie antes):
   `docker compose cp ../tests/Fixtures/jack app:/tmp/jack` e
   `docker compose exec app php bin/jack-cenarios.php --ia --frases=/tmp/jack` (usa `JACK_AI_URL=http://jack-ia:8080`).

## 3. Como funciona no código (opção A)

1. **Frases fixas** da JV1.2 (`tests/Fixtures/jack/`): 105 de ajuste (`triagem.csv`) e 40 de conferência
   (`triagem-conferencia.csv`, escritas depois e não usadas para afinar as regras). Placar regras x IA:
   `php bin/jack-cenarios.php [--ia]` (também usa as sugestões com setor certo). A comparação que vale é a das 40
   de conferência; contam acerto exato, setor certo e **erro grave** (mandou com certeza para o setor errado).
2. **Adaptador** `src/Jack/AiClassifier.php`: chama `/v1/chat/completions` (formato da OpenAI, que o Ollama e
   o llama-server entendem), com instruções fixas (o servidor reaproveita o cache), temperatura 0 e resposta
   em JSON fechado: `{"intencao": boleto|financeiro|cotacao|administrativo|manutencao|nenhuma, "certeza":
   alta|baixa}`. A resposta é conferida de novo no PHP. Falha, demora acima de `JACK_AI_TIMEOUT` (padrão 4 s
   no Pi) ou resposta fora da lista: valem as regras.
3. **`JACK_AI`**: `off` (padrão), `shadow` (as regras decidem; a IA só registra o que faria em
   `talk_jack_decisions` com `mode = shadow`, modelo e tempo) ou `on` (a IA decide quando tem certeza alta;
   nos outros casos, regras). Só cumprimento ("bom dia") não chama a IA.
4. **Tela Serviço da Jack**: "IA em teste" em cada atendimento e o placar do período (acertaria, erraria,
   sem certeza, não respondeu, tempo médio e máximo). Os acertos do Painel contam só as decisões reais.
5. **Ligar** (`JACK_AI=on`) quando, no Pi, a IA acertar mais que as regras nos cenários e na sombra, com
   tempo aceitável.

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
| IA1 | Frases fixas (JV1.2, 0.27.0/0.28.0) e placar regras x IA em `php bin/jack-cenarios.php`. Regras nas 40 de conferência: 60% exato, 77,5% no setor certo, nenhum erro grave | feito |
| IA2 | Adaptador da IA local e modo sombra (Mac: Ollama; Pi: serviço jack-ia) | feito no código (1.2.0); falta o teste no Mac |
| IA3 | IA decidindo o setor, regras como reserva (`JACK_AI=on`) | IA2 melhor que as regras, medida no Pi |
| IA4 | (se escolhido) rascunho de resposta com aprovação do atendente | decisão B ou C e revisão da ADR 0004 |

## 6. Onde as regras erram hoje (frases de conferência, 22/11/2026)

Depois do ajuste das palavras-chave (0.28.0), as regras acertam 24 das 40 frases de conferência (60%) e 77,5%
chegam ao setor certo, sem erro grave. Os erros são todos "sem certeza": a conversa vai para o Administrativo
(setor padrão), o que erra o setor das de Financeiro e Manutenção. Exemplos: "fiz o pix e nao baixou", "to devendo
3 meses, tem como negociar?", "a grade da varanda soltou", "quebraram o vidro da portaria". É o primeiro alvo da IA
em sombra. Para ligar (`JACK_AI=on`), a IA com as regras de reserva precisa ganhar nas 40 de conferência sem
nenhum erro grave a mais.
