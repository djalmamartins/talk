#!/usr/bin/env bash
# Liga o WhatsApp por QR Code de verdade no laboratório (talk.connect.lab).
# Sobe dois containers ao lado do dev-apache, na mesma rede:
#   talk-whatsapp-db  banco das sessões (Postgres)
#   talk-whatsapp     motor do WhatsApp (Evolution API, que usa o Baileys, como o Atendechat)
# e completa o .env do Talk com o endereço e a chave. Não apaga nem sobrescreve nada que já exista.
# Uso: tools/local/whatsapp-lab.sh   (YES=1 pula as confirmações)
set -euo pipefail

project=$(cd "$(dirname "$0")/../.." && pwd)
envf="$project/.env"
image=evoapicloud/evolution-api:v2.3.6
host=talk.connect.lab

die() { echo "ERRO: $*" >&2; exit 1; }
confirm() {
  [[ "${YES:-}" == "1" ]] && return 0
  local a; read -r -p "$1 [s/N] " a
  [[ "$a" =~ ^[sS]$ ]] || { echo "Interrompido; nada mais foi alterado."; exit 0; }
}
running() { [[ "$(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null)" == "true" ]]; }
exists() { docker inspect "$1" >/dev/null 2>&1; }
envget() { { grep -E "^$1=" "$envf" 2>/dev/null || true; } | tail -1 | cut -d= -f2-; }

[[ -f "$envf" ]] || die "Não achei $envf. Rode antes tools/local/install-lab.sh."
running dev-apache || die "dev-apache não está em execução."
net=$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' dev-apache | awk '{print $1}')
apache_ip=$(docker inspect -f "{{(index .NetworkSettings.Networks \"$net\").IPAddress}}" dev-apache)
[[ -n "$net" && -n "$apache_ip" ]] || die "Não descobri a rede do dev-apache."

echo "1/4 Chave entre o Talk e o motor do WhatsApp (.env)"
key=$(envget EVOLUTION_API_KEY)
if [[ -z "$key" ]]; then
  confirm "Gerar a chave e acrescentar EVOLUTION_URL e EVOLUTION_API_KEY ao .env?"
  key=$(openssl rand -hex 24)
  {
    echo
    echo "# WhatsApp por QR Code (tools/local/whatsapp-lab.sh)"
    echo "EVOLUTION_URL=http://talk-whatsapp:8080"
    echo "EVOLUTION_API_KEY=$key"
  } >>"$envf"
else
  echo "Chave existente mantida."
  [[ "$(envget EVOLUTION_URL)" == "http://talk-whatsapp:8080" ]] || echo "Aviso: EVOLUTION_URL no .env é '$(envget EVOLUTION_URL)'."
fi

echo "2/4 Banco das sessões (talk-whatsapp-db)"
if exists talk-whatsapp-db; then
  running talk-whatsapp-db || docker start talk-whatsapp-db >/dev/null
  echo "Container existente mantido."
else
  confirm "Criar o container talk-whatsapp-db (Postgres, volume talk-whatsapp-db)?"
  docker run -d --name talk-whatsapp-db --restart unless-stopped --network "$net" \
    -e POSTGRES_USER=evolution -e POSTGRES_PASSWORD="$key" -e POSTGRES_DB=evolution \
    -v talk-whatsapp-db:/var/lib/postgresql/data postgres:16-alpine >/dev/null
fi
for _ in $(seq 1 30); do docker exec talk-whatsapp-db pg_isready -U evolution >/dev/null 2>&1 && break; sleep 1; done

echo "3/4 Motor do WhatsApp (talk-whatsapp)"
# o motor avisa o Talk por https://talk.connect.lab; o certificado do laboratório é do mkcert
ca_args=(-e NODE_TLS_REJECT_UNAUTHORIZED=0)
if command -v mkcert >/dev/null && [[ -f "$(mkcert -CAROOT)/rootCA.pem" ]]; then
  ca_args=(-v "$(mkcert -CAROOT)/rootCA.pem:/certs/lab-root.pem:ro" -e NODE_EXTRA_CA_CERTS=/certs/lab-root.pem)
fi
recreate=0
if exists talk-whatsapp; then
  running talk-whatsapp || docker start talk-whatsapp >/dev/null
  old_ip=$(docker inspect -f '{{range .HostConfig.ExtraHosts}}{{.}} {{end}}' talk-whatsapp | tr ' ' '\n' | grep "^$host:" | cut -d: -f2 || true)
  if [[ "$old_ip" != "$apache_ip" ]]; then
    echo "O dev-apache mudou de endereço ($old_ip → $apache_ip); as mensagens recebidas não chegariam ao Talk."
    confirm "Recriar só o container talk-whatsapp? (as sessões ficam no banco e continuam conectadas)"
    docker rm -f talk-whatsapp >/dev/null
    recreate=1
  else
    echo "Container existente mantido."
  fi
fi
if ! exists talk-whatsapp; then
  exists talk-whatsapp-db || die "talk-whatsapp-db sumiu."
  [[ "$recreate" == "1" ]] || confirm "Criar o container talk-whatsapp ($image)?"
  docker run -d --name talk-whatsapp --restart unless-stopped --network "$net" \
    --add-host "$host:$apache_ip" "${ca_args[@]}" \
    -e SERVER_URL=http://talk-whatsapp:8080 -e AUTHENTICATION_API_KEY="$key" \
    -e DATABASE_PROVIDER=postgresql \
    -e DATABASE_CONNECTION_URI="postgresql://evolution:$key@talk-whatsapp-db:5432/evolution?schema=public" \
    -e DATABASE_SAVE_DATA_NEW_MESSAGE=false -e DATABASE_SAVE_MESSAGE_UPDATE=false \
    -e DATABASE_SAVE_DATA_CONTACTS=false -e DATABASE_SAVE_DATA_CHATS=false \
    -e CACHE_REDIS_ENABLED=false -e CACHE_LOCAL_ENABLED=true -e DEL_INSTANCE=false \
    -e CONFIG_SESSION_PHONE_CLIENT="Connect Talk" -e CONFIG_SESSION_PHONE_NAME=Chrome \
    -e LOG_LEVEL=ERROR,WARN -e TZ=America/Sao_Paulo \
    -v talk-whatsapp-instances:/evolution/instances "$image" >/dev/null
fi

echo "4/4 Conferindo (a primeira subida leva até 1 minuto)"
for _ in $(seq 1 60); do
  if docker exec dev-apache php -r 'exit(@file_get_contents("http://talk-whatsapp:8080/") === false ? 1 : 0);' 2>/dev/null; then
    echo
    echo "Pronto. Em https://$host → Conexão WhatsApp → Conectar novo número → QR Code:"
    echo "informe o nome e o número, salve e leia o QR Code com o celular desse número."
    exit 0
  fi
  sleep 2
done
die "o motor do WhatsApp não respondeu. Veja: docker logs talk-whatsapp --tail 50"
