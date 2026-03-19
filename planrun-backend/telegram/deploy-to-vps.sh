#!/bin/bash
# Развёртывание webhook-proxy на VPS (tg.planrun.ru)
# Запуск: ./deploy-to-vps.sh [user@vps-host]
# По умолчанию: root@217.177.46.235 (если доступен)

VPS="${1:-root@217.177.46.235}"
REMOTE_DIR="/var/www/telegram-webhook-proxy"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "Deploy to $VPS:$REMOTE_DIR"
ssh "$VPS" "mkdir -p $REMOTE_DIR"
scp "$SCRIPT_DIR/webhook-proxy.php" "$SCRIPT_DIR/env-vps.example" "$VPS:$REMOTE_DIR/"
echo "Done."
echo ""
echo "На VPS:"
echo "  1. cp env-vps.example .env  (или добавьте WEBHOOK_BACKEND_* в существующий .env)"
echo "  2. Backend URLs для hday/gpu-alert/tsd: alter-vision.ru/bots/.../bot.php (не /hday/webhook-internal.php)"
