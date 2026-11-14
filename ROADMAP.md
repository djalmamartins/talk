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
| S1 | Painel do contato: dados do ERP, etiquetas, últimos chamados, notas, ações rápidas | caixa-de-entrada.png | a fazer |
| S1 | Etiquetas nas conversas e contatos | Atendechat Tags | a fazer |
| S2 | Fila de atendimento: em espera, tempo médio, atendimentos do dia, taxa de resolução, prioridade, atendentes online | fila-de-atendimento.png | a fazer |
| S3 | Contatos: lista, busca, edição, bloquear | Atendechat Contacts | a fazer |
| S4 | Conexões WhatsApp: vários números, setor de cada número, QR Code, status | conexao-whatsapp.png | a fazer |
| S5 | Atendentes e setores: cadastro, papéis (atendente, gerente, supervisor), senha | Atendechat Users/Queues | a fazer |
| S6 | Agendamento de mensagens e envio em massa pela API oficial (boletos) | Atendechat Schedules/Campaigns | a fazer |
| S7 | Painel e relatórios (tempo de espera e de atendimento por setor e atendente) e avaliação pós-atendimento | Atendechat Dashboard/UserRating | a fazer |
| S8 | Horário de atendimento e mensagem fora do horário; produção (Docker, worker, backup) | Atendechat Settings | a fazer |
| J2+ | Jack: lembrete de espera, boleto pelo WhatsApp, IA na classificação | ADR 0004 | depois do sistema |
