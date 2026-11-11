#!/bin/bash
# First boot of the local dev shop: copy OpenCart into the web root, install
# it with cli_install.php, then hand over to Apache. A restart skips the
# install. The repo is bind-mounted at extension/two and is left alone.
set -euo pipefail

site=/var/www/html

log() { echo "[two-opencart] $*"; }

installed() { grep -q "define('DB_DATABASE'" "$site/config.php" 2>/dev/null; }

if ! installed; then
  log "installing OpenCart into ${site}"
  cp -a /usr/src/opencart/. "$site/"
  # cli_install.php needs both config files to exist and be writable.
  : > "$site/config.php"
  : > "$site/admin/config.php"
  for _ in $(seq 1 60); do
    php -r 'mysqli_report(MYSQLI_REPORT_OFF); exit(@mysqli_connect(getenv("DB_HOSTNAME"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), getenv("DB_DATABASE")) ? 0 : 1);' && break
    sleep 2
  done
  # cli_install reports errors on stdout and exits 0, so success is judged by
  # the config file it writes.
  php "$site/install/cli_install.php" install \
    --username "${ADMIN_USERNAME}" \
    --email "${ADMIN_EMAIL}" \
    --password "${ADMIN_PASSWORD}" \
    --http_server "${OPENCART_HTTP_SERVER}" \
    --language en-gb \
    --db_driver mysqli \
    --db_hostname "${DB_HOSTNAME}" \
    --db_username "${DB_USERNAME}" \
    --db_password "${DB_PASSWORD}" \
    --db_database "${DB_DATABASE}" \
    --db_port 3306 \
    --db_prefix oc_
  installed || { log "cli_install did not complete, see the output above"; exit 1; }
  log "installed"
fi

rm -rf "$site/install"
# Everything except the bind-mounted extension, whose ownership is the host's.
find "$site" -path "$site/extension/two" -prune -o -exec chown www-data:www-data {} +

exec apache2-foreground
