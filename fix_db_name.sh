#!/bin/bash
# Замена running_calendar на sv. Запускать из корня проекта.

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT" || exit 1

echo "🔍 Поиск упоминаний running_calendar..."
grep -r "running_calendar" . --exclude-dir=node_modules --exclude-dir=.git --exclude-dir=dist 2>/dev/null || true

echo ""
echo "📝 Исправление файлов..."

if [ -f "planrun-backend/db_config.php" ]; then
    echo "Исправляю planrun-backend/db_config.php..."
    sed -i 's/running_calendar/sv/g' planrun-backend/db_config.php
    sed -i 's/Новая БД для календаря тренировок/База данных sv/g' planrun-backend/db_config.php
    sed -i 's/MySQL базе данных running_calendar/MySQL базе данных sv/g' planrun-backend/db_config.php
    echo "✅ planrun-backend/db_config.php исправлен"
fi

if [ -f "docs/migration/MIGRATION_PROGRESS.md" ]; then
    echo "Исправляю docs/migration/MIGRATION_PROGRESS.md..."
    sed -i 's/running_calendar/sv/g' docs/migration/MIGRATION_PROGRESS.md
    echo "✅ docs/migration/MIGRATION_PROGRESS.md исправлен"
fi

echo ""
echo "🔍 Проверка результатов..."
grep -r "running_calendar" . --exclude-dir=node_modules --exclude-dir=.git --exclude-dir=dist 2>/dev/null || echo "✅ Все упоминания running_calendar заменены на sv"

echo ""
echo "✅ Готово. Проверка: cat planrun-backend/db_config.php | grep DB_NAME"
