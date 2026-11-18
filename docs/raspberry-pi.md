# Connect Talk num Raspberry Pi (escritório)

Decisão do Djalma (17 e 18/11/2026, ADR 0005): o Talk roda inteiro num Raspberry Pi no escritório, com um nobreak só
para o Pi e para a internet, e busca os dados no Connect ERP pela API (pessoas, chamados e 2ª via do boleto). Se faltar
luz ou internet por mais tempo que o nobreak aguenta, o Talk para junto, e o WhatsApp entrega o que chegou quando
ele voltar.

```
celular ⇄ WhatsApp ⇄ Pi do escritório (Talk + motor do WhatsApp + banco) ⇄ túnel Cloudflare ⇄ atendentes (navegador)
                                         └──── API REST com token ctk_ ────→ Connect ERP (hospedagem)
```

## O que precisa
- Raspberry Pi 4 ou 5 com **4 GB** de RAM ou mais (8 GB é folgado) e a fonte original.
- Um **SSD no USB 3** para o sistema. O cartão SD serve para testar, mas com o banco gravando o tempo todo estraga em meses.
- Cabo de rede no roteador (o Wi-Fi funciona, mas cai mais).
- Nobreak pequeno para o Pi e o roteador.
- Um pendrive ou HD externo para o backup (opcional, recomendado).
- Conta gratuita na Cloudflare com o domínio `connectcondominios.com.br`.
- No ERP: um usuário de integração e o token `ctk_` (passo 5).

## 1. Gravar o sistema
1. Instale o **Raspberry Pi Imager** (raspberrypi.com/software) no computador.
2. Escolha o modelo do Pi, depois **Raspberry Pi OS (other) → Raspberry Pi OS Lite (64-bit)**, e o SSD.
   Confira o tamanho do disco para não apagar outro.
3. Em **Editar configurações**:
   - nome `connect-talk`;
   - um usuário e uma senha, que ficam só com você;
   - fuso `America/Sao_Paulo`;
   - na aba Serviços, ative o **SSH**.
4. Grave, ligue o SSD no Pi e ligue o Pi. Em uns 2 minutos, no terminal: `ssh SEUUSUARIO@connect-talk.local`

## 2. Atualizar o Pi (dentro do ssh)
```bash
sudo apt update && sudo apt full-upgrade -y && sudo reboot
```

## 3. Túnel da Cloudflare (no navegador, uma vez)
O túnel publica o Talk em `https://talk.connectcondominios.com.br` com HTTPS, sem abrir porta no roteador. Funciona sem
IP fixo e com a operadora usando CGNAT.
1. Em dash.cloudflare.com, adicione o site `connectcondominios.com.br` (plano Free), se ainda não estiver lá. Confira se
   os registros de DNS e de e-mail (MX) vieram, e troque os servidores DNS do domínio no Registro.br para os da Cloudflare.
2. **Zero Trust → Networks → Tunnels → Create a tunnel → Cloudflared**, nome `connect-talk`. Copie **só o token**
   (o texto longo que começa com `eyJ`).
3. Em **Public Hostname**: subdomínio `talk`, domínio `connectcondominios.com.br`, Service `HTTP` e URL `app:80`.

> Já existe o túnel `connect-whatsapp` do ERP (ferramenta `tools/whatsapp-pi` do ERP, ADR 0349 do ERP)? Ele não é mais
> preciso: este Talk já traz o motor do WhatsApp. Apague o túnel antigo depois que este estiver funcionando.

## 4. Instalar o Talk (dentro do ssh)
```bash
git clone --depth 1 https://github.com/djalmamartins/talk ~/talk
bash ~/talk/deploy/instalar-pi.sh
```
O script:
- confere o Pi;
- instala o Docker;
- gera as senhas e chaves em `~/talk/deploy/.env`, que só o seu usuário lê;
- pergunta o endereço do Talk, o token do túnel e a ligação com o ERP;
- monta e liga tudo (site, worker, motor do WhatsApp, bancos, túnel e backup);
- cria o primeiro supervisor e mostra a senha dele uma vez;
- confere a ligação com o ERP.

Rodar de novo não apaga nada nem troca as chaves. **Guarde uma cópia do `TALK_KEY`** do `.env` num gerenciador de senhas:
sem ela, um backup restaurado não abre os tokens dos números.

## 5. Ligar no ERP
1. No ERP, crie em Configurações › Acesso › Usuários do Sistema o usuário "Connect Talk (integração)", com um papel
   que tenha a permissão de atender chamados e acesso aos condomínios que o Talk atende.
2. No servidor do ERP: `php tools/talk-token.php criar --usuario=EMAIL_DO_USUARIO`. O token aparece uma vez.
3. No Pi, cole no `~/talk/deploy/.env` (ou responda no passo 4):
   ```
   ERP_API_URL=https://erp.connectcondominios.com.br/api/v1
   ERP_API_TOKEN=ctk_...
   ERP_WEB_URL=https://erp.connectcondominios.com.br
   ```
4. Aplique e confira: `cd ~/talk/deploy && sudo docker compose up -d && sudo docker compose exec app php bin/erp-check.php`

Com o ERP ligado, o Talk mostra o nome, o condomínio e a unidade de quem escreve, abre o chamado no Help Desk e busca
a 2ª via do boleto do proprietário na conversa.

## 6. Ler o QR Code
No Talk, entre com o supervisor e vá em **Conexões → Novo número → QR Code**. Leia com o celular do número.

## Dia a dia
- **Faltou luz ou internet:** tudo volta sozinho quando o Pi religa.
- **Ver se está tudo ligado:** `cd ~/talk/deploy && sudo docker compose ps` e `https://talk.connectcondominios.com.br/health`.
- **Ver os logs:** `sudo docker compose logs --tail 50 app worker whatsapp`.
- **Atualizar:** `cd ~/talk && git pull && bash deploy/instalar-pi.sh`.
- **Backup:** todo dia às 2h, em `BACKUP_DIR` (padrão `deploy/backups`). Aponte `BACKUP_DIR` para o pendrive ou HD
  externo e, uma vez por semana, copie para fora do escritório. Restaurar: `docs/producao.md`, seção "Restaurar".
- **Se perder o Pi:** instale de novo, restaure o backup com o mesmo `TALK_KEY` e leia o QR Code de novo.

## Segurança
- O Pi não abre porta no roteador: só o túnel sai para a Cloudflare.
- O motor do WhatsApp e os bancos ficam só na rede interna do Docker.
- O token do ERP e as chaves ficam só no `deploy/.env` (permissão 600), nunca no Git nem no chat.
- O ERP pode limitar o token ao IP do escritório (`TALK_ALLOWED_IPS` no `.env` do ERP).
- Os dados das conversas ficam no Pi. Um pedido de titular (LGPD) precisa ser atendido também aqui, além do ERP.
