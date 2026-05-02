#!/usr/bin/env bash
# Easyverein Go – Nightly Sync via PHP-CLI
# Wird als System-Crontab-Job aufgerufen (kein HTTP-Timeout).
#
# Crontab-Eintrag (crontab -e):
#   0 3 * * * /usr/home/tvwmie/bin/evg-nightly-sync.sh >> /usr/home/tvwmie/logs/evg-cron.log 2>&1
#
# Einmalige Einrichtung auf dem Server:
#   chmod +x ~/bin/evg-nightly-sync.sh
#   crontab -e  →  Zeile oben einfügen

PHP=/usr/bin/php84
WP_CLI=~/bin/wp
WP_PATH=~/public_html/staging
LOG_DIR=~/public_html/staging/wp-content/easyverein-debug

echo "[$(date '+%Y-%m-%d %H:%M:%S')] EVG nightly-sync gestartet (CLI)"

$PHP $WP_CLI eval \
    'EVG_Plugin::get_instance()->run_nightly_sync_cli();' \
    --path="$WP_PATH" \
    --allow-root

EXIT=$?
echo "[$(date '+%Y-%m-%d %H:%M:%S')] EVG nightly-sync beendet (Exit: $EXIT)"
