#!/bin/bash
# Скрипт для запуска React dev сервера через systemd
# Используется для автозапуска

# Загружаем nvm если установлен
if [ -s "$HOME/.nvm/nvm.sh" ]; then
    export NVM_DIR="$HOME/.nvm"
    [ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
    nvm use 20 2>/dev/null || nvm use node 2>/dev/null || nvm use default 2>/dev/null
fi

# Пробуем найти node в разных местах
if ! command -v node &> /dev/null; then
    # Проверяем стандартные пути
    for path in /usr/local/bin /usr/bin /opt/node/bin "$HOME/.nvm/versions/node"/*/bin; do
        if [ -f "$path/node" ]; then
            export PATH="$path:$PATH"
            break
        fi
    done
fi

# Если node все еще не найден, пробуем установить через nvm
if ! command -v node &> /dev/null && [ -s "$HOME/.nvm/nvm.sh" ]; then
    export NVM_DIR="$HOME/.nvm"
    [ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
    nvm install 20 2>/dev/null || nvm install node 2>/dev/null
    nvm use 20 2>/dev/null || nvm use node 2>/dev/null
fi

# Определяем путь к директории скрипта автоматически
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR" || exit 1

# Убеждаемся что мы в правильной директории
export PWD="$SCRIPT_DIR"
export NODE_ENV=production

# Проверяем и переустанавливаем зависимости если нужно
if [ ! -f "node_modules/vite/bin/vite.js" ] && [ ! -f "node_modules/vite/dist/node/cli.js" ]; then
    echo "Vite not found, reinstalling dependencies..."
    npm install
fi

# Используем прямой путь к vite через node для надежности
if [ -f "node_modules/vite/bin/vite.js" ]; then
    exec node node_modules/vite/bin/vite.js
else
    exec node node_modules/vite/dist/node/cli.js
fi
