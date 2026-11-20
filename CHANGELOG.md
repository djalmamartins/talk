# Changelog

## [0.26.0] — 2026-11-16
### Alterado
- **Saiu a aba "Fila" de Atendimentos** (pedido do Djalma). Com o setor Geral ela ficou repetida: a
  tela abre em "Meus", e quem espera atendimento aparece em **Fila de atendimento** e no sino. O
  contador no menu lateral passa a vir do sino. Links antigos `/?aba=pending` levam para `/fila`.

### Adicionado
- **Aviso de transferência no sino.** Quando alguém transfere uma conversa para você ou para a fila
  de um setor seu, aparece "Conversa transferida para você/para o setor" com o nome do cliente, o
  protocolo e quem transferiu, e toca o alerta. O aviso some quando alguém do setor aceita, ou quando
  quem recebeu abre a conversa. Migration `022_transfer_alert.sql`.

## [0.25.0] — 2026-11-16
### Adicionado
- **Jack cadastrada como atendente** (JV1.8). "Jaqueline (Jack)" aparece em Equipe e setores, no setor
  Geral, como atendente automática "sempre ligada". As mensagens dela saem com o nome dela na
  conversa. Ela não faz login, não recebe transferência, não aparece entre os atendentes online e não
  é editada nem desativada pela tela (liga e desliga com `JACK_MODE`). Migration
  `021_jack_atendente.sql`.

## [0.24.0] — 2026-11-16
### Adicionado
- **Setor Geral.** Toda mensagem nova cai na fila do Geral, à vista de quem atende o Geral e do
  supervisor. Depois de 1 minuto (`JACK_GENERAL_SECONDS`), com a pessoa sem escrever, a Jack lê tudo e
  distribui calada para Financeiro, Administrativo ou Manutenção. Se alguém aceitar ou transferir no
  Geral antes, a Jack não mexe mais na conversa e o chamado abre nesse momento. A espera conta desde a
  chegada. Número dedicado a um setor não passa pelo Geral. A migration `020_jack_geral.sql` cria o
  setor em quem já tem setores; o `bin/setup.php` cria na primeira instalação.

### Planejado
- Jack cadastrada como atendente (JV1.8) e boleto pelo WhatsApp com conversa e aviso do aplicativo
  (JV1.9), no `ROADMAP.md` e na ADR 0004.

## [0.23.0] — 2026-11-16
### Adicionado
- **Acertos da Jack no Painel** (Jack V1, etapa JV1.1). Supervisor e gerentes veem a taxa de acerto
  da Jack no período, por setor sugerido, as palavras que mais levaram a erro e os 20 últimos erros
  (protocolo, para onde ela mandou e onde a conversa ficou), com link para a conversa. O gerente vê
  só o que passou pelos setores dele. O texto do cliente não aparece no painel.

### Documentação
- `docs/jack/MANIFESTO.md`: manifesto e especificação da Jack (V1 e Completa), confrontado com o
  código da 0.21.0, com a tabela do que está feito, planejado e faltando. Plano da Jack V1 em etapas
  no `ROADMAP.md`.
## [0.22.0] — 2026-11-16
### Adicionado
- **Status do atendimento na conversa**: seletor no topo com Na fila, Em atendimento, Aguardando
  cliente, Aguardando retorno interno e Encerrado. "Na fila" devolve para a fila do setor, sem
  atendente; "Encerrado" abre a tela de encerrar de sempre. Num atendimento encerrado, o seletor
  oferece "Reabrir", bloqueado se o contato já tem outro atendimento aberto.
- "Aguardando cliente" sai sozinho quando o cliente responde; "Aguardando retorno interno" fica até
  o atendente mudar. Os dois aparecem como etiqueta laranja na lista de conversas.
- Cada mudança fica no histórico como nota interna (🔁 "Status alterado para…").
- Migração 019: coluna `stage` em `talk_conversations` (só acrescenta).
### Observação
- O status não é enviado ao Help Desk do ERP; reabrir no Talk não reabre o chamado de lá (a API do
  ERP ainda não tem essa ação).

