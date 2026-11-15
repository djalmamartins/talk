#!/usr/bin/env bash
# Confere por que o WhatsApp por QR Code não recebe ou não envia no laboratório (talk.connect.lab).
# Só lê: não apaga, não recria e não muda nada. Não mostra chaves nem senhas.
# Uso: tools/local/whatsapp-check.sh
set -uo pipefail

project=$(cd "$(dirname "$0")/../.." && pwd)
envf="$project/.env"
host=talk.connect.lab
ok() { echo "  OK    $*"; }
bad() { echo "  FALHA $*"; problems=$((problems + 1)); }
problems=0
envget() { { grep -E "^$1=" "$envf" 2>/dev/null || true; } | tail -1 | cut -d= -f2-; }
running() { [[ "$(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null)" == "true" ]]; }

echo "1. Containers"
for c in dev-apache dev-mysql talk-whatsapp talk-whatsapp-db; do
  if running "$c"; then ok "$c em execução"; else bad "$c parado ou inexistente"; fi
done
running dev-apache || { echo "Sem o dev-apache não dá para conferir o resto."; exit 1; }

echo "2. Banco do Talk (migrações)"
out=$(docker exec dev-apache php /var/www/html/talk/bin/migrate.php --lab 2>&1 | tail -3)
if grep -q "migrations ok" <<<"$out"; then ok "$(grep 'migrations ok' <<<"$out")"; else bad "migrações: $out"; fi

echo "3. Configuração (.env)"
key=$(envget EVOLUTION_API_KEY)
[[ -n "$key" ]] && ok "EVOLUTION_API_KEY presente" || bad "EVOLUTION_API_KEY vazia (rode tools/local/whatsapp-lab.sh)"
[[ "$(envget EVOLUTION_URL)" == "http://talk-whatsapp:8080" ]] && ok "EVOLUTION_URL=http://talk-whatsapp:8080" || bad "EVOLUTION_URL é '$(envget EVOLUTION_URL)'"

if running talk-whatsapp; then
  echo "4. O motor do WhatsApp alcança o Talk (mensagens recebidas)"
  net=$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' dev-apache | awk '{print $1}')
  apache_ip=$(docker inspect -f "{{(index .NetworkSettings.Networks \"$net\").IPAddress}}" dev-apache)
  mapped=$(docker inspect -f '{{range .HostConfig.ExtraHosts}}{{.}} {{end}}' talk-whatsapp | tr ' ' '\n' | grep "^$host:" | cut -d: -f2)
  [[ "$mapped" == "$apache_ip" ]] && ok "$host aponta para o dev-apache ($apache_ip)" \
    || bad "$host aponta para '$mapped', mas o dev-apache está em $apache_ip (rode tools/local/whatsapp-lab.sh)"
  st=$(docker exec talk-whatsapp node -e "fetch('https://$host/login').then(r=>console.log(r.status)).catch(e=>console.log('erro: '+(e.cause?.code||e.message)))" 2>&1 | tail -1)
  [[ "$st" == "200" ]] && ok "https://$host responde ao motor (HTTP 200)" || bad "o motor não chega em https://$host ($st)"

  echo "5. Números conectados no motor"
  docker exec -e K="$key" dev-apache php -r '
    $ctx = stream_context_create(["http" => ["header" => "apikey: " . getenv("K"), "timeout" => 10, "ignore_errors" => true]]);
    $r = @file_get_contents("http://talk-whatsapp:8080/instance/fetchInstances", false, $ctx);
    if ($r === false) { echo "  FALHA o Talk não alcança o motor em http://talk-whatsapp:8080\n"; exit(1); }
    $list = json_decode($r, true);
    if (!is_array($list) || isset($list["status"])) { echo "  FALHA resposta do motor: " . substr($r, 0, 200) . "\n"; exit(1); }
    if (!$list) { echo "  FALHA nenhum número no motor (cadastre de novo pelo QR Code)\n"; exit(1); }
    $bad = 0;
    foreach ($list as $i) {
      $s = $i["connectionStatus"] ?? "?";
      $bad += $s !== "open";
      printf("  %s %s: %s%s\n", $s === "open" ? "OK   " : "FALHA", $i["name"] ?? "?", $s === "open" ? "conectado" : $s, $s === "open" ? "" : " (leia o QR Code de novo em Conexão WhatsApp)");
    }
    exit($bad ? 1 : 0);' || problems=$((problems + 1))

  echo "6. Webhook configurado em cada número (para onde o motor manda as mensagens)"
  docker exec -e K="$key" dev-apache php -r '
    $a = require "/var/www/html/talk/bootstrap.php";
    foreach ($a["db"]->all("SELECT id, name, config_encrypted FROM talk_channels WHERE kind = \"qrcode\" AND status = \"active\"") as $c) {
      $cfg = $a["crypto"]->decrypt($c["config_encrypted"]);
      $inst = $cfg["instance"] ?? "";
      $ctx = stream_context_create(["http" => ["header" => "apikey: " . getenv("K"), "timeout" => 10, "ignore_errors" => true]]);
      $w = json_decode((string) @file_get_contents("http://talk-whatsapp:8080/webhook/find/" . rawurlencode($inst), false, $ctx), true);
      $url = $w["url"] ?? null;
      $okUrl = $url && preg_match("#/webhook/" . $c["id"] . "$#", $url) && !empty($w["enabled"]);
      printf("  %s %s (%s): %s\n", $okUrl ? "OK   " : "FALHA", $c["name"], $inst, $url ? $url . (empty($w["enabled"]) ? " (desligado)" : "") : "sem webhook");
    }' 2>&1 | grep -v "^$"
fi

echo "7. Envios recentes"
docker exec dev-apache php -r '
  $a = require "/var/www/html/talk/bootstrap.php";
  $rows = $a["db"]->all("SELECT id, status, attempts, last_error, created_at FROM talk_outbox ORDER BY id DESC LIMIT 5");
  if (!$rows) { echo "  (nenhum envio ainda)\n"; }
  foreach ($rows as $r) { printf("  #%d %s, %d tentativa(s), %s%s\n", $r["id"], $r["status"], $r["attempts"], $r["created_at"], $r["last_error"] ? " — " . mb_substr($r["last_error"], 0, 160) : ""); }
  $m = $a["db"]->one("SELECT MAX(created_at) t FROM talk_messages WHERE direction = \"in\"");
  echo "  Última mensagem recebida: " . ($m["t"] ?? "nenhuma") . "\n";'

echo "8. Erros recentes do Talk no Apache"
docker exec dev-apache sh -c 'tail -300 /var/log/apache2/error.log 2>/dev/null' | grep -i "\[talk\]\|PHP Fatal\|Uncaught" | tail -8 | sed 's/^/  /' || true

echo
if [[ $problems -eq 0 ]]; then echo "Nada de errado encontrado. Mande o resultado para o Claude."; else echo "$problems problema(s) acima. Mande o resultado para o Claude."; fi
