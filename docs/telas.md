# Telas de referência (enviadas pelo Djalma em 14/11/2026)

As imagens em `docs/referencias/` são a **direção visual** das próximas fases: identidade Connect
Condomínios (verde e dourado sobre preto), layout claro com menu lateral. São referência de
layout e comportamento; a implementação é própria.

| Imagem | Tela | O que já existe | O que falta | Fase |
|---|---|---|---|---|
| `caixa-de-entrada.png` | Caixa de entrada: lista (Todas, Fila, Minhas, Não lidas), chat, painel com Contato, Chamados, Histórico, Notas e ações rápidas | Lista, chat, notas internas, transferir, encerrar, painel do contato | Aba "Não lidas", avatar, etiquetas, últimos chamados do ERP, anexos, emoji, áudio | UI-1 |
| `trocar-atendente.png` / `-modal.png` | Trocar atendente: grupo **ou** atendente, motivo (até 500), manter histórico | Transferir para setor com nota | Transferir para atendente específico, contador 500, opção "manter histórico" (padrão ligado), aviso ao cliente | UI-1 |
| `fila-de-atendimento.png` | Fila: em espera, tempo médio, atendimentos hoje, taxa de resolução, tabela com prioridade e tempo de espera, atendentes disponíveis | Aba Fila | Painel com indicadores, prioridade, tempo de espera colorido, atendentes online | UI-2 |
| `conexao-whatsapp.png` | Conexão WhatsApp: vários números, cada um com setor e atendentes | Tabela `talk_channels` com finalidade | Tela de gestão dos números, setor padrão por número, status da conexão, QR Code | UI-2 |
| `jack1..4.jpg` | Jaqueline ("Jack") | Avatar em `public/assets/jack/` | Mostrar a Jack como autora das mensagens automáticas e no painel de decisões | J1/J2 |

Paleta aplicada em `public/assets/app.css`: verde `#12804A`, verde escuro `#0B5C34`, dourado
`#C9A227`, preto `#111512`. Os valores oficiais (hex do manual da marca) ainda estão pendentes;
basta trocar as variáveis em `:root`.
