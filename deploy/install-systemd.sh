#!/bin/bash
# Установить systemd‑юнит для React dev‑сервера.
# Пути берутся из расположения проекта. Запуск: sudo ./deploy/install-systemd.sh

set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
SRC="$PROJECT_ROOT/planrun-react.service"
DEST="/etc/systemd/system/planrun-react.service"

sed "s|{{PROJECT_ROOT}}|$PROJECT_ROOT|g" "$SRC" > "$DEST"
systemctl daemon-reload
echo "OK: planrun-react.service installed (PROJECT_ROOT=$PROJECT_ROOT)."
echo "    systemctl enable planrun-react && systemctl start planrun-react"
