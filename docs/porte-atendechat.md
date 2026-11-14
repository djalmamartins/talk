# Porte de funções do Atendechat para o Connect Talk

O que existe no Atendechat (Codatendechat 6.0.0, em `/mnt/project-files/connect-talk`) e o destino
de cada item no Talk PHP. Atualizado a cada rodada de trabalho.

## Vem para o Talk
| Atendechat | No Talk | Situação |
|---|---|---|
| Tickets / Chat | Caixa de entrada, conversa, aceitar, responder, encerrar | feito (0.2.0) |
| Mídia (SendWhatsAppMedia, downloadMedia) | Enviar e receber arquivos: imagem, áudio, vídeo, PDF, documento; download com conferência de acesso | feito (0.6.0) |
| Queues (filas) | Setores Financeiro, Administrativo, Manutenção | feito (0.1.0); cadastro na tela Equipe e setores (0.11.0), com cor e descrição. Sem mensagem de saudação por fila: quem cumprimenta é a Jack |
| TicketNote | Notas internas | feito |
| Transferência | Trocar atendente (setor ou atendente) | feito (0.5.0) |
| QuickMessages | Mensagens rápidas: feito na 0.5.1, com mensagens por setor (no Atendechat eram por empresa/usuário) e campos {primeiro_nome}, {protocolo}, {setor}, {atendente} | S1 |
| Envio de mídia | Arquivos na conversa: feito (0.6.0), ver linha "Mídia" acima | S1 |
| Tags / TicketTag | Etiquetas: feito (0.7.0), no contato (não no ticket), para seguir a pessoa em todas as conversas; filtro na lista | S1 |
| ContactNotes (painel do contato) | Painel com dados do ERP, últimos chamados e notas do contato: feito (0.7.0) | S1 |
| Contacts | Contatos: feito (0.9.0). Lista, busca, filtros, editar nome, histórico e bloquear. Sem importar da agenda do celular nem apagar (LGPD: exclusão vira processo à parte) | S3 |
| Connections / Whatsapp | Conexão WhatsApp: feito (0.10.0). Vários números (QR Code pela Evolution, API oficial da Meta), setor por número, número padrão, QR Code na tela com conferência automática, ativar/desativar. Sem filas por conexão do Atendechat: aqui o setor do número substitui | S4 |
| Users / UserQueue | Equipe e setores: feito (0.11.0). Atendentes com papel (atendente, gerente, supervisor) e setores, senha com troca pela própria pessoa, desativar devolve os atendimentos à fila. Sem "perfil admin/user" do Atendechat: os três papéis do Talk cobrem | S5 |
| Schedules | Agendamentos: feito (0.12.0). Lista por dia, editar e cancelar, botão "Agendar" na conversa; abre conversa nova com protocolo quando não há uma aberta. Sai só pelo QR Code (a Meta exige modelo fora da janela de 24 h) | S6a |
| Campaigns / ContactLists | Envio em massa (API oficial) | S6 |
| Tickets (aba "pendentes") + tela de referência | Fila de atendimento com indicadores, prioridade e atendentes online: feito (0.8.0). No Atendechat não existe tela própria; seguimos a tela de referência | S2 |
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
