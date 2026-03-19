#!/bin/bash
# Установить systemd-юнит для worker очереди AI-планов.
# Запуск: sudo ./deploy/install-plan-generation-worker.sh

set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
SRC="$PROJECT_ROOT/planrun-plan-generation-worker.service"
DEST="/etc/systemd/system/planrun-plan-generation-worker.service"

sed "s|{{PROJECT_ROOT}}|$PROJECT_ROOT|g" "$SRC" > "$DEST"
systemctl daemon-reload
echo "OK: planrun-plan-generation-worker.service installed (PROJECT_ROOT=$PROJECT_ROOT)."
echo "    systemctl enable planrun-plan-generation-worker && systemctl start planrun-plan-generation-worker"
