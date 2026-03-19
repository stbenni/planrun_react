#!/bin/bash
# Применить Nginx-конфиг для planrun.ru (SPA + /api PHP-FPM).
# Legacy-домен старого проекта этот скрипт не трогает.
# Запуск из корня проекта: sudo ./deploy/apply-nginx.sh

set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
BOT_ROOT="${BOT_ROOT:-${PROJECT_ROOT}-bot}"
TEMPLATE="$SCRIPT_DIR/planrun.nginx.conf.template"
DEST="/etc/nginx/sites-available/planrun"

# Сокет/адрес PHP-FPM: автоопределение или по умолчанию
# На этом сервере FPM слушает 127.0.0.1:9999 (TCP), а не unix socket
PHP_FPM_SOCK="${PHP_FPM_SOCK:-}"
if [ -z "$PHP_FPM_SOCK" ]; then
  # 1) Проверяем конфиг пулов PHP-FPM (listen = 127.0.0.1:9999 или listen = /run/php/...)
  for dir in /etc/php/8.3/fpm/pool.d /etc/php/8.2/fpm/pool.d; do
    [ ! -d "$dir" ] && continue
    listen_line=$(grep -h '^\s*listen\s*=' "$dir"/*.conf 2>/dev/null | head -1)
    if [ -n "$listen_line" ]; then
      listen_val=$(echo "$listen_line" | sed -n 's/^[^=]*=\s*\(.*\)/\1/p' | tr -d ';' | tr -d ' ')
      if [ -n "$listen_val" ]; then
        [[ "$listen_val" = /* ]] && PHP_FPM_SOCK="unix:$listen_val" || PHP_FPM_SOCK="$listen_val"
        break
      fi
    fi
  done
  # 2) Иначе ищем unix socket
  if [ -z "$PHP_FPM_SOCK" ]; then
    for sock in /run/php/php8.3-fpm.sock /run/php/php8.2-fpm.sock /run/php/php-fpm.sock; do
      if [ -S "$sock" ]; then
        PHP_FPM_SOCK="unix:$sock"
        break
      fi
    done
  fi
  [ -z "$PHP_FPM_SOCK" ] && PHP_FPM_SOCK="127.0.0.1:9999"
fi

sed -e "s|{{PROJECT_ROOT}}|$PROJECT_ROOT|g" \
    -e "s|{{BOT_ROOT}}|$BOT_ROOT|g" \
    -e "s|{{PHP_FPM_SOCK}}|$PHP_FPM_SOCK|g" \
    "$TEMPLATE" > "$DEST"

# Включить сайт, если ещё не включён
ENABLED="/etc/nginx/sites-enabled/planrun"
[ -L "$ENABLED" ] || ln -sf "$DEST" "$ENABLED"

nginx -t
systemctl reload nginx
echo "OK: Nginx config applied (PROJECT_ROOT=$PROJECT_ROOT, BOT_ROOT=$BOT_ROOT, PHP_FPM_SOCK=$PHP_FPM_SOCK), reloaded."
