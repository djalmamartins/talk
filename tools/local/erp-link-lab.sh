#!/usr/bin/env bash
# Liga o Talk do laboratório (dev-apache, talk.connect.lab) ao Connect ERP do laboratório (compose connect-erp).
# Uso, no Mac:  tools/local/erp-link-lab.sh EMAIL_DO_USUARIO_DO_ERP
#          ou:  tools/local/erp-link-lab.sh            (sem e-mail: usa o token que já está no .env e só confere e testa)
#   1. gera no ERP um token ctk_ (tools/talk-token.php) para esse usuário (precisa da permissão support.manage);
#   2. descobre um endereço do ERP que o dev-apache alcança;
#   3. grava ERP_API_URL, ERP_API_TOKEN e ERP_WEB_URL no .env do Talk (com cópia .env.bak-DATA antes);
#   4. roda as migrações e o bin/erp-check.php;
#   5. faz chegar uma mensagem de teste no número simulado, deixa a Jack encaminhar e mostra o chamado aberto no SAC.
# Só laboratório. O token aparece uma vez e fica só no .env (que não vai para o Git).
set -euo pipefail

project=$(cd "$(dirname "$0")/../.." && pwd)
envf="$project/.env"
in_container=/var/www/html/talk
email="${1:-}"
die() { echo "ERRO: $*" >&2; exit 1; }

[[ -f "$envf" ]] || die "Não achei $envf."
if [[ -z "$email" ]] && ! grep -qE '^ERP_API_TOKEN=ctk_[0-9a-f]{64}$' "$envf"; then
  die "Informe o e-mail de um usuário do ERP com acesso ao SAC. Ex.: tools/local/erp-link-lab.sh voce@connect.com.br"
fi
grep -q '^APP_ENV=lab' "$envf" || die "Este script é só para o laboratório (APP_ENV=lab no .env)."
[[ "$(docker inspect -f '{{.State.Running}}' dev-apache 2>/dev/null)" == "true" ]] || die "dev-apache não está em execução."
erp=$(docker ps -q --filter label=com.docker.compose.project=connect-erp --filter label=com.docker.compose.service=app | head -1)
[[ -n "$erp" ]] || die "O app do ERP (compose connect-erp) não está em execução. Suba com: docker compose up -d (na pasta do ERP)."

echo "1/5 Endereço do ERP visto pelo Talk"
port=$(docker port "$erp" 80/tcp 2>/dev/null | head -1 | sed 's/.*://')
url=""
for cand in "https://erp.connect.lab/api/v1" "http://host.docker.internal:${port:-8088}/api/v1"; do
  code=$(docker exec dev-apache curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$cand/health" || true)
  echo "   $cand/health -> $code"
  if [[ "$code" == "200" ]]; then url="$cand"; break; fi
done
[[ -n "$url" ]] || die "O dev-apache não alcança o ERP por nenhum dos endereços acima."

if [[ -z "$email" ]]; then
  echo "2/5 Token: usando o que já está no .env"
  token=$(sed -n 's/^ERP_API_TOKEN=//p' "$envf" | tail -1)
else
echo "2/5 Token do Talk no ERP (usuário $email)"
out=$(docker exec "$erp" php tools/talk-token.php criar --usuario="$email" --nome="Connect Talk (lab)" 2>&1) || die "$out"
echo "$out" | grep -v '^ERP_API_TOKEN=' | sed '/^$/d' | sed 's/^/   /'
token=$(echo "$out" | sed -n 's/^ERP_API_TOKEN=\(ctk_[0-9a-f]*\).*/\1/p')
[[ "$token" =~ ^ctk_[0-9a-f]{64}$ ]] || die "Não consegui ler o token na saída do ERP."
echo "   token ctk_…${token: -4} gerado"
fi

echo "3/5 .env do Talk"
cp -p "$envf" "$envf.bak-$(date +%Y%m%d-%H%M%S)"
setvar() { # troca a linha KEY=… ou acrescenta no fim
  local k=$1 v=$2 tmp; tmp=$(mktemp)
  if grep -q "^$k=" "$envf"; then awk -v k="$k" -v v="$v" 'index($0, k"=")==1 {print k"="v; next} {print}' "$envf" >"$tmp"; else cat "$envf" >"$tmp"; printf '%s=%s\n' "$k" "$v" >>"$tmp"; fi
  cat "$tmp" >"$envf"; rm -f "$tmp"
}
grep -q '^# Connect ERP' "$envf" || printf '\n# Connect ERP do laboratório (tools/local/erp-link-lab.sh)\n' >>"$envf"
setvar ERP_API_URL "$url"
setvar ERP_API_TOKEN "$token"
grep -q '^ERP_WEB_URL=.\+' "$envf" || setvar ERP_WEB_URL "https://erp.connect.lab"
echo "   ERP_API_URL=$url  ERP_API_TOKEN=ctk_…${token: -4}"

echo "4/5 Migrações e conferência"
docker exec dev-apache php "$in_container/bin/migrate.php"
docker exec dev-apache php "$in_container/bin/erp-check.php"

echo "5/5 Chamado de teste (cliente simulado 5531999990777)"
docker exec -i dev-apache php <<'PHP'
<?php
$app = require '/var/www/html/talk/bootstrap.php';
$db = $app['db']; $svc = $app['conversations'];
$ch = $db->one("SELECT id FROM talk_channels WHERE kind = 'lab' ORDER BY is_default DESC, id LIMIT 1");
if (!$ch) { echo "   Sem número simulado: rode php bin/seed-lab.php e tente de novo.\n"; exit(1); }
$id = $svc->handleInbound((int) $ch['id'], new ConnectTalk\Domain\InboundMessage('ERPTEST-' . bin2hex(random_bytes(6)), '5531999990777', 'text',
    'Teste da ligação com o SAC: o portão da garagem não fecha desde ontem.', 'Teste Talk ERP'));
// adianta o relógio do setor Geral para a Jack decidir agora (só esta conversa de teste)
$db->run('UPDATE talk_conversations SET opened_at = NOW() - INTERVAL 10 MINUTE, queued_at = NOW() - INTERVAL 10 MINUTE WHERE id = ?', [$id]);
$db->run('UPDATE talk_messages SET created_at = NOW() - INTERVAL 10 MINUTE WHERE conversation_id = ?', [$id]);
$svc->triage();
$c = $db->one('SELECT c.protocol, c.status, c.helpdesk_ticket_id, c.helpdesk_protocol, c.helpdesk_url, s.name sector FROM talk_conversations c LEFT JOIN talk_sectors s ON s.id = c.sector_id WHERE c.id = ?', [$id]);
echo "   Conversa #$id ({$c['protocol']}) no setor {$c['sector']}, status {$c['status']}\n";
if ($c['helpdesk_ticket_id']) {
    echo "   CHAMADO ABERTO NO SAC: {$c['helpdesk_protocol']} (id {$c['helpdesk_ticket_id']})\n";
    echo '   Link de acompanhamento: ' . ($c['helpdesk_url'] ?: '(o ERP ainda não manda tracking_url: falta o PR connect-erp#708)') . "\n";
    $out = $db->all("SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'out' ORDER BY id", [$id]);
    foreach ($out as $m) { echo "   Jack -> cliente: " . str_replace("\n", "\n                    ", $m['body']) . "\n"; }
} else {
    $err = $db->one("SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'internal' ORDER BY id DESC LIMIT 1", [$id]);
    echo "   O chamado não abriu. Última nota: " . ($err['body'] ?? '(nenhuma)') . "\n";
    exit(1);
}
PHP
echo "Pronto. Pode encerrar a conversa de teste no Talk."
