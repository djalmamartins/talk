#!/usr/bin/env bash
# Instala o Connect Talk inteiro num Raspberry Pi 4/5 (Raspberry Pi OS Lite 64 bits) ou em qualquer Linux com Docker.
# Guia: docs/raspberry-pi.md. Rodar de novo só confere e atualiza: não apaga dados nem troca as chaves já geradas.
#
#   bash deploy/instalar-pi.sh
#
# Pergunta o domínio, o token do túnel da Cloudflare e (opcional) o endereço e o token da API do ERP.
# As chaves ficam só em deploy/.env (permissão 600), nunca no Git.
set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd)
envf="$here/.env"

say() { printf '\n== %s\n' "$*"; }
die() { echo "ERRO: $*" >&2; exit 1; }
sudo_() { if [[ $EUID -eq 0 ]]; then "$@"; else sudo "$@"; fi; }
# valor da chave no .env, sem o comentário do fim da linha
envget() { { grep -E "^$1=" "$envf" 2>/dev/null || true; } | tail -1 | cut -d= -f2- | sed -E 's/[[:space:]]+#.*$//; s/[[:space:]]+$//'; }
# grava a chave só se ainda estiver vazia (rodar de novo não troca nada)
envset() {
  [[ -n "$(envget "$1")" ]] && return 0
  local tmp; tmp=$(mktemp)
  if grep -qE "^$1=" "$envf"; then
    awk -v k="$1" -v v="$2" 'BEGIN{FS=OFS="="} $1==k && !done {print k "=" v; done=1; next} {print}' "$envf" >"$tmp"
  else
    cat "$envf" >"$tmp"; echo "$1=$2" >>"$tmp"
  fi
  cat "$tmp" >"$envf"; rm -f "$tmp"
}
ask() { # ask CHAVE "pergunta" [padrão] [secreto]
  [[ -n "$(envget "$1")" ]] && return 0
  local v
  if [[ "${4:-}" == secret ]]; then read -r -s -p "$2: " v; echo; else read -r -p "$2${3:+ [$3]}: " v; fi
  v=${v:-${3:-}}
  [[ -n "$v" ]] && envset "$1" "$v"
  return 0
}

say "1/7 Conferindo o aparelho"
arch=$(uname -m)
[[ "$arch" == "aarch64" || "$arch" == "x86_64" ]] || die "processador $arch: use o Raspberry Pi OS de 64 bits (aarch64)."
mem=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
(( mem >= 3500 )) || echo "Aviso: ${mem} MB de RAM. O Talk inteiro (banco, site, worker e WhatsApp) pede 4 GB ou mais."
root_dev=$(findmnt -n -o SOURCE / || true)
if [[ "$root_dev" == /dev/mmcblk* ]]; then
  echo "Aviso: o sistema está no cartão SD ($root_dev). Com o banco gravando o tempo todo, o cartão estraga em meses."
  echo "       O recomendado é ligar o Pi por um SSD no USB 3 (docs/raspberry-pi.md, passo 1)."
fi

say "2/7 Docker"
if ! command -v docker >/dev/null; then
  echo "Instalando o Docker (script oficial get.docker.com)…"
  curl -fsSL https://get.docker.com | sudo_ sh
  sudo_ usermod -aG docker "${SUDO_USER:-$USER}" || true
fi
sudo_ systemctl enable --now docker >/dev/null 2>&1 || true
dc() { sudo_ docker compose --project-directory "$here" -f "$here/docker-compose.yml" "$@"; }
dc version >/dev/null || die "o Docker não tem o 'docker compose'. Reinstale pelo get.docker.com."

say "3/7 Chaves e configuração (deploy/.env)"
[[ -f "$envf" ]] || cp "$here/.env.example" "$envf"
chmod 600 "$envf"
envset DB_PASSWORD "$(openssl rand -hex 24)"
envset DB_ROOT_PASSWORD "$(openssl rand -hex 24)"
envset TALK_KEY "$(openssl rand -base64 32)"
envset EVOLUTION_API_KEY "$(openssl rand -hex 24)"
ask TALK_DOMAIN "Endereço do Talk" "talk.connectcondominios.com.br"
envset APP_URL "https://$(envget TALK_DOMAIN)"
if [[ "$(envget COMPOSE_PROFILES)" == *tunnel* && -z "$(envget CLOUDFLARE_TUNNEL_TOKEN)" ]]; then
  echo "Cole o token do túnel da Cloudflare (docs/raspberry-pi.md, passo 3). Ele começa com 'eyJ'."
  read -r -s -p "Token do túnel: " tok; echo
  [[ "$tok" == eyJ* ]] || die "isso não parece o token do túnel. Rode de novo e cole só o token."
  envset CLOUDFLARE_TUNNEL_TOKEN "$tok"
fi
echo "Ligação com o ERP (Enter para deixar para depois; o Talk funciona sem, só não puxa nomes nem abre chamado):"
ask ERP_API_URL "Endereço da API do ERP, termina em /api/v1" ""
if [[ -n "$(envget ERP_API_URL)" ]]; then
  ask ERP_API_TOKEN "Token do ERP (ctk_..., gerado com php tools/talk-token.php criar)" "" secret
  [[ -z "$(envget ERP_WEB_URL)" ]] && envset ERP_WEB_URL "$(envget ERP_API_URL | sed -E 's#/api/v1/?$##')"
fi
echo "Configuração em $envf (só o seu usuário lê). Guarde uma cópia do TALK_KEY fora do Pi."

say "4/7 Baixando e montando (a primeira vez demora uns 10 minutos no Pi)"
dc pull -q --ignore-buildable 2>/dev/null || dc pull -q || true
dc up -d --build

say "5/7 Esperando o Talk responder"
ok=0
for _ in $(seq 1 60); do
  if dc exec -T app php -r 'exit(@file_get_contents("http://127.0.0.1/health") === false ? 1 : 0);' >/dev/null 2>&1; then ok=1; break; fi
  sleep 3
done
(( ok )) || { dc logs --tail 40 app; die "o Talk não respondeu em 3 minutos (logs acima)."; }
echo "Talk respondendo."

say "6/7 Primeiro supervisor e setores"
if dc exec -T app php -r '$a = require "bootstrap.php"; exit($a["db"]->one("SELECT 1 AS ok FROM talk_staff WHERE role = \"supervisor\" LIMIT 1") ? 0 : 1);' >/dev/null 2>&1; then
  echo "Já existe supervisor; mantido."
else
  read -r -p "E-mail do primeiro supervisor (o mesmo do Connect ERP): " email
  read -r -p "Nome: " name
  dc exec -T app php bin/setup.php --email="$email" --name="$name"
fi

say "7/7 Conferindo a ligação com o ERP"
dc exec -T app php bin/erp-check.php || echo "Corrija o ERP_API_URL/ERP_API_TOKEN em $envf e rode: cd $here && sudo docker compose up -d"

echo
echo "Pronto. Abra https://$(envget TALK_DOMAIN), entre com o supervisor e vá em Conexões > Novo número > QR Code."
echo "O Pi liga tudo sozinho depois de faltar luz. Ver o estado: cd $here && sudo docker compose ps"
