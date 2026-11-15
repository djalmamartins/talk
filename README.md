# Connect Talk (PHP + MariaDB)

Atendimento via WhatsApp da Connect. Aplicação própria (PHP 8.2 puro + MariaDB + JS,
sem framework), hospedada **separada do ERP** e integrada a ele por **API REST**.

- Decisões: [0002 módulo Talk](docs/decisions/0002-connect-talk-module.md) ·
  [0003 hospedagem separada + API REST](docs/decisions/0003-hospedagem-separada-api-rest.md)
- **API que o ERP precisa expor:** [docs/api-erp.md](docs/api-erp.md)

## Produção
Docker no PC do escritório, com HTTPS automático, worker, backup diário e restauração:
**[docs/producao.md](docs/producao.md)**.

## Laboratório local (https://talk.connect.lab)
No Mac com o XAMPP compartilhado (`dev-apache` + `dev-mysql`):
```bash
cd "/Volumes/MAC HD/Docker/xampp/www"
git clone https://github.com/djalmamartins/talk talk
cd talk && tools/local/install-lab.sh
tools/local/whatsapp-lab.sh        # WhatsApp por QR Code de verdade (motor ao lado do dev-apache)
tools/local/whatsapp-check.sh       # não recebe ou não envia? confere tudo (só lê) e diz o que fazer
echo '127.0.0.1 talk.connect.lab' | sudo tee -a /etc/hosts   # se o script pedir
```
Entre com `supervisor@connect.lab` / `connect123` (outros: financeiro@, administrativo@,
manutencao@, gerente.manutencao@). Em **Conexão WhatsApp**, conecte um número de teste por QR Code
(nome e número) e mande mensagens para ele de outro celular.

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

## Rotas
| Rota | Classe |
|---|---|
| `POST /webhook/{channel}` | `WebhookHandler::handle()` (sem login, valida assinatura) |
| `GET /webhook/{channel}` | `CloudApiGateway::verifySubscription()` (verificação da Meta) |
| `GET /events` | `SseStream::stream()` (tempo real) |
| `GET /api/conversations?tab=` | `InboxQuery::list()` |
| `GET /api/conversations/{id}` | conversa + contato + mensagens |
| `GET /api/search` | `InboxQuery::search()` |
| `POST /api/conversations/{id}/accept\|reply\|transfer\|close\|notes\|read` | `ConversationService` |
| `POST /lab/simulate` (só `APP_ENV=lab`) | simula mensagem de cliente |

Login por sessão + CSRF em todos os POST. Integração com o ERP ligada por
`ERP_API_URL` + `ERP_API_TOKEN` no `.env`.

## Estrutura
```
migrations/   SQL das tabelas talk_*
src/Gateway   WhatsAppGateway, CloudApiGateway, EvolutionGateway, GatewayFactory
src/Service   ConversationService (regras), AccessPolicy, InboxQuery, OutboxWorker, WebhookHandler, Texts
src/Realtime  EventBus, SseStream
src/Erp       ErpApi + HttpContactDirectory + HttpHelpdesk (REST); Null* quando sem ERP
public/       front controller, assets (JS puro) · views/ telas · tools/local/ instalação do laboratório
tests/        Unit + Integration (MariaDB real)
```
