#!/usr/bin/env bash
# Cópia de segurança do Connect Talk: banco (mariadb-dump) + arquivos das conversas.
# Roda no serviço "backup" do docker-compose:
#   bash backup.sh          faz uma cópia agora
#   bash backup.sh --loop   uma por dia, na hora BACKUP_HOUR, apagando as mais velhas que BACKUP_KEEP_DAYS
# Gera /backups/talk-AAAAMMDD-HHMM/{banco.sql.gz, arquivos.tar.gz, SHA256SUMS}
set -euo pipefail

once() {
  local dir tmp
  dir="/backups/talk-$(date +%Y%m%d-%H%M)"
  tmp="$dir.parcial"
  mkdir -p "$tmp"
  MYSQL_PWD="$DB_ROOT_PASSWORD" mariadb-dump -h "$DB_HOST" -uroot --single-transaction --routines --triggers \
    --default-character-set=utf8mb4 "$DB_DATABASE" | gzip -6 >"$tmp/banco.sql.gz"
  tar -C /storage -czf "$tmp/arquivos.tar.gz" .
  (cd "$tmp" && sha256sum banco.sql.gz arquivos.tar.gz >SHA256SUMS)
  # só vira cópia válida quando terminou inteira
  mv "$tmp" "$dir"
  echo "[backup] $(date '+%d/%m/%Y %H:%M') ok: $dir ($(du -sh "$dir" | cut -f1))"
  find /backups -maxdepth 1 -type d -name 'talk-*' -mtime +"${BACKUP_KEEP_DAYS:-14}" -print -exec rm -rf {} + | sed 's/^/[backup] apagada: /'
  find /backups -maxdepth 1 -type d -name 'talk-*.parcial' -mmin +180 -exec rm -rf {} +
}

if [[ "${1:-}" != "--loop" ]]; then
  once
  exit 0
fi

echo "[backup] agendado: todo dia às ${BACKUP_HOUR:-2}h, guardando ${BACKUP_KEEP_DAYS:-14} dias"
last=""
while true; do
  today=$(date +%Y%m%d)
  if [[ "$(date +%-H)" == "${BACKUP_HOUR:-2}" && "$last" != "$today" ]]; then
    once || echo "[backup] ERRO: a cópia de $(date '+%d/%m/%Y') falhou" >&2
    last=$today
  fi
  sleep 60
done
