# API REST do Connect ERP para o Connect Talk (v1)

Especificação do que o Connect Talk precisa que o Connect ERP exponha. O Talk roda em hospedagem
separada (ADR 0003) e fala com o ERP **somente** por esta API. Baseada no código do
`djalmamartins/connect-erp` em 14/11/2026 (HEAD `e8939b3`).

Legenda: **CONFIRMADO** = existe no repositório do ERP; **PROPOSTO** = precisa ser criado;
**PENDENTE** = decisão da equipe do ERP.

> **Situação em 18/11/2026: tudo implementado no ERP.** Seções 1 a 5 no ADR 0308 do ERP (PR connect-erp#538) e a
> seção 6 no ADR 0386. O token `ctk_` sai de `php tools/talk-token.php criar --usuario=EMAIL` no servidor do ERP. No
> Talk: `HttpContactDirectory`, `HttpHelpdesk` e `HttpChargeDirectory` (botão "2ª via" na conversa, ADR 0005).
> Conferir a ligação: `php bin/erp-check.php`. Diferenças do que estava proposto abaixo: só o proprietário e o
> responsável financeiro recebem boleto (inquilino fora), e `portal_url` aponta para a página do boleto no app do
> morador, sem link assinado de PDF.

## 0. O que já existe no ERP e será reaproveitado (CONFIRMADO)

| Necessidade do Talk | Onde está no ERP |
|---|---|
| Pessoas com telefone, por condomínio | `erp_residents` (`condominium_id`, `unit_id`, `relationship` owner/tenant/resident/financial, `name`, `email`, `phone varchar(13)`, `status`, `starts_on`/`ends_on`, `deleted_at`) |
| Unidades | `erp_units` (`condominium_id`, `block`, `label`, `status`, `deleted_at`) |
| Condomínios | `condominiums` (`name`, `status`) |
| Chamados (Help Desk) | `studio_support_tickets` + `TicketService::open()`; colunas `origin`, `condominium_id`, `unit_id`, `requester_name`, `requester_phone`, `area` general/technical/financial (ADR 0034) |
| Histórico do chamado | `studio_support_ticket_events` (`event_type`, `old_value`, `new_value`) e `studio_support_ticket_messages` |
| Boletos | `erp_bank_charges` (`status` registered/paid…, `amount_cents`, `due_on`, `digitable_line`, `barcode`, `pix_copy_paste`) ligado a `erp_entries.unit_id` |
| API REST | `/api/v1`, controllers em `source/Controllers/Api/V1`, resposta `{"data": ...}` e erro `{"error": {"code", "message", "fields"}}`, OpenAPI em `docs/api/openapi.yaml` |
| Auditoria | `system_audit_logs` / `AppLogger` |

## 1. Autenticação de serviço (PROPOSTO, PENDENTE de ADR no ERP)

Hoje o `ApiAuthenticated` só aceita **sessão + CSRF** (feito para o navegador). O Talk é um
servidor, então precisa de um acesso máquina-a-máquina. Proposta, sem criar sistema paralelo de
usuários:

- Um **usuário de sistema** comum em `users` (ex.: "Connect Talk — integração"), com as
  permissões existentes que fizerem sentido (`erp.access`, leitura de pessoas/unidades, abrir e
  movimentar chamados, ver cobranças) e um perfil próprio, por exemplo `talk.integration`.
- Um **token de integração** ligado a esse usuário, guardado só como hash (SHA-256), com data
  de criação, último uso e revogação. Enviado como `Authorization: Bearer <token>`.
- Rotas `/api/v1/talk/*` aceitam **somente** esse token (sem sessão e sem CSRF, porque não há
  cookie). As demais rotas `/api/v1` continuam como estão.
- HTTPS obrigatório, limite de requisições (sugestão: 120/min), lista de IPs opcional
  (`TALK_ALLOWED_IPS`), toda chamada registrada na auditoria com `origin = talk`.
- Cabeçalho informativo enviado pelo Talk: `X-Talk-Client: connect-talk`.

Escopo de condomínio: o usuário de integração atende todos os condomínios da Connect (ADR-002,
administradora única), mas toda resposta continua filtrada por `condominium_id` da pessoa
identificada. **Nada de `tenant_id`.**

## 2. Identificar quem escreveu — `GET /api/v1/talk/people?phones=...`

`phones`: até 6 variantes do mesmo número, separadas por vírgula, só dígitos (com e sem 55, com e
sem o nono dígito). O ERP compara com `erp_residents.phone` normalizado só com dígitos.

Regras: só `status = 'active'`, `deleted_at IS NULL` e vínculo vigente
(`ends_on IS NULL OR ends_on >= CURDATE()`). O mesmo telefone pode estar em mais de um
condomínio ou unidade, então a resposta é uma **lista** (máx. 10), ordenada pelo cadastro
mais recente.

```json
200 {"data": [
  {"id": 77, "name": "João Morador", "relationship": "owner", "email": "joao@exemplo.com",
   "condominium": {"id": 3, "name": "Residencial Jardins"},
   "unit": {"id": 9, "label": "Bloco A 101"}}
]}
```

Sem cadastro: `200 {"data": []}` (ou 404, o Talk aceita os dois). Não devolver documento (CPF)
nem dados financeiros aqui (LGPD: mínimo necessário).

## 3. Abrir chamado — `POST /api/v1/talk/tickets`

Toda conversa do Talk vira um chamado no Help Desk. Usa `TicketService::open()` com uma
**nova origem `talk`** (acrescentar em `ORIGINS` e no ENUM `origin`, migration com `.down.sql`).

```json
{
  "talk_protocol": "SAC202611140001",
  "subject": "WhatsApp SAC202611140001",
  "area": "technical",
  "resident_id": 77,
  "condominium_id": 3,
  "unit_id": 9,
  "requester_name": "João Morador",
  "requester_phone": "5531999998888",
  "conversation_url": "https://talk.connect.com.br/?c=12"
}
```

- `area`: o Talk manda Financeiro → `financial`, Manutenção → `technical`,
  Administrativo → `general`. Se a equipe do ERP preferir áreas próprias (ex.: `administrative`,
  `maintenance`), basta avisar e o Talk ajusta o mapa.
- `message` (mínimo 10 caracteres no `normalize()`): o ERP monta o texto a partir da primeira
  mensagem, ou aceita o campo `message` que o Talk pode mandar.
- **Idempotência**: guardar `talk_protocol` numa coluna nova `external_ref varchar(40)` com
  índice único. Repetir o POST com o mesmo protocolo devolve `200` com o mesmo chamado (em vez
  de `201`). Isso evita chamado duplicado quando a rede falha.
- `resident_id`, `condominium_id` e `unit_id` são opcionais (contato não identificado) e o ERP
  valida que o morador pertence ao condomínio e à unidade informados.
- `conversation_url` vai como evento do chamado ou campo, para o atendente do ERP abrir a
  conversa no Talk.

```json
201 {"data": {"id": 500, "protocol": "TK2611140001", "status": "open"}}
```

O `protocol` devolvido é o número do chamado no Help Desk (no ERP, `CH` + data + 4 caracteres). O Talk o guarda em
`talk_conversations.helpdesk_protocol` e é ele que o cliente vê nas mensagens (fora do horário, encerramento).
Sem `protocol` na resposta, o cliente vê o protocolo do Talk.

## 4. Transferir — `POST /api/v1/talk/tickets/{id}/transfer`

```json
{"area": "financial", "sector": "Financeiro", "note": "Cobrança do reparo", "talk_user_id": 2}
```

Atualiza `area`, grava `studio_support_ticket_events` (`event_type = 'area_changed'`,
`old_value`, `new_value`) e a nota como **mensagem interna**. `talk_user_id` é o atendente no
Talk (fica no evento; não é usuário do ERP). Resposta `200 {"data": {...chamado}}` ou `204`.

## 5. Encerrar — `POST /api/v1/talk/tickets/{id}/close`

```json
{"resolution": "Vazamento corrigido pelo zelador.", "talk_user_id": 2}
```

`status = 'resolved'`, `resolved_at = NOW()`, evento `status_changed` e a resolução como
mensagem. Chamar de novo num chamado já resolvido devolve `200` sem mudar nada.

## 6. Boletos em aberto (2ª via) — `GET /api/v1/talk/charges?resident_id=77&phone=5531999998888`

Usado pelo atendente no botão **2ª via** da conversa (ADR 0005) e, depois, pela assistente **Jack** (ADR 0004 do
Talk) quando o morador pede o boleto pelo WhatsApp. O Talk trata `404` como "nenhum boleto", sem motivo.
É o endpoint mais sensível, então tem regras extras:

- Exige `resident_id` **e** `phone`; o ERP confere que o telefone pertence àquele morador. Sem
  conferência = `404` (não dizer o motivo).
- Só devolve cobranças das unidades daquele morador (`erp_entries.unit_id` = `erp_residents.unit_id`
  do mesmo `condominium_id`), com vínculo `owner` ou `financial` (inquilino só se a equipe do ERP
  decidir que sim: **PENDENTE**).
- Só `erp_bank_charges.status = 'registered'` e não vencidas há mais de 60 dias (vencidas mais
  antigas: o Talk manda para o Financeiro). Máx. 3 itens.
- Cada consulta vai para a auditoria com o telefone mascarado.

```json
200 {"data": [
  {"id": 9001, "condominium": "Residencial Jardins", "unit": "Bloco A 101",
   "competence": "2026-11", "due_on": "2026-11-10", "amount_cents": 65000,
   "digitable_line": "00190.00009 01234.567890 ...", "pix_copy_paste": "000201...",
   "portal_url": "https://morador.connect.com.br/boletos/9001"}
]}
```

- `portal_url`: link para o portal/aplicativo do morador (exige login lá). **PENDENTE**: se
  houver PDF do boleto, preferir um link assinado e com validade curta (ex.: 24 h) a expor o
  PDF por id.
- Sem boleto em aberto: `200 {"data": []}`.

## 7. Erros (padrão do ERP)

`{"error": {"code": "...", "message": "...", "fields": {}}}`, com `401 unauthenticated`,
`403 forbidden`, `404 not_found`, `422 validation_failed`, `429 too_many_requests`,
`500 internal_error`. O Talk trata `404` como "não encontrado"; qualquer outro `4xx/5xx` é
registrado e **não para o atendimento** (o chamado é tentado de novo depois).

## 8. Critérios de aceite

1. Contrato das 6 rotas em `docs/api/openapi.yaml` (tag `Talk`).
2. Testes de contrato e de escopo: token inválido = 401; morador de outro condomínio nunca
   aparece; boleto só com telefone conferido; POST de chamado repetido não duplica.
3. Migrations novas com `.down.sql` (origem `talk`, `external_ref`, tabela de tokens).
4. Artigo na Central de Ajuda (ADR 0141) sobre o chamado vindo do WhatsApp.
5. Ambiente de teste/lab com dados fictícios, URL e token para o Talk testar
   (`ERP_API_URL=https://erp.connect.lab/api/v1`).

## Exemplos

```bash
curl -H "Authorization: Bearer $TOKEN" \
  "https://erp.connect.lab/api/v1/talk/people?phones=5531999998888,31999998888"

curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"talk_protocol":"SAC202611140001","subject":"WhatsApp SAC202611140001","area":"technical","requester_phone":"5531999998888"}' \
  https://erp.connect.lab/api/v1/talk/tickets
```
