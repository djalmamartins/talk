# Connect Talk em produção (PC do escritório)

Tudo roda em Docker, num PC ligado o tempo todo. São cinco serviços (`deploy/docker-compose.yml`):

| Serviço | O que faz |
|---|---|
| `caddy` | HTTPS automático (Let's Encrypt) no domínio, ex.: `talk.connect.com.br` |
| `app` | o site (PHP 8.2 + Apache). Ao subir, aplica as migrations sozinho |
| `worker` | envia as mensagens, faz a triagem da Jack, agendamentos e envio em massa |
| `db` | MariaDB 11.4 (dados no volume `db`) |
| `backup` | cópia diária do banco e dos arquivos em `deploy/backups/` |

O projeto do docker-compose se chama `talk-php`. Isso evita misturar com o Talk antigo em Node,
que se chama `connect-talk`.

## Antes de começar
1. **Domínio:** crie no DNS o registro `talk.connect.com.br` apontando para o IP público do escritório.
2. **Roteador:** encaminhe as portas **80** e **443** para o PC. A Meta só entrega mensagens em HTTPS,
   e o certificado é emitido pela porta 80.
   - Sem IP fixo ou sem como abrir porta, a alternativa é um túnel (Cloudflare Tunnel) apontando
     para `http://localhost:80`. Nesse caso tire as `ports` do `caddy` e deixe o túnel na frente.
3. **No PC:** instale o Docker (Docker Desktop no Windows/Mac ou Docker Engine no Linux).

## Instalação (uma vez)
```bash
git clone https://github.com/djalmamartins/talk
cd talk/deploy
cp .env.example .env
```
Preencha o `.env`:
- `DB_PASSWORD` e `DB_ROOT_PASSWORD`: gere com `openssl rand -base64 24`.
- `TALK_KEY`: gere com `openssl rand -base64 32`. **Guarde uma cópia fora do PC** (gerenciador de
  senhas). É ela que abre os tokens do WhatsApp guardados no banco; sem ela, um backup restaurado
  não reconecta os números.
- `ERP_API_URL` e `ERP_API_TOKEN`: quando a API do ERP existir. Vazio, o Talk funciona sozinho.

Depois:
```bash
docker compose up -d --build
docker compose run --rm app php bin/setup.php --email=djalma@connect.com.br --name="Djalma"
```
O `setup` cria os setores da Jack (Financeiro, Administrativo, Manutenção) e o primeiro supervisor.
Ele mostra a **senha inicial uma vez só**. Entre em `https://talk.connect.com.br` e troque a senha
em "Trocar senha".

Em seguida, na tela:
- **Equipe e setores:** cadastre os atendentes.
- **Conexão WhatsApp:** número de QR Code (Evolution) e número oficial (Meta). Use o número
  real só quando for a hora.
- **Horário de atendimento:** ligue quando estiver tudo certo.

## Conferir se está tudo de pé
```bash
docker compose ps                      # todos "Up", app e worker "(healthy)"
curl https://talk.connect.com.br/health
# {"ok":true,"db":"ok","worker":{"ok":true,"seconds_since":3}}
docker compose logs -f worker          # o que o worker está fazendo
```
- `worker.ok: false` quer dizer que o worker não dá sinal há mais de 90 s.
- O Docker reinicia o worker sozinho se ele parar.
- O worker também se reinicia a cada hora, de propósito: assim não acumula memória nem conexão velha.

## Atualizar para uma versão nova
```bash
cd talk && git pull
cd deploy && docker compose up -d --build
```
As migrations rodam sozinhas ao subir o `app`. Antes de atualizar, faça um backup (abaixo).

## Backup
- **Automático:** todo dia às `BACKUP_HOUR` (padrão 2h), em `deploy/backups/talk-AAAAMMDD-HHMM/`.
  - Cada pasta tem `banco.sql.gz` (o banco inteiro), `arquivos.tar.gz` (fotos, áudios e PDFs das
    conversas) e `SHA256SUMS` (para conferir se o arquivo não corrompeu).
  - Guarda `BACKUP_KEEP_DAYS` dias (padrão 14).
- **Na hora:** `docker compose exec backup bash /scripts/backup.sh`.
- **Copie a pasta `deploy/backups` para fora do PC** (HD externo, Google Drive, outro computador).
  Backup que fica só no mesmo PC não protege contra defeito no disco.
- O `.env` **não** entra no backup, porque tem as senhas e a `TALK_KEY`. Guarde-o à parte.

## Restaurar um backup
```bash
cd talk/deploy
docker compose stop app worker
./restore.sh backups/talk-20261115-0200
docker compose start app worker
```
- O script confere os arquivos, pede confirmação e **substitui** o banco e os arquivos atuais
  pelos da cópia.
- Testado em 14/11/2026: as tabelas, os registros e os arquivos voltaram como estavam no momento
  da cópia.

## Segurança
- Só as portas 80 e 443 ficam abertas, e apenas pelo Caddy. O banco não tem porta exposta.
- Cookies de sessão `Secure`, `HttpOnly` e `SameSite=Lax`.
- O IP do cliente vem do Caddy (`TRUST_PROXY=1`), o que mantém valendo o limite de tentativas de login.
- Senhas com `password_hash`; tokens do WhatsApp cifrados com a `TALK_KEY` (libsodium).
- Nada de `.env` ou `deploy/backups` no Git (`.gitignore`).
