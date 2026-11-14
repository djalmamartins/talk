# Connect Talk (PHP + MariaDB)

Núcleo do atendimento via WhatsApp que vai rodar **dentro do ERP da Connect**
(Organic Code). PHP 8.2 puro, MariaDB, sem framework obrigatório: as classes
recebem PDO e são ligadas às rotas MovesCode na integração.

Decisões de arquitetura: [docs/decisions/0002-connect-talk-module.md](docs/decisions/0002-connect-talk-module.md)

## O que já faz (Fase 1)
- **Dois tipos de canal atrás de uma interface:** QR Code (Evolution API) e oficial
  (WhatsApp Cloud API, com modelos para envio em massa). Credenciais cifradas (libsodium).
- **Toda conversa vira chamado com protocolo** `AAAAMMDD-NNNNN`.
- **Menu de setores** na primeira mensagem (1 Financeiro, 2 Administrativo, 3 Manutenção)
  ou direcionamento automático por palavra-chave ("boleto" → Financeiro).
- **Identificação pelo telefone** no cadastro do ERP (com/sem DDI e 9º dígito).
- **Permissões:** atendente vê a fila do setor e as próprias conversas; gerente vê o setor;
  supervisor vê tudo. Um setor não vê o outro.
- Aceitar, responder (com assinatura), **transferir com nota interna**, observações, encerrar.
- **Busca** "o que chegou / o que saiu" (texto, direção, período, setor, atendente, contato/protocolo).
- **Tempo real por SSE** (`SseStream`) com filtro de permissão.
- **Fila de envio** com retentativa (`OutboxWorker`, `bin/talk-worker`), idempotência de webhooks,
  status de entrega (enviado/entregue/lido) que nunca regride.

## Rodando os testes
```bash
composer install
docker run -d --name talkdb -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=talk_test -p 127.0.0.1:3307:3306 mariadb:11.4
vendor/bin/phpunit
```

## Ligando no ERP (rotas sugeridas)
| Rota | Classe |
|---|---|
| `POST /talk/webhook/{channel}` | `WebhookHandler::handle()` (sem login, valida assinatura) |
| `GET /talk/webhook/{channel}` | `CloudApiGateway::verifySubscription()` (verificação da Meta) |
| `GET /talk/events` | `SseStream::stream()` — chamar `session_write_close()` antes |
| `GET /talk/conversations?tab=` | `InboxQuery::list()` |
| `GET /talk/conversations/{id}/messages` | `InboxQuery::messages()` |
| `GET /talk/search` | `InboxQuery::search()` |
| `POST /talk/conversations/{id}/accept\|reply\|transfer\|close\|notes` | `ConversationService` |

Todas (menos webhook) exigem sessão do ERP + CSRF nos POST. O usuário vira
`StaffMember` via `StaffRepository::find($userId)`.

## Estrutura
```
migrations/   SQL das tabelas talk_*
src/Gateway   WhatsAppGateway, CloudApiGateway, EvolutionGateway, GatewayFactory
src/Service   ConversationService (regras), AccessPolicy, InboxQuery, OutboxWorker, WebhookHandler, Texts
src/Realtime  EventBus, SseStream
src/Erp       ContactDirectory/Helpdesk do ERP (Null* até ligarmos; SqlContactDirectory configurável)
tests/        Unit + Integration (MariaDB real)
```