## [0.21.2] — 2026-11-16
### Corrigido
- O aviso do sino ("A mais antiga espera há…") também deixa de contar o tempo com o setor fechado
  e os feriados, como o Painel e a Fila (faltou na 0.21.0).

## [0.21.1] — 2026-11-16
### Alterado
- **Protocolo SAC + AAMMDD + 5 dígitos** (ex.: `SAC26111600004`). A migração 018 passa os
  protocolos já emitidos para esse formato (os de 0.20 e os antigos `AAAAMMDD-NNNNN`); o número
  antigo fica guardado em `legacy_protocol` e continua achando a conversa na busca. Não apaga nada.
- **Protocolos da pessoa em uma linha por item**: número, data e situação; o atual com fundo verde e
  uma barra à esquerda (sem o "este"). O assunto aparece ao passar o mouse.
### Corrigido
- Busca por número de protocolo (novo, antigo ou chamado CH…) acha a conversa pelo número, uma
  linha por conversa. Antes dependia de o número aparecer no texto de alguma mensagem.
- O navegador não fica mais com o CSS/JS da versão anterior depois de atualizar: os arquivos de
  tela levam a versão no endereço.

## [0.21.0] — 2026-11-16
### Alterado
- **Tempo de espera só conta com o setor atendendo.** Fora do horário do setor e nos feriados a
  espera não corre: vale no Painel (espera média e planilha), na Fila de atendimento e nos alertas
  de espera. Na Fila, quem chegou com o setor fechado mostra "⏸ fora do horário" e o relógio fica
  parado até o setor abrir. Com o controle de horário desligado, nada muda.
- **Jack ainda mais rápida:** sem assunto claro espera 5 s (era 15 s); só um "bom dia", 30 s (era
  2 min) e vai para o Administrativo. Assunto claro continua indo na hora.

## [0.20.1] — 2026-11-16
### Corrigido
- **Mensagem demorava a aparecer na fila.** A Jack esperava 60 s sem mensagem nova antes de
  encaminhar. Agora, quando o assunto já está claro ("estamos sem água no bloco B"), a conversa
  entra na fila do setor na hora. Sem assunto claro, a espera caiu para 15 s; só um "bom dia",
  para 2 min (era 10). Padrões em `JACK_WAIT_SECONDS` e `JACK_MAX_WAIT_SECONDS`.
- No laboratório, a tela aberta faz a triagem e os envios a cada ~2 s (antes dependia da próxima
  ação na tela, até ~25 s).

## [0.20.0] — 2026-11-16
### Alterado
- **Protocolo no formato SAC**: `SAC` + data + sequência do dia, sem hífen (ex.: `SAC202611160003`).
  É o número interno do Talk, para acompanhar e passar para outra pessoa; vai junto
  (`talk_protocol`) quando o chamado é aberto no Help Desk do ERP, para saber de onde veio.
  Os protocolos antigos (`AAAAMMDD-NNNNN`) continuam valendo e aparecem na busca.
- **Protocolos da pessoa na lateral**: os atendimentos do contato, do protocolo mais novo para o
  mais antigo, 5 por página, com o atual marcado ("este") e o total. Quem não vê outro setor
  enxerga só os seus, com o aviso "+N em outros setores".

## [0.19.0] — 2026-11-15
### Adicionado
- **Som de alerta** quando chega mensagem de cliente ou conversa nova na fila: um "plim" curto,
  gerado pelo navegador (sem arquivo), com o sino balançando. Toca em qualquer tela: na de
  atendimentos na hora, nas outras quando a fila ou as respostas aumentam. No máximo um a cada
  3 s. Liga/desliga no menu do usuário ("Som de alerta"), guardado no navegador. O navegador só
  libera som depois do primeiro clique na página.
- **Botão "Abrir chamado"** na conversa (quando ainda não há chamado): assunto e descrição já vêm
  preenchidos com o que o cliente escreveu; abre no Help Desk do ERP na área do setor, com nome,
  telefone, condomínio e unidade do cadastro do ERP, e deixa uma nota interna com o número.
  Sem o Help Desk ligado (`ERP_API_URL`), explica o que falta.
### Alterado
- O contador do sino deixa de ser uma bolinha vermelha por cima do ícone: o sino vira um botão
  com o número ao lado, em tom suave.
