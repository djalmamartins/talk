# 0003 — Talk em hospedagem separada, integrado ao ERP por API REST

Status: **aceita** (2026-11-14) · substitui o item "mesmo servidor do ERP" da [0002](0002-connect-talk-module.md)

## Contexto
A 0002 previa o Talk dentro do ERP, lendo as tabelas direto. O Djalma propôs separar:
o Talk em hospedagem própria e a conversa com o ERP por API REST.

## Decisão
- O Talk é uma **aplicação independente** (PHP 8.2 + MariaDB próprio + JS), com
  repositório próprio (`djalmamartins/talk`) e deploy próprio.
- O ERP **expõe uma API REST** para o Talk ([docs/api-erp.md](../api-erp.md)):
  buscar pessoa pelo telefone (condomínio/unidade) e abrir/transferir/encerrar chamados no helpdesk.
- O Talk guarda só o que é dele: conversas, mensagens, protocolos, filas, usuários do atendimento.
  Cadastro de pessoas e chamados continuam sendo do ERP.
- Autenticação Talk → ERP por **token Bearer** (`ERP_API_TOKEN`), só HTTPS.
- **O ERP fora do ar não para o atendimento**: o Talk segue sem identificar a pessoa e
  sem registrar no helpdesk naquele momento (o erro vai para o log).
- Login: usuários próprios do Talk (`talk_users`, com `erp_user_id` para registrar autoria
  nos chamados). Login único com o ERP fica para depois.

## Por que é melhor
- Um problema no Talk (picos de mensagens, WhatsApp) não derruba o ERP, e vice-versa.
- Deploy e atualização independentes; o Talk pode ir para um servidor/VPS com IP fixo e HTTPS
  público (necessário para o webhook da Meta), sem expor o ERP.
- Contrato explícito: o ERP pode mudar por dentro sem quebrar o Talk.

## Consequências
- O time do ERP precisa implementar os endpoints de `docs/api-erp.md`.
- Sem chamadas cruzadas de banco: relatórios que juntam Talk + ERP passam pela API ou por exportação.
- Laboratório: `talk.connect.lab` (XAMPP) roda sem ERP; com `ERP_API_URL` aponta para o ERP local.
