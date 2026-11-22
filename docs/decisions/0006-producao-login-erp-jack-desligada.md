# 0006 — Primeira versão de produção: login só pelo ERP e Jack desligada com menu de setores

- **Status:** aceito
- **Data:** 2026-11-22
- **Relacionados:** 0004 (Jack), 0005 (Raspberry Pi e API do ERP), ADR 0643 do Connect ERP (login único), ADR 0637 do ERP (protocolo único).

## Contexto
Pedido do Djalma em 22/11/2026: "tirar a Jaque da jogada por enquanto e preparar a versão 1 do Talk para colocar em
produção... depois a gente acrescenta a Jaque numa versão 1.1, 1.2 ou 2.0". E: "os mesmos usuários do ERP consigam
acessar e outras pessoas não consigam... só quem está cadastrado no ERP como funcionário da Connect... coloca para cada
usuário a permissão de acesso lá". No cartão de decisão ele escolheu "Desligar com menu de setores".

Os números 1.1.0 a 1.3.0 já tinham sido usados nas versões de laboratório (fila, Serviço da Jack, avaliação), então a
primeira versão de produção é a **1.4.0**.

## Decisão
1. **Login só pelo Connect ERP** quando `ERP_API_URL` e `ERP_WEB_URL` estão no `.env` (`TALK_LOGIN=erp`, padrão nesse caso).
   - A tela de login tem só o botão "Entrar pelo Connect ERP", que leva a `ERP_WEB_URL/ambiente/talk`. O ERP confere senha,
     2FA e a permissão `talk.access` e devolve o navegador a `/ambiente/entrar/talk/{código}` (uso único, 60 s).
   - O Talk troca o código em `POST /talk/staff/login` (escopo `staff.login` do token) e acha o perfil pelo `erp_user_id`,
     depois pelo e-mail (só perfil ainda sem conta do ERP); se não existir, cria sem setor. Perfil desativado no Talk não entra.
   - A cada `TALK_LOGIN_RECHECK_SECONDS` (600) pergunta `GET /talk/staff/{id}`; sem acesso no ERP, a sessão cai. ERP fora
     do ar não derruba ninguém.
   - Senha do Talk deixa de valer: o POST de `/login` é recusado, "Trocar senha" some e Equipe não pede senha. O supervisor
     pode cadastrar a pessoa antes pelo e-mail do ERP para ela já entrar com papel e setores.
   - Limite: 10 códigos recusados por IP em 15 minutos (o mesmo `LoginGuard` do login com senha).
   - `TALK_LOGIN=senha` mantém as senhas do Talk (laboratório sem ERP).
2. **Jack desligada** (`JACK_MODE=off`). O código dela continua, para voltar numa versão seguinte.
3. **Menu de setores** (`TALK_MENU=on`): a conversa nova recebe "Oi, Maria! Aqui é a Connect... responda com o número do
   setor" com os setores ativos numerados por `menu_key` (sem o Geral). Aceita o número, "opção 2", o emoji 2️⃣ ou o
   nome do setor. Resposta que não serve recebe o menu uma segunda vez; depois disso, ou com 10 minutos sem resposta
   (`TALK_MENU_TIMEOUT_SECONDS`), vai para o setor padrão. Número dedicado a um setor e resposta a envio em massa não
   passam pelo menu. O menu não é assinado como Jack.

## Consequências
- O token do Pi precisa ser criado no ERP com o escopo `staff.login`; `bin/erp-check.php` avisa quando falta.
- O `.env` do ERP precisa de `TALK_URL` com o endereço do Talk.
- Quem administra o acesso é o ERP (papel com `talk.access` ou liberação por usuário). O papel no Talk (atendente,
  gerente, supervisor) e os setores continuam no Talk, em Equipe e setores.
