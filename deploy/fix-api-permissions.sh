#!/bin/bash
# Исправление прав для API (сессии, логи).
# Запуск: sudo ./deploy/fix-api-permissions.sh

set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(dirname "$SCRIPT_DIR")"
WWW_USER="${WWW_USER:-www-data}"

echo "Fix permissions for PlanRun API (user: $WWW_USER)"
echo "Project root: $ROOT"

# api/sessions — PHP-FPM должен писать сессии
mkdir -p "$ROOT/api/sessions"
chown "$WWW_USER:$WWW_USER" "$ROOT/api/sessions"
chmod 0770 "$ROOT/api/sessions"
echo "  api/sessions: ok"

# planrun-backend/logs — Logger пишет логи
mkdir -p "$ROOT/planrun-backend/logs"
chown -R "$WWW_USER:$WWW_USER" "$ROOT/planrun-backend/logs"
chmod 0775 "$ROOT/planrun-backend/logs"
echo "  planrun-backend/logs: ok"

echo "Done."
