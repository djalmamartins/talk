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
| S6b | Envio em massa pela API oficial (modelos aprovados, lista por etiqueta ou planilha; boletos quando a API do ERP sair) | Atendechat Campaigns/ContactLists | feito (0.13.0) |
| S7 | Painel e relatórios (tempo de espera e de atendimento por setor e atendente) e avaliação pós-atendimento | Atendechat Dashboard/UserRating | feito (0.14.0) |
| S8a | Horário de atendimento (geral, por setor, 24 h, feriados) e mensagem fora do horário | Atendechat Settings | feito (0.15.0) |
| S8b | Produção: Docker, worker como serviço, backup do banco e dos arquivos, guia de instalação | — | a fazer |
| J2+ | Jack: lembrete de espera, boleto pelo WhatsApp, IA na classificação | ADR 0004 | depois do sistema |
