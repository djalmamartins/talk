#!/usr/bin/env bash
# Restaura uma cópia feita pelo backup.sh. Rode na pasta deploy/, com o Talk parado:
#   docker compose stop app worker
#   ./restore.sh backups/talk-20261115-0200
#   docker compose start app worker
# APAGA o banco atual e os arquivos atuais e põe os da cópia no lugar (pede confirmação).
set -euo pipefail
dir=${1:?"Uso: ./restore.sh backups/talk-AAAAMMDD-HHMM"}
[[ -f "$dir/banco.sql.gz" && -f "$dir/arquivos.tar.gz" ]] || { echo "Cópia incompleta em $dir" >&2; exit 1; }
(cd "$dir" && sha256sum -c SHA256SUMS) || { echo "Arquivos da cópia corrompidos" >&2; exit 1; }
if [[ "${YES:-}" != "1" ]]; then
  read -r -p "Isto substitui o banco e os arquivos atuais pelos de $dir. Continuar? [s/N] " a
  [[ "$a" =~ ^[sS]$ ]] || { echo "Nada foi alterado."; exit 0; }
fi
set -a; . ./.env; set +a
abs=$(cd "$dir" && pwd)
docker compose run --rm --no-deps -v "$abs:/restore:ro" -e MYSQL_PWD="$DB_ROOT_PASSWORD" --entrypoint bash backup -c "
  set -euo pipefail
  mariadb -h db -uroot -e 'DROP DATABASE IF EXISTS \`$DB_DATABASE\`; CREATE DATABASE \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON \`$DB_DATABASE\`.* TO \`$DB_USERNAME\`@\"%\";'
  gunzip -c /restore/banco.sql.gz | mariadb -h db -uroot '$DB_DATABASE'
  echo 'banco restaurado'"
docker compose run --rm --no-deps -v "$abs:/restore:ro" --entrypoint sh app -c \
  "find /var/talk/storage -mindepth 1 -delete && tar -C /var/talk/storage -xzf /restore/arquivos.tar.gz && chown -R www-data:www-data /var/talk/storage && echo 'arquivos restaurados'"
echo "Pronto. Suba de novo: docker compose start app worker"
