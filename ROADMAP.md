# Roadmap do Connect Talk até a v1.0

Prioridade definida pelo Djalma em 14/11/2026: **construir o sistema completo primeiro; a Jack
(Jaqueline) evolui depois.** A Jack fica como está (triagem sem menu, "digitando…", apresentação).

Base: telas de referência em `docs/referencias/` e funções do Atendechat que servem para uma
empresa só (`docs/porte-atendechat.md`).

| Fase | Entrega | Referência | Situação |
|---|---|---|---|
| S1 | Trocar atendente: para setor **ou** atendente, motivo (até 500), manter histórico | trocar-atendente.png | feito (0.5.0) |
| S1 | Mensagens rápidas (atalho `/` na resposta) | Atendechat QuickMessages | feito (0.5.1) |
| S1 | Enviar e receber arquivos: imagem, áudio, PDF, documento | Atendechat Chat | feito (0.6.0) |
| S1 | Painel do contato: dados do ERP, etiquetas, últimos chamados, notas, ações rápidas | caixa-de-entrada.png | feito (0.7.0); as ações já ficam no topo da conversa |
| S1 | Etiquetas nas conversas e contatos | Atendechat Tags | feito (0.7.0): no contato, aparecem na lista e filtram |
| S2 | Fila de atendimento: em espera, tempo médio, atendimentos do dia, taxa de resolução, prioridade, atendentes online | fila-de-atendimento.png | feito (0.8.0) |
| S3 | Contatos: lista, busca, edição, bloquear | Atendechat Contacts | feito (0.9.0) |
| S4 | Conexões WhatsApp: vários números, setor de cada número, QR Code, status | conexao-whatsapp.png | feito (0.10.0) |
| S5 | Atendentes e setores: cadastro, papéis (atendente, gerente, supervisor), senha | Atendechat Users/Queues | feito (0.11.0) |
| S6a | Agendamento de mensagens | Atendechat Schedules | feito (0.12.0) |
| S6b | Envio em massa pela API oficial (modelos aprovados, lista por etiqueta ou planilha; boletos quando a API do ERP sair) | Atendechat Campaigns/ContactLists | feito (0.13.0); 2ª via do boleto na conversa pela API do ERP (1.0.0) |
| S7 | Painel e relatórios (tempo de espera e de atendimento por setor e atendente) e avaliação pós-atendimento | Atendechat Dashboard/UserRating | feito (0.14.0) |
| S8a | Horário de atendimento (geral, por setor, 24 h, feriados) e mensagem fora do horário | Atendechat Settings | feito (0.15.0) |
| S8b | Produção: Docker, worker como serviço, backup do banco e dos arquivos, guia de instalação | — | feito (0.16.0); no Raspberry Pi com túnel e instalador (1.0.0, ADR 0005); falta instalar no Pi do escritório |
| U1 | Pedidos do Djalma no teste de 15/11: QR Code só com nome e número; topo com busca, notificações e perfil; seletor de sistemas; cor livre nas etiquetas; abas; sem simulador na tela | prints de 15/11 | feito (0.16.2 e 0.17.0) |
| U2 | Correções do primeiro teste real (15/11): número do cliente (LID), Jack encaminha calada, falta de água vai para Manutenção, número do chamado do Help Desk, formatação do WhatsApp na tela, emojis na resposta, favicon | conversa com o coordenador de 15/11 | feito (0.18.0); número CH… real depende do `/talk/tickets` no ERP |
| U3 | Som de alerta ao chegar mensagem, sino sem a bolinha por cima, botão "Abrir chamado" no Help Desk | pedido de 15/11 | feito (0.19.0); chamado real e nomes do ERP dependem da API do ERP (issue #400) |
| U4 | Protocolo SAC sem hífen (SAC26111600004, 0.21.1); protocolos da pessoa na lateral, do maior para o menor, 5 por página | pedido de 16/11 | feito (0.20.0) |
| U5 | Fila instantânea; Jack espera 5 s/30 s; espera não conta fora do horário e feriados | pedidos de 16/11 | feito (0.20.1 e 0.21.0) |
| U6 | Mudar o status do atendimento (fila, em atendimento, aguardando cliente/retorno interno, encerrado, reabrir) | pedido de 16/11 | feito (0.22.0) |
| U7 | Setor Geral: toda mensagem nova cai no Geral e a Jack distribui depois de 1 minuto | pedido de 16/11 | feito (0.24.0) |
| U8 | Sem a aba Fila em Atendimentos; aviso no sino quando transferem uma conversa | pedido de 16/11 | feito (0.26.0) |
| U9 | Fila com "com quem está", distribuição automática, Jack conta e consulta o chamado do SAC | pedido de 22/11 | feito (1.1.0) |
| U10 | Aba Serviço da Jack: lista de atendimentos com janela de detalhe (ideia, procedimento, respostas, o que não fez) e sugestões para ela melhorar | pedido de 22/11 | feito (1.2.0) |
| IA | Jack com IA local (Gemma 3 1B): plano em `docs/jack/IA.md`. IA0 sugestões, IA1 cenários e IA2 adaptador + modo sombra feitos | pedido de 22/11 | falta teste no Mac, depois Pi |
| v1.0 | Fechamento do primeiro roteiro: Talk separado no Raspberry Pi, dados do ERP pela API (pessoas, chamados, 2ª via) | decisão de 18/11 | feito (1.0.0) |
| J2+ | Jack: ver o plano da Jack V1 abaixo | ADR 0004, `docs/jack/MANIFESTO.md` | depois do sistema |

## Jack V1

Plano consolidado em `docs/jack/MANIFESTO.md` (seção 13), que separa a **Jack V1** (primeiro
atendimento no Talk) da **Jack Completa** (evolução futura). A regra em vigor continua sendo a ADR 0004.

| Etapa | Entrega | Depende de | Situação |
|---|---|---|---|
| JV1.0 | Manifesto da Jack; decidir se ela conversa ou só encaminha (registrar na ADR 0004) | Djalma | feito: calada na V1 (16/11) |
| JV1.1 | Painel de acertos na tela do supervisor | só o Talk | feito (0.23.0): seção no Painel |
| JV1.2 | Cenários fixos de triagem (laboratório mínimo) nos testes | JV1.1 | a fazer |
| JV1.3 | Resumo para o atendente (nota interna com assunto e protocolos anteriores) | só o Talk | a fazer |
| JV1.4 | Urgência: prioridade alta e aviso ao gerente | só o Talk | a fazer |
| JV1.5 | Chamado duplicado junta na conversa existente | PR talk#1 / erp#538 | a fazer |
| JV1.6 | Lembrete de espera com modelos aprovados editáveis | só o Talk | a fazer |
| JV1.7 | ~~Pergunta de desempate~~ | — | fora da V1 (decisão de 16/11); fica para a Jack Completa |
| JV1.8 | Jack cadastrada como atendente na Equipe (sem login), autora das mensagens dela | só o Talk | feito (0.25.0) |
| JV1.9 | Boleto pelo WhatsApp: "só um minuto que vou olhar", busca no ERP, envia e lembra do aplicativo | JV1.8 + rota `talk/charges` no ERP | aguardando ERP |
| JV1.10 | (Opcional) IA só para classificar, lista fechada, custo limitado | JV1.2 + aprovação | a decidir |
