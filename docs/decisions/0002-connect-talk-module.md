# 0002 — Módulo Connect Talk no Organic Code

Status: **aceita** (2026-11-14) — decisões confirmadas pelo Djalma na seção "Decisões tomadas"

## Contexto
O Connect Talk (atendimento via WhatsApp) foi prototipado a partir do Codatendechat
(Node/React/Postgres) e está funcionando em Docker como referência
(`connect-talk` v0.1.0). A decisão agora é construir o Talk **dentro do Organic Code**,
na mesma base do ERP: **PHP 8.2 + MySQL/MariaDB + JS**, reaproveitando MovesCode
(Router, Model, Middleware, View), usuários, permissões e clientes do próprio ERP.

O protótipo Node serve como **especificação viva**: o comportamento a reproduzir
é o dele (fluxo de tickets, filas, tempo real, busca).

## Decisão proposta

### 1. Novo ambiente `talk`
Seguindo o contrato de ambientes isolados por hostname:

| Item | Onde |
|---|---|
| Host | `talk.organiccode.lab` (dev) · `talk.connect.com.br` (produção) |
| Tema | `container/talk/{theme}/{version}/` com `default.php` e `routes.php` |
| Controllers | `source/App/Talk/` |
| Serviços | `source/Services/Talk/` (TicketService, MessageService, Gateway/*, RealtimeService) |
| Models | `source/Models/Talk/` |
| Assets JS/CSS | `container/talk/{theme}/{version}/assets/` → publicados via MovesCode Compress |

Login, sessão, 2FA, papéis e organizações são os do ERP (sem cadastro próprio).

### 2. Banco de dados (prefixo `talk_`)
| Tabela | Função |
|---|---|
| `talk_channels` | números de WhatsApp conectados (provider, credenciais cifradas, status) |
| `talk_contacts` | contato WhatsApp (telefone E.164, nome, foto) + `client_id` do ERP quando identificado |
| `talk_queues`, `talk_queue_users` | filas/setores e quem atende cada uma |
| `talk_tickets` | atendimento: `status` (pending/open/closed), `queue_id`, `user_id`, `contact_id`, `channel_id`, `last_message_at`, `unread` |
| `talk_messages` | `direction` (in/out), `type` (text/image/audio/document/…), `body`, `media_path`, `provider_message_id` (UNIQUE, idempotência), `ack` (sent/delivered/read/failed) |
| `talk_ticket_events` | histórico: aceito, transferido, resolvido, reaberto (auditoria e relatórios) |
| `talk_tags`, `talk_ticket_tags` | etiquetas |
| `talk_quick_replies` | respostas rápidas |
| `talk_notes` | observações internas do atendimento |
| `talk_outbox` | fila de envio (tentativas, erro) processada por worker |

Índices: `(status, queue_id, last_message_at)` em tickets; `FULLTEXT(body)` e
`(ticket_id, created_at)` em mensagens. Usar só recursos comuns a MySQL 8 e MariaDB 10.6+.

"Isso é um chamado" passa a ser **nativo**: o serviço de chamados do ERP é chamado
direto, gravando `talk_ticket_id` no chamado. Sem API intermediária.

### 3. Conexão com o WhatsApp — interface `WhatsAppGateway`
```php
interface WhatsAppGateway {
    public function sendText(Channel $c, string $to, string $text): SendResult;
    public function sendMedia(Channel $c, string $to, MediaFile $f, ?string $caption): SendResult;
    public function parseWebhook(Request $r): array; // InboundMessage[] | StatusUpdate[]
    public function verifyWebhook(Request $r): bool; // assinatura
}
```
- **CloudApiGateway (recomendado):** API oficial da Meta. Webhook HTTPS com
  verificação `X-Hub-Signature-256`; envio por HTTP. Sem QR Code, sem risco de banimento,
  custo por conversa.
- **EvolutionGateway (alternativa):** container Evolution API (Node/Baileys) ao lado;
  mantém QR Code, mesmo risco do protótipo.

Fluxo de entrada (webhook `POST /talk/webhook/{channel}`):
1. Valida assinatura → 2. ignora `provider_message_id` repetido → 3. acha/cria contato →
4. acha ticket aberto/pendente do contato no canal, senão cria `pending` (fila padrão ou
menu da fila) → 5. grava mensagem (baixa mídia para storage fora de `public/`) →
6. publica evento em tempo real → 7. responde 200 rápido (trabalho pesado vai para o worker).

### 4. Tempo real sem recarregar a página
PHP-FPM/Apache não segura WebSocket bem, então um **servidor de tempo real ao lado**:

- **Recomendado: Centrifugo** (binário único, container oficial). O PHP publica por
  HTTP (`POST /api/publish`); o navegador assina canais com `centrifuge-js` usando
  token JWT emitido pelo PHP (só canais que o usuário pode ver).
- Canais: `talk:user#{id}`, `talk:queue#{id}`, `talk:ticket#{id}`.
- Eventos: `ticket.created|updated|transferred|closed`, `message.created|ack`, `user.typing`.
- **Plano B sem container extra:** Server-Sent Events em PHP (cada aba segura 1 processo).
  Aceitável até ~20 atendentes; serve para o MVP se preferirem não subir nada novo.

### 5. Frontend (JS)
JS modular (ES modules) sobre o layout do ERP, sem SPA pesada:
- Tela de 3 colunas como no protótipo: lista (abas **Aguardando / Em atendimento /
  Resolvidos**, filtro por fila e etiqueta, busca), conversa, painel do contato.
- Busca de mensagens "o que chegou / o que saiu": filtros por direção, período,
  contato, atendente e texto (FULLTEXT).
- Envio otimista (mensagem aparece na hora, ack atualiza o ícone depois).
- Notificação sonora e badge de não lidas por fila.

### 6. Processos em segundo plano
`php bin/talk-worker` (supervisionado pelo Docker, `restart: unless-stopped`):
envio da `talk_outbox` com retentativa, download de mídia, fechamento automático
por inatividade, agendamentos. Sem Redis no MVP (fila em tabela com `SELECT … FOR UPDATE SKIP LOCKED`).

## Fases
| Fase | Entrega | Estimativa (1 dev) |
|---|---|---|
| F0 | Decisões abaixo, conta Meta/Business verificada, ambiente `talk` no Organic Code | 2–3 dias |
| F1 | Migrations, webhook de entrada, envio de texto, worker, testes | 1–2 semanas |
| F2 | Caixa de entrada em tempo real: aceitar, transferir, resolver, reabrir, busca | 2 semanas |
| F3 | Mídia (imagem/áudio/doc), respostas rápidas, etiquetas, observações, **chamado nativo** | 1–2 semanas |
| F4 | Menu/chatbot por fila, horário de atendimento, relatórios básicos | 1–2 semanas |
| F5 (se precisar) | Campanhas, Kanban, agendamento de mensagens | a definir |

MVP em produção ao fim da F3 (≈ 5–7 semanas). Até lá, o protótipo Node pode atender.

## Testes esperados
- Unitários: parse/verificação de webhook (payloads reais da Meta), regras de criação
  de ticket, idempotência por `provider_message_id`, permissões por fila.
- Integração: migration em banco descartável (instalação limpa + rollback),
  webhook → mensagem → evento publicado; envio com falha → retentativa.
- E2E (Playwright): dois atendentes, mensagem chega e aparece nos dois sem recarregar;
  aceitar some da aba Aguardando do outro.

## Segurança
CSRF em formulários, SQL parametrizado, assinatura do webhook obrigatória, credenciais
do canal cifradas, mídia servida por controller com checagem de permissão, rate limit
no webhook e no envio, tokens de tempo real com expiração curta e canais restritos.

## Decisões tomadas (2026-11-14)
1. **Gateway híbrido:** canal QR Code (Evolution API) para o atendimento do dia a dia e
   canal oficial (Cloud API da Meta) para envios em massa (boletos, avisos) com modelos
   aprovados. São **dois números** (a Meta não permite o mesmo número nos dois).
   `GatewayFactory::bulkChannelId()` escolhe o oficial para massa.
2. **Tempo real: SSE** em PHP no MVP (3 atendentes + supervisor), atrás de `EventBus`;
   troca para Centrifugo sem mexer nas telas se o time crescer.
3. **Banco: MariaDB** (o mesmo do ERP). O SQL também roda em MySQL 8.
4. **Mesmo servidor do ERP**, lendo os cadastros para identificar quem escreveu pelo telefone.
5. **Toda conversa é um chamado com protocolo** (`AAAAMMDD-NNNNN`), ligado ao helpdesk do ERP.
6. **Setores iniciais:** Financeiro, Administrativo, Manutenção. Menu na 1ª mensagem ou
   palavra-chave; isolamento entre setores; transferência com nota interna; gerente por
   setor; supervisor master vê tudo.

## Pendente
- Estrutura (sem dados) das tabelas do ERP: pessoas/telefones, condomínios/unidades, helpdesk.
- Imagens de referência das telas.
