#!/bin/bash
# Установка автозагрузки моделей LM Studio и исправление конфига PlanRun AI
# Запуск: sudo ./install-lm-studio-models.sh

set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "Installing LM Studio model load drop-in..."
mkdir -p /etc/systemd/system/lm-studio.service.d
cp "$SCRIPT_DIR/lm-studio.service.d/load-models.conf" /etc/systemd/system/lm-studio.service.d/

echo "Installing PlanRun AI embed model fix..."
mkdir -p /etc/systemd/system/planrun-ai.service.d
cp "$SCRIPT_DIR/planrun-ai.service.d/embed-model-fix.conf" /etc/systemd/system/planrun-ai.service.d/

echo "Reloading systemd..."
systemctl daemon-reload

echo "Restarting lm-studio.service..."
systemctl restart lm-studio.service || true

echo "Waiting for LM Studio API..."
sleep 15

echo "Loading models..."
"$SCRIPT_DIR/load-planrun-models.sh"

echo "Restarting planrun-ai.service..."
systemctl restart planrun-ai.service

echo "Done. Check: curl -s http://127.0.0.1:1234/v1/models"
