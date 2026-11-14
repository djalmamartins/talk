#!/usr/bin/env bash
# Verificações somente leitura do laboratório talk.connect.lab
set -uo pipefail
host=talk.connect.lab
fail=0
check() { if [[ "$2" == "$3" ]]; then echo "OK    $1"; else echo "FALHA $1 (esperado: $2; obtido: $3)"; fail=$((fail+1)); fi; }
ca="$(mkcert -CAROOT 2>/dev/null)/rootCA.pem"

check "configtest" "Syntax OK" "$(docker exec dev-apache apache2ctl configtest 2>&1 | grep -o 'Syntax OK')"
check "$host resolve" "127.0.0.1" "$(dscacheutil -q host -a name "$host" | awk '/^ip_address/{print $2; exit}')"
check "HTTPS /login" "200" "$(curl -s --cacert "$ca" -o /dev/null -w '%{http_code}' "https://$host/login")"
check "/ sem login redireciona" "302" "$(curl -s --cacert "$ca" -o /dev/null -w '%{http_code}' "https://$host/")"
check "API sem login bloqueada" "401" "$(curl -s --cacert "$ca" -o /dev/null -w '%{http_code}' "https://$host/api/me")"
check ".env fora do site" "404" "$(curl -s --cacert "$ca" -o /dev/null -w '%{http_code}' "https://$host/.env")"
check "banco e migrations" "migrations ok" "$(docker exec dev-apache php /var/www/html/talk/bin/migrate.php --lab 2>&1 | tail -1)"
[[ $fail -eq 0 ]] && echo "Tudo certo." || { echo "$fail verificação(ões) falharam."; exit 1; }
