# Changelog

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
