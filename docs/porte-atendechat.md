# Porte de funções do Atendechat para o Connect Talk

O que existe no Atendechat (Codatendechat 6.0.0, em `/mnt/project-files/connect-talk`) e o destino
de cada item no Talk PHP. Atualizado a cada rodada de trabalho.

## Vem para o Talk
| Atendechat | No Talk | Situação |
|---|---|---|
| Tickets / Chat | Caixa de entrada, conversa, aceitar, responder, encerrar | feito (0.2.0) |
| Mídia (SendWhatsAppMedia, downloadMedia) | Enviar e receber arquivos: imagem, áudio, vídeo, PDF, documento; download com conferência de acesso | feito (0.6.0) |
| Queues (filas) | Setores Financeiro, Administrativo, Manutenção | feito (0.1.0); cadastro em S5 |
| TicketNote | Notas internas | feito |
| Transferência | Trocar atendente (setor ou atendente) | feito (0.5.0) |
| QuickMessages | Mensagens rápidas: feito na 0.5.1, com mensagens por setor (no Atendechat eram por empresa/usuário) e campos {primeiro_nome}, {protocolo}, {setor}, {atendente} | S1 |
| Envio de mídia | Arquivos na conversa | S1 |
| Tags / TicketTag | Etiquetas: feito (0.7.0), no contato (não no ticket), para seguir a pessoa em todas as conversas; filtro na lista | S1 |
| ContactNotes (painel do contato) | Painel com dados do ERP, últimos chamados e notas do contato: feito (0.7.0) | S1 |
| Contacts | Contatos | S3 |
| Connections / Whatsapp | Conexões WhatsApp | S4 |
| Users / UserQueue | Atendentes e setores | S5 |
| Schedules | Agendamento de mensagens | S6 |
| Campaigns / ContactLists | Envio em massa (API oficial) | S6 |
| Dashboard | Painel e relatórios | S7 |
| UserRating | Avaliação pós-atendimento | S7 |
| Settings (horário) | Horário de atendimento | S8 |

## Fica de fora (e por quê)
| Atendechat | Motivo |
|---|---|
| Companies, Plans, Subscription, Invoices, Financeiro, Signup | Coisas de SaaS; o Talk é só da Connect |
| Announcements, Helps | Avisos do dono do SaaS para clientes |
| QueueOption (menu 1, 2, 3) | O Djalma não quer menu; a Jack faz a triagem |
| Prompts (OpenAI) e QueueIntegration (Typebot, n8n) | A Jack substitui; IA só na fase J4, com aprovação |
| Kanban, ToDoList, Chat interno, Files | Fora do escopo da v1.0; reavaliar depois |
