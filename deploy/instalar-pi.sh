#!/usr/bin/env bash
# Instala o Connect Talk inteiro num Raspberry Pi 4/5 (Raspberry Pi OS 64 bits) ou em qualquer Linux com Docker:
# site, worker, banco, backup e o motor do WhatsApp, com o Cloudflare Tunnel no lugar do Caddy.
# Rodar de novo só confere e atualiza: não apaga nada e não troca senha nem chave que já exista.
#
#   cd ~/talk/deploy && bash instalar-pi.sh
#
# Guia completo: docs/raspberry-pi.md
set -euo pipefail

deploy=$(cd "$(dirname "$0")" && pwd)
envf="$deploy/.env"

say() { printf '\n== %s\n' "$*"; }
die() { echo "ERRO: $*" >&2; exit 1; }
sudo_() { if [[ $EUID -eq 0 ]]; then "$@"; else sudo "$@"; fi; }
# valor atual da chave no .env, sem o comentário do fim da linha
envget() { { grep -E "^$1=" "$envf" 2>/dev/null || true; } | tail -1 | cut -d= -f2- | sed -E 's/[[:space:]]+#.*$//; s/[[:space:]]+$//'; }
# grava só se ainda estiver vazio (troca a linha existente ou acrescenta no fim)
envfill() {
  [[ -n "$(envget "$1")" ]] && return 0
  local tmp; tmp=$(mktemp)
  KEY="$1" VAL="$2" awk 'BEGIN{k=ENVIRON["KEY"]; v=ENVIRON["VAL"]; done=0}
    $0 ~ "^"k"=" && !done {print k"="v; done=1; next} {print} END{if(!done) print k"="v}' "$envf" >"$tmp"
  cat "$tmp" >"$envf"; rm -f "$tmp"
}

say "1/7 Conferindo o aparelho"
arch=$(uname -m)
[[ "$arch" == "aarch64" || "$arch" == "x86_64" ]] || die "processador $arch: use o Raspberry Pi OS de 64 bits (aarch64)."
mem=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
(( mem >= 3500 )) || echo "Aviso: ${mem} MB de RAM. Para o Talk inteiro o recomendado é 4 GB."
if [[ "$(findmnt -n -o SOURCE /)" == /dev/mmcblk* ]]; then
  echo "Aviso: o sistema está no cartão SD. Com dois bancos gravando o tempo todo o cartão gasta rápido."
  echo "       Funciona para começar; o recomendado é um SSD no USB 3 (docs/raspberry-pi.md, passo 1)."
fi

say "2/7 Docker"
if ! command -v docker >/dev/null; then
  echo "Instalando o Docker (script oficial get.docker.com)…"
  curl -fsSL https://get.docker.com | sudo_ sh
  sudo_ usermod -aG docker "${SUDO_USER:-$USER}" || true
fi
sudo_ systemctl enable --now docker >/dev/null 2>&1 || true
dc() { (cd "$deploy" && sudo_ docker compose "$@"); }
dc version >/dev/null || die "o Docker não tem o 'docker compose'. Reinstale pelo get.docker.com."

say "3/7 Configuração (.env)"
if [[ ! -f "$envf" ]]; then
  cp "$deploy/.env.example" "$envf"
  echo "Criei o .env a partir do .env.example."
fi
chmod 600 "$envf"
envfill COMPOSE_FILE "docker-compose.yml:docker-compose.pi.yml"
envfill DB_PASSWORD "$(openssl rand -hex 24)"
envfill DB_ROOT_PASSWORD "$(openssl rand -hex 24)"
envfill TALK_KEY "$(openssl rand -base64 32)"
envfill EVOLUTION_API_KEY "$(openssl rand -hex 24)"
domain=$(envget TALK_DOMAIN)
if [[ -z "$domain" || "$domain" == "talk.connect.com.br" ]]; then
  read -r -p "Endereço do Talk [talk.connectcondominios.com.br]: " domain
  domain=${domain:-talk.connectcondominios.com.br}
  sed -i "s#^TALK_DOMAIN=.*#TALK_DOMAIN=$domain#; s#^APP_URL=.*#APP_URL=https://$domain#" "$envf"
fi
if [[ -z "$(envget ERP_WEB_URL)" ]]; then
  base=${domain#talk.}
  envfill ERP_WEB_URL "https://erp.$base"
fi
if [[ -z "$(envget CLOUDFLARE_TUNNEL_TOKEN)" ]]; then
  echo "Cole o token do túnel da Cloudflare (docs/raspberry-pi.md, passo 3). Ele começa com 'eyJ'."
  read -r -s -p "Token: " tok; echo
  [[ "$tok" == eyJ* ]] || die "isso não parece o token do túnel. Rode de novo e cole só o token."
  envfill CLOUDFLARE_TUNNEL_TOKEN "$tok"
fi
echo "Senhas e chaves geradas e guardadas em $envf (só o seu usuário lê)."
echo "GUARDE UMA CÓPIA DO .env FORA DO PI (gerenciador de senhas): sem a TALK_KEY o backup não reconecta os números."

say "4/7 Montando e ligando (a primeira vez demora uns 10 a 15 minutos no Pi)"
dc pull -q --ignore-buildable 2>/dev/null || dc pull -q || true
dc up -d --build

say "5/7 Esperando o Talk responder"
ok=0
for _ in $(seq 1 80); do
  if dc exec -T app php -r 'exit(@file_get_contents("http://127.0.0.1/health") === false ? 1 : 0);' >/dev/null 2>&1; then ok=1; break; fi
  sleep 3
done
(( ok )) || { dc logs --tail 40 app; die "o Talk não respondeu em 4 minutos (logs acima)."; }
echo "Talk respondendo no próprio Pi."
if curl -fsS -m 15 "https://$domain/health" >/dev/null 2>&1; then
  echo "Talk respondendo pela internet em https://$domain"
else
  echo "Aviso: https://$domain ainda não respondeu. Confira no painel da Cloudflare se o túnel está 'Healthy'"
  echo "       e se o Public Hostname aponta para http://app:80 (docs/raspberry-pi.md, passo 3)."
fi

say "6/7 Primeiro supervisor"
read -r -p "Criar agora o primeiro supervisor (só na primeira instalação)? [s/N] " a
if [[ "$a" =~ ^[sS]$ ]]; then
  read -r -p "E-mail: " email
  read -r -p "Nome: " name
  dc run --rm app php bin/setup.php --email="$email" --name="$name"
fi

say "7/7 Pronto"
echo "Abra https://$domain. Conexão WhatsApp > Conectar novo número > QR Code."
echo "Para ligar ao ERP (chamados e moradores): docs/raspberry-pi.md, passo 6."
echo "Ver se está tudo de pé: cd $deploy && sudo docker compose ps"
