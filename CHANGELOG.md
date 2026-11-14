# Changelog

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