- Os botões do topo da conversa quebram de linha inteiros, sem partir o texto.
### Corrigido
- A abertura automática do chamado passa a mandar condomínio e unidade do cadastro do ERP
  (antes ia só o nome e o telefone).

## [0.18.1] — 2026-11-15
### Adicionado
- `tools/local/whatsapp-check.sh`: confere, só lendo, por que o WhatsApp por QR Code não recebe
  ou não envia no laboratório: containers, migrações, `.env`, se o motor alcança o Talk (endereço
  do dev-apache, que muda quando o Docker reinicia), números conectados, webhook de cada número,
  últimos envios com o erro e erros do Talk no Apache. Não mostra chaves.

## [0.18.0] — 2026-11-15
Correções do primeiro teste real com WhatsApp por QR Code.
### Corrigido
- **Número do cliente chegava errado.** O WhatsApp passou a mandar um código interno (LID,
  terminado em `@lid`) no lugar do telefone; o Talk agora usa o telefone de verdade que vem junto
  (`remoteJidAlt`/`senderPn`).
- **A Jack falava com o cliente ao encaminhar.** Como combinado (ADR 0004), ela lê, entende e
  coloca a conversa na fila do setor certo **sem mandar mensagem**. Só um "bom dia" sozinho não
  gera resposta: ela espera o assunto. Fora do horário, o aviso configurado continua indo.
- **"Falta de água" ia para o Administrativo.** A Jack reconhece falta de água/luz/energia,
  cano, torneira, descarga, ralo e pressão da água como Manutenção.
- **`*` e `_` aparecendo nas mensagens.** A conversa mostra a formatação do WhatsApp como o
  celular mostra (*negrito*, _itálico_, ~riscado~, ```monoespaçado```); a lista de conversas
  mostra o texto limpo.
### Adicionado
- **Número do chamado do Help Desk.** Quando o ERP devolve o número do chamado (ex.:
  `CH2611153FA2`), ele fica guardado na conversa (`helpdesk_protocol`, migração 017) e é o que o
  cliente vê no aviso de fora do horário e no encerramento, no topo da conversa e na busca. Sem o
  Help Desk ligado continua valendo o protocolo do Talk.
- **Emojis no campo de resposta**: botão ao lado do clipe, com os mais usados, rostos, gestos,
  condomínio e atendimento; dá para escolher vários seguidos e Esc fecha.
- **Ícone do Connect Talk** na aba do navegador, nos favoritos e na tela inicial do celular
  (favicon, PNGs, apple-touch-icon e manifest).

