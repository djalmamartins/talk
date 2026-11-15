#!/bin/sh
# Ao subir o site, aplica as migrations (idempotentes) antes de abrir o Apache.
set -e
if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
  i=0
  until php bin/migrate.php; do
    i=$((i + 1))
    [ "$i" -ge 10 ] && { echo "[talk] migrations falharam 10 vezes; verifique o banco" >&2; exit 1; }
    echo "[talk] banco ainda não respondeu; nova tentativa em 5 s" >&2
    sleep 5
  done
fi
exec "$@"
