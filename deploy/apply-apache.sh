#!/bin/bash
# Применить Apache-конфиг для s-vladimirov.ru.
# Все пути — внутри проекта. Запуск: sudo ./deploy/apply-apache.sh

set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
TEMPLATE="$SCRIPT_DIR/vladimirov-le-ssl.conf.template"
DEST="/etc/apache2/sites-available/vladimirov-le-ssl.conf"

sed "s|{{PROJECT_ROOT}}|$PROJECT_ROOT|g" "$TEMPLATE" > "$DEST"
apache2ctl configtest
systemctl reload apache2
echo "OK: Apache config applied (PROJECT_ROOT=$PROJECT_ROOT) and reloaded."