## [0.17.2] — 2026-11-15
### Corrigido
- **Número de QR Code antigo tentava um endereço que não existe** ("Could not resolve host:
  evolution.lab"). Com o motor do WhatsApp configurado no servidor, todos os números de QR Code
  passam a usar ele, mesmo os cadastrados antes com endereço próprio. Os dados de teste do
  laboratório também deixam de trazer esse endereço.

## [0.17.1] — 2026-11-15
### Adicionado
- **QR Code funcionando como no Atendechat**, sem nenhum dado técnico na tela: o motor do WhatsApp
  (Evolution API, que usa o Baileys, a mesma biblioteca do Atendechat) passa a vir junto com o Talk.
  - Produção: serviços `whatsapp` e `whatsapp-db` no `docker-compose`; basta a `EVOLUTION_API_KEY` no `.env`.
  - Laboratório: `tools/local/whatsapp-lab.sh` sobe os dois containers ao lado do dev-apache, gera
    a chave e completa o `.env`. Não apaga nada; rodar de novo só confere.
  - O motor avisa o Talk das mensagens pela rede interna (`EVOLUTION_WEBHOOK_URL`).
- A janela do QR Code abre na hora, com "Gerando o QR Code…", espera o WhatsApp mandar o código
  (até ~10 s) e troca a imagem sozinha a cada ~20 s, como no WhatsApp Web.

## [0.17.0] — 2026-11-15
### Adicionado
- **Topo em todas as telas**, no padrão do Connect ERP:
  - busca de conversas (nome, telefone, protocolo ou mensagem) no alto, com atalho Ctrl K; de
    qualquer tela, a busca abre os resultados em Atendimentos, com "Limpar busca";
  - sino de notificações: conversas esperando na fila dos seus setores, clientes seus que
    responderam, agendamentos que falharam (7 dias) e, para o supervisor, números de WhatsApp
    desconectados. Cada aviso leva à tela certa e some quando o motivo some;
  - menu do usuário à direita: nome, e-mail, papel, "Online", Trocar senha e Sair.
- **Seletor de sistemas** no lugar do antigo bloco de perfil do menu: "Connect Talk" abre um
  pop-up com Connect ERP e Connect Talk (endereço do ERP em `ERP_WEB_URL`).
- **Etiquetas com cor livre:** além das 8 cores sugeridas, seletor de cor e campo com o código
  (#RRGGBB), com prévia da etiqueta.
### Alterado
- Abas de Atendimentos em formato segmentado, sem quebrar "Em atendimento" em duas linhas.
- Abas por setor da Fila com a cor do setor e o número em destaque, em vez de "Financeiro (0)".
- Texto das etiquetas um pouco mais escuro que a cor, para ler bem mesmo em cores claras.
### Removido
- Simulador de cliente da tela de Atendimentos (o painel da direita agora só mostra o cliente).
  O endereço `/lab/simulate` continua no laboratório para os testes automáticos.

## [0.16.2] — 2026-11-15
### Alterado
- **Cadastro de número por QR Code só com nome e número.** A tela não pede mais endereço da
  Evolution, nome da instância nem chave da API:
  - o servidor da Evolution é configurado uma vez no `.env` (`EVOLUTION_URL` e `EVOLUTION_API_KEY`);
  - o Talk cria na Evolution a instância `talk-<número>` e já mostra o QR Code ao salvar;
  - o número aceita formato livre, como `(31) 99999-0000`, e não pode repetir;
  - o setor do número aparece ao configurar depois; o uso do QR Code é sempre atendimento.
  - Números já cadastrados com endereço e chave próprios continuam funcionando.
- **Laboratório:** sem Evolution configurada, o QR Code é simulado e aparece o botão "Simular leitura".

## [0.16.1] — 2026-11-15
### Corrigido
- **Sessão expirada aparecia como erro 500.** O Apache não conhece o código 419 e trocava por 500,
  então a tela não mostrava "recarregue a página". Agora responde 403 com essa mensagem.
- **Prévia da mensagem fora do horário com o controle desligado.** A variável `{volta}` mostrava a
  hora atual; agora mostra a próxima abertura do horário configurado (ex.: "segunda-feira às 8h").

## [0.16.0] — 2026-11-14
### Adicionado
- **Pacote de produção** em `deploy/` (guia: `docs/producao.md`):
  - `Dockerfile`: PHP 8.2 + Apache, opcache, sem pacotes extras do sistema.
  - `docker-compose.yml` com cinco serviços:
    - `caddy`: HTTPS automático, sem compressão nem buffer no tempo real;
    - `app`: aplica as migrations sozinho ao subir;
    - `worker`;
    - `db`: MariaDB 11.4, sem porta exposta;
    - `backup`.
  - O projeto se chama `talk-php`, para não misturar com o Talk antigo em Node.
- **Worker como serviço:**
  - dá sinal de vida a cada 15 s;
  - `php bin/talk-worker --check` serve de verificação de saúde do Docker;
  - encerra de forma limpa com SIGTERM;
  - se reinicia sozinho a cada hora (`WORKER_MAX_MINUTES`) ou depois de 20 erros seguidos;
  - na primeira subida, espera as tabelas.
- **`/health`** (sem login): banco respondendo e há quantos segundos o worker deu sinal.
- **Backup diário:**
  - banco com `mariadb-dump --single-transaction` e arquivos das conversas, com `SHA256SUMS`;
  - só vira cópia quando termina inteira;
  - apaga as mais velhas que `BACKUP_KEEP_DAYS`.
- **`deploy/restore.sh`:** confere a cópia, pede confirmação e restaura banco e arquivos.
- **`bin/setup.php`:** primeira instalação. Cria os setores da Jack e o primeiro supervisor com
  senha gerada (mostrada uma vez).
- **`TRUST_PROXY=1`:** atrás do Caddy, o site reconhece o HTTPS (cookie `Secure`) e o IP real do
  cliente (limite de tentativas de login).

### Testado
- Subida do zero com o docker-compose (todos os serviços saudáveis).
- `setup`, login por HTTPS com cookie `Secure`.
- Tempo real "Online" passando pelo Caddy.
- IP forjado em `X-Forwarded-For` ignorado.
- Worker reiniciado pelo Docker depois de parar.
- Backup e restauração: dados e arquivos voltaram como no momento da cópia.

## [0.15.0] — 2026-11-14
### Adicionado
- Tela **Horário de atendimento** (`/configuracoes`, só supervisor):
  - **Agora:** cada setor aberto ou fechado, e quando volta.
  - **Horário geral:** até 3 faixas por dia (ex.: 8h às 12h e 13h às 18h), botão para copiar a
    segunda para os dias úteis.
  - **Setores:** segue o horário geral, horário próprio ou atende 24 horas (ex.: plantão da
    Manutenção).
  - **Feriados:** cadastro avulso e "incluir feriados nacionais" do ano (fixos, Carnaval, Sexta-feira
    Santa e Corpus Christi calculados pela Páscoa). No feriado só os setores de 24 h atendem.
  - **Mensagem fora do horário** com variáveis `{nome}`, `{protocolo}`, `{setor}`, `{horario}` e
    `{volta}` e prévia de como o cliente recebe.
- Fora do horário, quando a Jack encaminha, o cliente recebe essa mensagem no lugar do "já vamos te
  atender" (ex.: "A equipe responde segunda-feira às 8h"). Vai uma vez por atendimento; a conversa
  fica na fila do setor normalmente. Vale também para número fixo de setor e resposta a envio em
  massa.
- Fica **desligado** até o supervisor ligar; alterações e feriados vão para o registro de auditoria.
- Migration `016_hours.sql`.

### Mudou
- ROADMAP: S8 dividido em S8a (horário, feito) e S8b (produção).

## [0.14.0] — 2026-11-14
### Adicionado
- **Avaliação pós-atendimento:** ao encerrar um atendimento que teve atendente, o cliente recebe
  "Como você avalia o atendimento de *Ana*? Responda com um número de 1 (ruim) a 5 (ótimo)".
  Aceita "5", "4 - foi rápido", "4/5", estrelas (⭐⭐⭐⭐) e palavras (ótimo, bom, ruim...). A resposta
  fica na própria conversa encerrada (marcada "⭐ Avaliação") e o cliente recebe um obrigado. Vale
  por 24 h; uma mensagem que não é nota ("2 via do boleto") abre atendimento novo como sempre.
  Desliga com `TALK_RATING=off`.
- Tela **Painel** (`/painel`, no menu de todos): período (hoje, 7 dias, 30 dias, este mês ou datas
  livres), filtro por setor e atendente; atendimentos, % encerrados, espera média (da fila até
  aceitar), atendimento médio (de aceitar até encerrar) e nota dos clientes; gráficos por dia e por
  hora do dia; tabelas por setor e por atendente; distribuição das notas e últimos comentários.
  Supervisor vê tudo; gerente, os setores que gerencia; atendente, só os próprios atendimentos.
- **Exportar planilha:** CSV (abre direto no Excel) com um atendimento por linha: protocolo,
  datas, contato, setor, atendente, espera, duração, nota e comentário.
- Migration `015_ratings.sql`.

## [0.13.0] — 2026-11-14
### Adicionado
- Tela **Envio em massa** (`/envios`, para supervisor e gerentes de setor), com duas abas:
  - **Envios:** cada envio com situação (rascunho, agendado, enviando, pausado, concluído,
    cancelado), barra de progresso e números de pessoas, na fila, enviadas, entregues, lidas,
    responderam, falhas e fora; clicar num número abre a lista de destinatários filtrada.
  - **Modelos da Meta:** modelos aprovados por número oficial, com botão para sincronizar com a
    Meta (supervisor) ou cadastrar à mão.
- Novo envio: número oficial, setor que recebe as respostas, modelo, de onde vem cada variável
  (primeiro nome, nome, telefone, coluna da lista ou texto fixo), público (contatos com uma
  etiqueta ou lista colada do Excel), quando (agora ou dia e hora) e ritmo por minuto. Prévia ao
  vivo com quantos recebem, quem fica de fora e por quê (telefone inválido, repetido, bloqueado,
  pediu para sair, variável vazia) e como a mensagem chega para os três primeiros.
- O worker envia no ritmo escolhido; pausar, retomar e cancelar a qualquer momento; falha da Meta
  fica registrada no destinatário; confirmações de entrega e leitura atualizam os números.
- Resposta de quem recebeu o envio (até 3 dias) vai direto para o setor da campanha, com nota
  interna mostrando a mensagem enviada. "SAIR", "PARAR", "STOP"... tiram o contato dos próximos
  envios em massa (LGPD).
- Migration `014_campaigns.sql`.

## [0.12.0] — 2026-11-14
### Adicionado
- Tela **Agendamentos** (`/agendamentos`, no menu de todos): mensagens marcadas para um dia e
  hora, agrupadas por dia (Hoje, Amanhã, …), com abas Próximos, Enviados, Cancelados e Com falha.
- Novo agendamento: busca o contato (nome, telefone, condomínio) ou aceita um número novo com DDD;
  setor; dia e hora com atalhos (daqui a 1 hora, amanhã às 9h/14h, segunda às 9h); assinar com o
  nome de quem agendou.
- Botão **Agendar** dentro da conversa: já vem com o contato e o setor; fica uma nota interna na
  conversa dizendo o que foi agendado.
- Na hora marcada o worker manda: se o contato tem conversa aberta naquele número, a mensagem
  entra nela; senão abre uma conversa nova (protocolo e chamado) com quem agendou, ou na fila do
  setor se a pessoa estiver desativada.
- Regras: só pelos números de QR Code (a API oficial só aceita modelos aprovados fora da janela de
  24 h; isso fica no envio em massa); contato bloqueado não recebe (o agendamento é cancelado);
  número desativado marca falha. Cada um vê os agendamentos dos seus setores; editar e cancelar:
  quem agendou, o gerente do setor ou o supervisor.
- Migration `013_schedules.sql`.

### Mudou
- ROADMAP: S6 dividido em S6a (agendamentos, feito) e S6b (envio em massa pela API oficial).

## [0.11.0] — 2026-11-14
### Adicionado
- Tela **Equipe e setores** (`/equipe`, só supervisor), com três abas:
  - **Atendentes:** cadastro com nome, e-mail de login, senha inicial, papel (atendente, gerente ou
    supervisor), setores e, opcionalmente, o usuário correspondente no ERP. Busca, filtro por setor
    e por situação; quem está disponível, atendendo ou offline; atendimentos em andamento e
    encerrados hoje.
  - **Setores:** criar, renomear, cor e descrição; pessoas, fila, atendimentos e números de cada
    setor. Os três setores da Jack podem ser renomeados (ela continua reconhecendo) mas não
    desativados. Setor com atendimento aberto não desativa; ao desativar, números dedicados a ele
    voltam para a Jack.
  - **Registro de alterações:** quem cadastrou, alterou, desativou ou reativou o quê (sem senhas).
- **Desativar atendente:** a pessoa não entra mais, a sessão aberta cai na hora e os atendimentos
  dela voltam para a fila do setor, com nota interna. Sempre fica pelo menos um supervisor ativo e
  ninguém desativa ou rebaixa a si mesmo.
- **Trocar senha** (menu lateral, para todos): confirma a senha atual; as sessões em outros
  aparelhos caem. Senha: 8 a 72 caracteres, com letras e números. Redefinir pelo supervisor também
  derruba as sessões da pessoa.
- **Limite de tentativas de login:** 5 erros no mesmo e-mail ou 20 no mesmo IP em 15 minutos
  bloqueiam novas tentativas até a janela passar.
- Migration `012_team.sql` (`jack_key`, cor e descrição nos setores; `talk_login_attempts`;
  `talk_audit_log`).

## [0.10.0] — 2026-11-14
### Adicionado
- Tela **Conexão WhatsApp** (`/conexoes`, só supervisor; o item só aparece no menu dele):
  - vários números, cada um com tipo (QR Code pela Evolution API ou API oficial da Meta), uso
    (atendimento, envios em massa ou os dois), telefone e **setor do número**;
  - situação de cada número (conectado, aguardando leitura, desconectado, desativado), com a hora da
    última conferência e o erro, se houver; atendentes do setor do número;
  - **QR Code na tela**: ao conectar um número de QR Code, o Talk configura o webhook na Evolution
    (com o segredo no cabeçalho), mostra o QR Code e confere a cada 3 s até conectar;
  - API oficial: "Verificar" consulta o número na Meta (telefone e qualidade) e a tela mostra o
    endereço do webhook e o token de verificação para colar no Meta for Developers;
  - número **padrão**, ativar/desativar (mensagens novas de um número desativado são ignoradas),
    desconectar o celular, excluir (só número sem histórico).
- Número com setor: quem escreve nele vai direto para a fila desse setor, sem a Jack escolher.
  Número sem setor continua com a triagem da Jack.
- Evento `connection.update` da Evolution atualiza a situação do número.
- Tokens e chaves continuam cifrados; a tela só recebe os 4 últimos caracteres e senha em branco ao
  editar mantém a atual.
- Laboratório: QR Code de mentira com "Simular leitura"; números de exemplo (nenhum real); o
  simulador aceita o número de destino (`channel_id`).
- Migration `011_channels.sql`.

### Pendente
- Testar com uma Evolution e um número da Meta de verdade (aguarda aprovação para usar número real).

## [0.9.0] — 2026-11-14
### Adicionado
- Tela **Contatos** (`/contatos`): lista com busca por nome, telefone (com ou sem máscara),
  condomínio ou unidade; filtros por etiqueta e por situação (identificados no ERP, não
  identificados, bloqueados); 25 por página; número de atendimentos e último contato.
- Detalhe do contato: dados do ERP, etiquetas, atendimentos (só os que o usuário pode ver, com link
  para abrir a conversa) e notas. Editar o nome.
- **Bloquear contato** (supervisor e gerentes, com motivo): as mensagens da pessoa deixam de entrar
  (ficam só contadas) e os atendimentos abertos são encerrados sem aviso a ela. Desbloquear volta ao
  normal.
- Escopo (LGPD): supervisor vê todos os contatos; os demais, só quem já falou com os seus setores.
- Migration `010_contact_block.sql`.

## [0.8.0] — 2026-11-14
### Adicionado
- Tela **Fila de atendimento** (`/fila`, no menu lateral), no padrão da tela de referência:
  - em espera, tempo médio de espera (de quem está na fila agora), atendimentos de hoje e taxa de
    resolução (encerradas das conversas de hoje);
  - tabela por ordem de chegada com posição, contato, etiquetas, setor, protocolo e assunto, tempo de
    espera correndo ao vivo (amarelo a partir de 15 min, vermelho a partir de 30; `QUEUE_WARN_MINUTES`
    e `QUEUE_LATE_MINUTES`), prioridade e "Atender" (aceita e abre a conversa);
  - abas por setor, busca, ordenação por mais antigo, prioridade ou mais recente; menu ⋮ para mudar a
    prioridade (alta, normal, baixa);
  - distribuição da fila por setor (rosca), atendentes online, atendendo ou offline, e maior, médio e
    menor tempo de espera.
- Cada setor só vê a própria fila e os próprios números; o supervisor vê tudo.
- Presença: o atendente com a tela aberta aparece online (conexão de tempo real do último minuto).
- A espera conta a partir da entrada no setor: transferir para outro setor recomeça a contagem.
- Migration `009_queue.sql` (`queued_at`, `accepted_at`, `priority`, `last_seen_at`).
- Links `/?c=ID` abrem a conversa direto (usado pela fila e pelo chamado no ERP).

## [0.7.0] — 2026-11-14
### Adicionado
- Etiquetas (ex.: Síndico, Inadimplente, 2ª via, Obra), com cor. Ficam no **contato**: seguem a pessoa
  em todas as conversas, aparecem na lista e têm filtro próprio. Supervisor e gerentes cadastram (menu
  "Etiquetas"); quem vê a conversa aplica ou tira pelo "+" do painel. Até 10 por contato.
- Painel do contato no padrão da tela de referência: iniciais, vínculo no ERP, telefone, condomínio,
  unidade, e-mail, protocolo, etiquetas, **últimos chamados** da pessoa (só os que o setor pode ver,
  clicáveis; avisa quantos há em outros setores) e **notas do contato** (valem para todas as conversas;
  só o autor, o supervisor ou um gerente apagam).
- API: `GET/POST /api/tags`, `POST /api/tags/{id}` e `/{id}/delete`, `GET /api/conversations/{id}/panel`,
  `POST /api/conversations/{id}/tags`, `POST /api/conversations/{id}/contactnotes` e
  `/contact-notes/{nota}/delete`; `tag` no filtro de `/api/conversations`.
- Migration `008_tags_contact_notes.sql`; seed do laboratório com etiquetas e uma moradora fictícia
  "identificada no ERP".

## [0.6.0] — 2026-11-14
### Adicionado
- Arquivos nas conversas. O atendente envia imagem, áudio, vídeo, PDF, Word, Excel ou texto pelo clipe,
  colando um print no campo ou arrastando para a conversa (até 16 MB; a legenda sai assinada; áudio
  vai sem legenda). O que o cliente manda é baixado pelo worker (Evolution `getBase64FromMediaMessage`;
  API oficial pelo id da mídia), com 3 tentativas antes de ficar "indisponível".
- Na conversa: foto em miniatura, player de áudio e vídeo, cartão do documento com nome e tamanho.
  Na lista, a última mensagem aparece como "📷 Foto", "🎤 Áudio" etc.
- `/media/{id}`: o arquivo só abre para quem vê a conversa (mesma regra de setor e de histórico
  oculto). Fica fora da pasta pública (`TALK_STORAGE`, padrão `./storage`); o tipo é conferido pelo
  conteúdo, não pelo nome. Migration `007_media.sql`.
- Simulador do laboratório: o cliente também manda arquivo (clipe).

### Corrigido
- Atualização em tempo real apagava o que o atendente estava digitando; agora o rascunho fica.
- Paginação das mensagens ignorava o "histórico oculto" da transferência sem manter histórico.
- Triagem da Jaqueline passa a ler também a legenda de foto e vídeo (ex.: foto "água vazando").

### Atenção na instalação
- O PHP precisa aceitar envio de 17 MB (`public/.htaccess` e `public/.user.ini` já trazem isso).
- Incluir a pasta `storage/` no backup.

## [0.5.1] — 2026-11-14
### Adicionado
- Mensagens rápidas: no campo de resposta, `/` abre a lista (filtra pelo atalho e pelo texto; setas,
  Enter/Tab inserem, Esc fecha). Campos trocados na hora: `{primeiro_nome}`, `{nome}`, `{protocolo}`,
  `{setor}`, `{atendente}`. Cadastro no menu lateral: supervisor cria gerais e de qualquer setor;
  gerente cria só do seu setor; atendente só usa as gerais e as do seu setor.
  API `GET/POST /api/quick-messages`, `POST /api/quick-messages/{id}` e `/{id}/delete`.
  Migration `006_quick_messages.sql`; exemplos no seed do laboratório.

## [0.5.0] — 2026-11-14
### Adicionado
- `ROADMAP.md` até a v1.0 (sistema completo primeiro, Jack depois) e `docs/porte-atendechat.md`.
- Trocar atendente no padrão da tela de referência: para um setor ou direto para um atendente do
  setor, motivo de até 500 caracteres com contador e opção "manter histórico visível" (sem ela, o
  novo atendente vê a conversa a partir da transferência; gerente e supervisor veem tudo).
  Migration `005_transfer_history.sql`.

## [0.4.1] — 2026-11-14
### Alterado
- Mensagens assinadas como *Jack:* (pedido do Djalma); na apresentação ela continua dizendo que se chama Jaqueline.

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
