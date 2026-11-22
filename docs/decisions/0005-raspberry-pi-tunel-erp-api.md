# 0005 — Produção num Raspberry Pi com túnel da Cloudflare; dados do ERP só pela API

- **Status:** aceito
- **Data:** 2026-11-18
- **Relacionados:** 0003 (hospedagem separada, só API REST), ADR 0308, 0359 e 0386 do Connect ERP.

## Contexto
Em 16/11 o Talk foi levado para dentro do ERP (ADR 0324 do ERP). Em 17/11 o Djalma voltou atrás: o Talk continua sendo
este projeto, instalado num Raspberry Pi no escritório com nobreak (ADR 0359 do ERP). Em 18/11 ele pediu para fechar o
primeiro roteiro assim, buscando as informações do ERP pela API. A hospedagem do ERP não roda processo contínuo, e o
motor do WhatsApp por QR Code precisa ficar ligado o tempo todo.

## Decisão
- **Um aparelho, tudo junto.** O mesmo `deploy/docker-compose.yml` roda no Pi (ARM 64 bits) e num PC: site, worker,
  motor do WhatsApp (Evolution), MariaDB, Postgres do motor e backup. Todas as imagens têm versão `arm64`.
- **Entrada pela internet escolhida no `.env`** (`COMPOSE_PROFILES`): `tunnel` (Cloudflare Tunnel, padrão) dá HTTPS
  sem abrir porta, sem IP fixo e com CGNAT; `caddy` (Let's Encrypt) continua para quem abrir as portas 80 e 443.
- **Instalador** `deploy/instalar-pi.sh`: confere o aparelho, instala o Docker, gera as chaves uma vez, pergunta o
  domínio, o token do túnel e a ligação com o ERP, sobe tudo, cria o primeiro supervisor e confere o ERP
  (`bin/erp-check.php`). Rodar de novo não troca nada.
- **Pi com pouca folga:** banco com 256 MB de buffer (`DB_BUFFER_POOL`), logs do Docker limitados a 30 MB por serviço,
  backup em pasta configurável (`BACKUP_DIR`, de preferência num disco externo).
- **ERP só pela API** (`ERP_API_URL` + token `ctk_`): pessoas, chamados e, agora, a 2ª via do boleto
  (`GET /talk/charges`, ADR 0386 do ERP).

## Consequências
- Sem luz ou internet no escritório além do nobreak, o Talk para; o WhatsApp entrega o atrasado quando voltar.
- Os dados das conversas ficam no Pi. O backup precisa sair do escritório, e o pedido de titular (LGPD) é atendido nos
  dois sistemas.
- O motor do WhatsApp só no Pi do ERP (`tools/whatsapp-pi`) deixa de ser necessário.

## Atualização 22/11/2026 — protocolo único e chamado só pelo botão (pedido do Djalma)

- O protocolo da conversa fica sem "SAC" na frente (aammdd + 5 dígitos, ex.: 26112200403). A migração 027 tirou o
  prefixo dos números antigos; a busca e a consulta pelo WhatsApp aceitam os dois jeitos.
- Conversa comum fica só no Talk. O chamado só vai para o SAC quando o atendente clica em **Abrir chamado** e preenche
  título, descrição e o setor que vai cuidar (escolha do Djalma no cartão de decisão). O chamado usa o mesmo protocolo
  da conversa (ADR 0637 do ERP) e continua aberto no SAC depois que a conversa é encerrada no Talk.
- `SAC_AUTO_TICKET=on` volta o comportamento antigo (todo encaminhamento abre chamado, e encerrar a conversa encerra o
  chamado). A Jack só fala em chamado quando ele existe.
