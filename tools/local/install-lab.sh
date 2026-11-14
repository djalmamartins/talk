#!/usr/bin/env bash
# Instala o Connect Talk no XAMPP compartilhado (dev-apache + dev-mysql) em https://talk.connect.lab
# Mesmo padrão do Organic Code: nunca apaga, sobrescreve ou recria nada; pede confirmação a cada passo.
# Uso: a pasta do projeto deve estar em <xampp>/www/talk  →  tools/local/install-lab.sh
set -euo pipefail

project=$(cd "$(dirname "$0")/../.." && pwd)
xampp=$(cd "$project/../.." && pwd)
host=talk.connect.lab
crt="$xampp/certs/talk-connect.pem"
key="$xampp/certs/talk-connect-key.pem"
conf_src="$project/tools/local/talk.conf"
conf="$xampp/apache/talk.conf"
envf="$project/.env"
backup="$xampp/backups/talk-$(date +%Y%m%d-%H%M%S)"
in_container=/var/www/html/talk

# cliente SQL do container (MySQL tem "mysql"; MariaDB 11 só "mariadb")
sqlroot() { docker exec -i dev-mysql sh -c 'c=$(command -v mysql || command -v mariadb); MYSQL_PWD="${MYSQL_ROOT_PASSWORD:-$MARIADB_ROOT_PASSWORD}" "$c" -uroot "$@"' sh "$@"; }

die() { echo "ERRO: $*" >&2; exit 1; }
confirm() {
  [[ "${YES:-}" == "1" ]] && return 0
  local a; read -r -p "$1 [s/N] " a
  [[ "$a" =~ ^[sS]$ ]] || { echo "Interrompido; nada mais foi alterado."; exit 0; }
}

[[ "$(basename "$project")" == "talk" ]] || die "A pasta do projeto precisa se chamar 'talk' e ficar em <xampp>/www/ (hoje: $project)."
for c in dev-apache dev-mysql; do
  [[ "$(docker inspect -f '{{.State.Running}}' "$c" 2>/dev/null)" == "true" ]] || die "$c não está em execução."
done
[[ -d "$xampp/apache" && -d "$xampp/certs" && -d "$xampp/backups" ]] || die "XAMPP não encontrado em $xampp."
docker exec dev-apache test -f "$in_container/public/index.php" || die "O dev-apache não enxerga $in_container (a pasta www está montada em /var/www/html?)."
php_ok=$(docker exec dev-apache php -r 'echo (PHP_VERSION_ID >= 80200 && extension_loaded("pdo_mysql") && extension_loaded("sodium")) ? "ok" : PHP_VERSION;')
[[ "$php_ok" == "ok" ]] || die "dev-apache precisa de PHP 8.2+ com pdo_mysql e sodium (encontrado: $php_ok)."

echo "1/6 Backup em $backup"
confirm "Criar o backup?"
mkdir -p "$backup"
cp -Rp "$xampp/apache" "$backup/"
docker exec dev-apache apache2ctl -S >"$backup/vhosts.txt" 2>&1 || true

echo "2/6 Certificado $crt"
if [[ -f "$crt" && -f "$key" ]]; then
  echo "Certificado existente mantido."
else
  [[ ! -e "$crt" && ! -e "$key" ]] || die "Só um dos arquivos do certificado existe; corrija manualmente."
  confirm "Gerar certificado para $host com mkcert?"
  mkcert -cert-file "$crt" -key-file "$key" "$host"
  chmod 600 "$key"
fi
docker exec dev-apache test -f /etc/apache2/certs/talk-connect.pem || die "O dev-apache não vê certs/talk-connect.pem em /etc/apache2/certs (confira o volume)."

echo "3/6 VirtualHost apache/talk.conf"
if [[ -e "$conf" ]]; then
  cmp -s "$conf_src" "$conf" || die "apache/talk.conf já existe e é diferente; não vou sobrescrever."
else
  confirm "Adicionar apache/talk.conf?"
  [[ -e "$conf" ]] || cp "$conf_src" "$conf"
fi

echo "4/6 Ativar no dev-apache (graceful, sem recriar o container)"
if docker exec dev-apache test -e /etc/apache2/sites-enabled/talk.conf; then
  docker exec -i dev-apache cmp -s /etc/apache2/sites-available/talk.conf - <"$conf" || die "O site talk já está ativo com outra configuração."
  echo "Site já ativo."
else
  confirm "Ativar o site e recarregar o Apache?"
  docker cp "$conf" dev-apache:/etc/apache2/sites-available/talk.conf
  docker exec dev-apache a2ensite talk >/dev/null
  if ! docker exec dev-apache apache2ctl configtest; then
    docker exec dev-apache a2dissite talk >/dev/null
    die "configtest falhou; site desativado e Apache não recarregado."
  fi
  docker exec dev-apache apache2ctl graceful
fi

echo "5/6 Banco talk_dev, usuário talk e .env"
if [[ ! -f "$envf" ]]; then
  confirm "Criar banco/usuário e o .env do projeto?"
  exists=$(sqlroot -N -e "SELECT COUNT(*) FROM mysql.user WHERE user='talk'" </dev/null)
  [[ "$exists" == "0" ]] || die "O usuário talk já existe no banco, mas não há .env; não vou trocar a senha."
  pass=$(openssl rand -hex 24)
  sqlroot <<SQL
CREATE DATABASE IF NOT EXISTS talk_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'talk'@'%' IDENTIFIED BY '$pass';
GRANT ALL PRIVILEGES ON talk_dev.* TO 'talk'@'%';
SQL
  (
    umask 077
    cat >"$envf" <<ENV
APP_ENV=lab
APP_URL=https://$host
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=talk_dev
DB_USERNAME=talk
DB_PASSWORD=$pass
TALK_KEY=$(openssl rand -base64 32)
TZ=America/Sao_Paulo
ENV
  )
  chmod 644 "$envf"   # o Apache (www-data) do container precisa ler; o .env fica fora do DocumentRoot
else
  echo ".env existente mantido."
fi

echo "6/6 Migrations e dados de laboratório"
docker exec dev-apache php "$in_container/bin/migrate.php" --lab
docker exec dev-apache php "$in_container/bin/seed-lab.php"

if ! dscacheutil -q host -a name "$host" 2>/dev/null | grep -q 127.0.0.1; then
  echo
  echo "Falta só o DNS local. Rode (pede a senha do Mac):"
  echo "  echo '127.0.0.1 $host' | sudo tee -a /etc/hosts"
fi
echo
echo "Pronto: https://$host  (login supervisor@connect.lab / connect123)"
echo "Teste: tools/local/test-lab.sh"
