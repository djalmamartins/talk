# Connect Talk num Raspberry Pi (escritório)

Decisão do Djalma (17/11/2026): o Talk inteiro roda num Raspberry Pi 4B de 4 GB no escritório. Ficam no Pi o
site, o worker, o banco, o backup e o motor do WhatsApp. O Pi e a internet ficam num nobreak próprio. Se
faltar luz ou internet por mais tempo, o escritório também para, então isso é aceito.

O Talk fala com o Connect ERP pela API `/api/v1/talk` (ADR 0308 do ERP, `docs/api-erp.md`). O ERP fica no
Cloud Startup Hosting.

É o mesmo `deploy/docker-compose.yml` do [producao.md](producao.md). Só muda a entrada:
- o **Cloudflare Tunnel** (`deploy/docker-compose.pi.yml`) entra no lugar do Caddy;
- não precisa de IP fixo nem de porta aberta no roteador;
- funciona com CGNAT.

```
celular ⇄ WhatsApp ⇄ Pi no escritório (Talk + Evolution) ⇄ túnel Cloudflare ⇄ https://talk.connectcondominios.com.br
                                   └──── API REST ────→ https://erp.connectcondominios.com.br/api/v1
```

## O que precisa
- Raspberry Pi 4 ou 5 com **4 GB** e fonte original.
- Um cartão SD para começar. Melhor ainda: um SSD no USB 3, porque o cartão gasta com dois bancos gravando.
- Cabo de rede no roteador e o nobreak.
- Uma conta gratuita na Cloudflare com o domínio `connectcondominios.com.br` (passo 3).

## 1. Gravar o sistema (no Mac)
1. Instale o **Raspberry Pi Imager** (raspberrypi.com/software).
2. Escolha **Raspberry Pi 4**, depois **Raspberry Pi OS (other) → Raspberry Pi OS Lite (64-bit)**, e por fim o
   cartão ou o SSD. Confira o tamanho do disco para não apagar outro.
3. Em **Editar configurações**, preencha:
   - nome `connect-talk`;
   - um usuário e uma senha, que ficam só com você;
   - fuso `America/Sao_Paulo`;
   - na aba Serviços, ative o **SSH**.
4. Grave, coloque no Pi, ligue no cabo e espere uns 2 minutos. Então, no Terminal do Mac:
   `ssh SEUUSUARIO@connect-talk.local`

## 2. Atualizar o Pi (dentro do ssh)
```bash
sudo apt update && sudo apt full-upgrade -y && sudo apt install -y git && sudo reboot
```

## 3. Túnel da Cloudflare (no navegador, uma vez)
1. Em dash.cloudflare.com, adicione o site `connectcondominios.com.br` (plano Free).
   - Confira se os registros que já existem vieram, principalmente os do site e do e-mail (MX).
   - Troque os servidores DNS do domínio para os da Cloudflare. A troca é feita no Registro.br, ou onde o
     domínio foi registrado.
2. Em **Zero Trust → Networks → Tunnels → Create a tunnel → Cloudflared**, use o nome `connect-talk`. Copie
   **só o token**, o texto longo que começa com `eyJ`.
3. Em **Public Hostname**, preencha:
   - subdomínio `talk`;
   - domínio `connectcondominios.com.br`;
   - Service: tipo `HTTP`, URL `app:80`.

## 4. Instalar o Talk (dentro do ssh)
```bash
git clone https://github.com/djalmamartins/talk ~/talk
cd ~/talk/deploy && bash instalar-pi.sh
```
O script:
- confere o Pi;
- instala o Docker;
- cria o `deploy/.env` com senhas e chaves novas;
- pede o endereço e o token do túnel;
- monta e liga tudo (a primeira vez leva de 10 a 15 minutos);
- testa;
- oferece criar o primeiro supervisor e mostra a senha inicial uma vez.

Rodar de novo não apaga nada e não troca senha.

**Guarde uma cópia do `deploy/.env` fora do Pi.** Sem a `TALK_KEY`, um backup não reconecta os números.

## 5. Conectar o WhatsApp
Em `https://talk.connectcondominios.com.br`, vá em **Conexão WhatsApp → Conectar novo número → QR Code**. Informe
o nome e o número, e leia o QR Code com o celular.

## 6. Ligar ao ERP
1. No servidor do ERP, crie o token de integração. Use um usuário só para isso, com `support.manage` e acesso aos
   condomínios:
   `php tools/talk-token.php criar --usuario=EMAIL_DO_USUARIO --nome="Connect Talk"`
2. No `deploy/.env` do Pi, preencha:
   ```
   ERP_API_URL=https://erp.connectcondominios.com.br/api/v1
   ERP_API_TOKEN=ctk_...   (o token que apareceu uma vez)
   ```
3. Aplique: `cd ~/talk/deploy && sudo docker compose up -d`

## Dia a dia
- **Tudo de pé?** `cd ~/talk/deploy && sudo docker compose ps`. Ou abra `https://talk.connectcondominios.com.br/health`.
- **Atualizar:** `cd ~/talk && git pull && cd deploy && bash instalar-pi.sh`
- **Backup:** sai todo dia às 2h em `deploy/backups/`. Copie essa pasta para fora do Pi (veja [producao.md](producao.md)).
- **Faltou luz ou internet além do nobreak:** quando voltar, o Pi e todos os serviços sobem sozinhos.
- **Trocar o Pi por um PC ou uma VPS:** restaure o backup lá, rode o mesmo `instalar-pi.sh` com o mesmo `.env`
  e o mesmo token do túnel.

## Segurança
- Nenhuma porta é aberta no roteador. Só o túnel sai para a Cloudflare.
- O banco e o motor do WhatsApp ficam só na rede interna do Docker.
- O `cloudflared` repassa o IP do cliente (`X-Forwarded-For`), e com `TRUST_PROXY=1` o limite de tentativas de
  login continua valendo.
- O `.env` e `deploy/backups` não vão para o Git.
