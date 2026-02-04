#!/bin/bash
# Запуск React dev-сервера. Запускать из корня проекта.

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT" || exit 1

export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
nvm use 20 2>/dev/null || true

pkill -f 'vite' 2>/dev/null || true
exec npm run dev
